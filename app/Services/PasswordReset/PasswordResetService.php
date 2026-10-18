<?php

namespace App\Services\PasswordReset;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Exceptions\ProblemException;
use App\Models\Mailcow\Mailbox;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SecondFactorOutcome;
use App\Services\Auth\SecondFactorService;
use App\Services\Auth\SessionRegistry;
use App\Services\Config\MailcowConfig;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\SogoDirectory;
use App\Services\Mailboxes\MailboxAddress;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Services\Passwords\PasswordPolicyReader;
use App\Support\Database\NamedLock;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use App\Support\Passwords\NewPasswordValidator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * The password reset through the recovery address (feature 005 FR-019–FR-026, research R6), legacy
 * reset_password() with tokens shared with the legacy reset page and the spec's deviations:
 *
 *  - every well-formed request gets the same acknowledgement before any lookup; the eligibility
 *    check, the token and the message run after the response (Laravel's deferred callbacks), so
 *    that neither the answer nor its timing reveals the account;
 *  - a token is issued only for an active, non-resource, mailcow-source mailbox with a valid
 *    recovery address and fewer than `PW_RESET_TOKEN_LIMIT` outstanding tokens (the `pw_reset` flag
 *    is not checked, as in legacy), under a named lock; a token whose message cannot be sent is
 *    discarded;
 *  - a completion needs a valid second factor when the account has active second-factor entries;
 *    the new password is never kept between requests;
 *  - a completed reset also clears the forced password change and ends every session of the
 *    account in this API.
 *
 * The anonymous requests and completions are audited here (RefusalAuditor needs a principal).
 */
class PasswordResetService
{
    /** The session key of a reset's WebAuthn challenge, bound to the token's hash. */
    public const WEBAUTHN_SESSION_KEY = 'restv2.passwordResetWebauthn';

    public function __construct(
        private readonly PasswordResetMailer $mailer,
        private readonly ResetLinkBuilder $links,
        private readonly MailcowConfig $config,
        private readonly NamedLock $locks,
        private readonly AuditLogger $audit,
        private readonly NewPasswordValidator $rules,
        private readonly MailboxPasswordHasher $hasher,
        private readonly SecondFactorService $secondFactors,
        private readonly SessionRegistry $registry,
        private readonly SogoDirectory $groupware,
        private readonly GroupwareCacheFlush $cacheFlush,
        private readonly PasswordPolicyReader $policies,
    ) {}

    /**
     * @throws ProblemException password-reset-unavailable
     */
    public function assertAvailable(): void
    {
        if (! $this->mailer->available()) {
            throw new ProblemException(ProblemType::PasswordResetUnavailable);
        }
    }

    /**
     * Accepts a reset request and defers its work until after the response.
     *
     * @return array{tokenLifetimeMinutes: int}
     */
    public function request(Request $request, string $address): array
    {
        $this->assertAvailable();
        $host = $this->links->hostFor($request);

        defer(function () use ($address, $host): void {
            try {
                $this->issue($address, $host);
            } catch (Throwable $e) {
                Log::error('A password reset request could not be processed: '.$e->getMessage());
            }
        });

        return ['tokenLifetimeMinutes' => $this->config->pwResetTokenLifetime()];
    }

    /**
     * The deferred work of a request: clean-up, eligibility, token, message, audit record.
     */
    public function issue(string $address, string $host): void
    {
        $lifetime = $this->config->pwResetTokenLifetime();
        DB::delete('DELETE FROM `reset_password` WHERE `created` < DATE_SUB(NOW(), INTERVAL ? MINUTE)', [$lifetime]);

        $username = MailboxAddress::normalize($address)->address ?? mb_strtolower(trim($address));
        $mailbox = DB::table('mailbox')->where('username', $username)->first(['username', 'active', 'kind', 'authsource', 'attributes']);
        [$reason, $recovery] = $this->eligibility($mailbox);

        if ($reason === null && $mailbox !== null) {
            $username = (string) $mailbox->username;
            $reason = $this->locks->run(['restv2_pwreset_'.sha1($username)], function () use ($username, $recovery, $host): ?string {
                if (DB::table('reset_password')->where('username', $username)->count() >= $this->config->pwResetTokenLimit()) {
                    return 'tokenLimitReached';
                }

                $token = self::newToken();
                DB::insert('INSERT INTO `reset_password` (`username`, `token`) VALUES (?, ?)', [$username, $token]);

                if (! $this->mailer->send($username, (string) $recovery, $this->links->link($host, $token))) {
                    DB::delete('DELETE FROM `reset_password` WHERE `username` = ? AND `token` = ?', [$username, $token]);

                    return 'messageNotSent';
                }

                return null;
            });
        }

        $this->audit->record(
            AuditEventType::PasswordResetRequest,
            $reason === null,
            null,
            ['type' => 'passwordReset', 'id' => mb_substr($username, 0, 255), 'name' => null],
            null,
            $reason,
            [],
        );
    }

    /**
     * The account of a valid token, its expiry, the password rules and the second-factor
     * situation (contract `PasswordResetTokenInfo`).
     *
     * @return array<string, mixed>
     */
    public function check(string $token): array
    {
        $found = $this->find($token);
        $methods = $this->methods($found['username']);

        return [
            'address' => $found['username'],
            'expiresAt' => $found['expiresAt']->format('Y-m-d\TH:i:s\Z'),
            'passwordPolicy' => $this->policies->effective()->toArray(),
            'secondFactorRequired' => $this->secondFactors->hasActiveEntries($found['username']),
            'secondFactorMethods' => $methods,
        ];
    }

    /**
     * A WebAuthn challenge for the account of a valid token, bound to the token and the session.
     *
     * @return array{publicKey: array<string, mixed>, expiresAt: CarbonImmutable}
     */
    public function webauthnChallenge(Request $request, string $token): array
    {
        $found = $this->find($token);

        return $this->secondFactors->issueWebAuthnChallengeFor($request, $found['username'], self::WEBAUTHN_SESSION_KEY, ['tokenHash' => hash('sha256', $found['token'])])
            ?? throw new ProblemException(ProblemType::InvalidResetToken, 'The account has no WebAuthn second factor.');
    }

    /**
     * Completes a reset.
     *
     * @param  array<string, mixed>|null  $secondFactor  a validated `SecondFactorVerification`
     * @return array{address: string}
     */
    public function complete(Request $request, string $token, string $newPassword, ?array $secondFactor): array
    {
        $found = $this->find($token);
        $username = $found['username'];

        $violations = new FieldViolations;
        $this->rules->validate($newPassword, $violations);
        $violations->throwIfAny();

        $type = $this->verifySecondFactor($request, $username, $found['token'], $secondFactor);
        $hash = $this->hasher->hash($newPassword)->value;
        $forced = $this->forcedChangePending($username);

        $ended = DB::transaction(function () use ($username, $hash, $forced, $type): int {
            DB::update(
                "UPDATE `mailbox` SET `password` = ?, `attributes` = JSON_SET(`attributes`, '$.passwd_update', NOW(), '$.force_pw_update', '0') WHERE `username` = ? AND `authsource` = 'mailcow'",
                [$hash, $username],
            );
            DB::delete('DELETE FROM `reset_password` WHERE `username` = ?', [$username]);
            $this->groupware->refresh([$username]);
            $ended = $this->registry->endAll(AccountKind::Mailbox, $username);

            $this->audit->record(
                AuditEventType::PasswordResetCompletion,
                true,
                null,
                ['type' => 'passwordReset', 'id' => $username, 'name' => null],
                null,
                null,
                array_filter(['password' => 'changed', 'forcedChangeResolved' => $forced, 'secondFactorType' => $type, 'sessionsEnded' => $ended], fn (mixed $v): bool => $v !== null),
            );

            return $ended;
        });

        $this->cacheFlush->flush();

        return ['address' => $username];
    }

    /**
     * Five groups of six random upper-case hexadecimal characters (legacy's format).
     */
    public static function newToken(): string
    {
        return implode('-', array_map(fn (): string => strtoupper(bin2hex(random_bytes(3))), range(1, 5)));
    }

    /**
     * @return array{?string, ?string} the failure reason (null when eligible) and the recovery address
     */
    private function eligibility(?object $mailbox): array
    {
        if ($mailbox === null) {
            return ['unknownAccount', null];
        }

        if ((int) $mailbox->active !== 1 || (string) $mailbox->authsource !== 'mailcow' || preg_match('/'.Mailbox::RESOURCE_KIND_PATTERN.'/', (string) $mailbox->kind) === 1) {
            return ['accountNotEligible', null];
        }

        $attributes = json_decode((string) $mailbox->attributes, true);
        $recovery = is_array($attributes) && is_string($attributes['recovery_email'] ?? null) ? $attributes['recovery_email'] : '';

        if ($recovery === '' || filter_var($recovery, FILTER_VALIDATE_EMAIL) === false) {
            return ['noRecoveryEmail', null];
        }

        return [null, $recovery];
    }

    /**
     * Legacy reset_password('check'): the token (characters outside `[A-Za-z0-9-]` removed) of an
     * active mailcow-source mailbox, issued less than the token lifetime ago.
     *
     * @return array{username: string, token: string, expiresAt: CarbonImmutable}
     *
     * @throws ProblemException invalid-reset-token
     */
    private function find(string $token): array
    {
        $token = (string) preg_replace('/[^a-zA-Z0-9-]/', '', $token);
        $lifetime = $this->config->pwResetTokenLifetime();

        $row = $token === '' ? null : DB::selectOne(
            "SELECT `t1`.`username`, `t1`.`token`, `t1`.`created` FROM `reset_password` AS `t1` JOIN `mailbox` AS `t2` ON `t1`.`username` = `t2`.`username` WHERE `t1`.`token` = ? AND `t1`.`created` > DATE_SUB(NOW(), INTERVAL ? MINUTE) AND `t2`.`active` = 1 AND `t2`.`authsource` = 'mailcow'",
            [$token, $lifetime],
        );

        if ($row === null) {
            throw new ProblemException(ProblemType::InvalidResetToken);
        }

        $created = CarbonImmutable::parse((string) $row->created, (string) config('restv2.timezone', 'UTC'));

        return [
            'username' => (string) $row->username,
            'token' => (string) $row->token,
            'expiresAt' => $created->addMinutes($lifetime)->utc(),
        ];
    }

    /**
     * @return list<array{id: int, type: string, name: string, tier: string}>
     */
    private function methods(string $username): array
    {
        return array_values($this->secondFactors->usableMethods($username)->map(function ($row): array {
            $type = SecondFactorService::TYPES[(string) $row->authmech];

            return ['id' => (int) $row->id, 'type' => $type, 'name' => (string) $row->key_id, 'tier' => $type === 'yubiOtp' ? 'advanced' : 'primary'];
        })->all());
    }

    /**
     * The second-factor step of a completion: none needed without active entries; otherwise the
     * proof is verified (401 when missing or wrong, 403 when only deprecated entries exist).
     *
     * @param  array<string, mixed>|null  $proof
     * @return string|null the verified factor's type
     */
    private function verifySecondFactor(Request $request, string $username, string $token, ?array $proof): ?string
    {
        if (! $this->secondFactors->hasActiveEntries($username)) {
            return null;
        }

        $methods = $this->methods($username);

        if ($methods === []) {
            $this->auditFailure($username, 'secondFactorUnavailable', null);

            throw new ProblemException(ProblemType::SecondFactorUnavailable, 'The account\'s only second factors are deprecated. Ask an administrator to reset them.');
        }

        if ($proof === null) {
            throw new ProblemException(ProblemType::SecondFactorRequired, 'The account has second factors: send one with the new password.', ['methods' => $methods]);
        }

        $type = (string) $proof['type'];
        $session = $request->session();

        if ($type === 'webauthn' && $session->get(self::WEBAUTHN_SESSION_KEY.'.tokenHash') !== hash('sha256', $token)) {
            $session->forget(self::WEBAUTHN_SESSION_KEY);
        }

        $outcome = $this->secondFactors->verifyForAccount($request, $username, $proof, self::WEBAUTHN_SESSION_KEY);

        if ($outcome === SecondFactorOutcome::ServiceUnavailable) {
            $this->auditFailure($username, 'verificationUnavailable', $type);

            throw new ProblemException(ProblemType::TemporaryVerificationFailure, null, [], ['Retry-After' => '30']);
        }

        if ($outcome !== SecondFactorOutcome::Verified) {
            $this->auditFailure($username, 'secondFactorFailed', $type);

            throw new ProblemException(ProblemType::SecondFactorFailed);
        }

        return $type;
    }

    private function auditFailure(string $username, string $reason, ?string $type): void
    {
        DB::transaction(fn () => $this->audit->record(
            AuditEventType::PasswordResetCompletion,
            false,
            null,
            ['type' => 'passwordReset', 'id' => $username, 'name' => null],
            null,
            $reason,
            $type === null ? [] : ['secondFactorType' => $type],
        ));
    }

    private function forcedChangePending(string $username): bool
    {
        $raw = DB::table('mailbox')->where('username', $username)->value('attributes');
        $attributes = is_string($raw) ? json_decode($raw, true) : null;
        $value = is_array($attributes) ? ($attributes['force_pw_update'] ?? 0) : 0;

        return is_scalar($value) && (int) $value === 1;
    }
}
