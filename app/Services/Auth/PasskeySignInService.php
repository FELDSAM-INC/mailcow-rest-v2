<?php

namespace App\Services\Auth;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Models\Mailcow\Admin;
use App\Models\Mailcow\Mailbox;
use App\Services\Config\MailcowConfig;
use App\Support\Http\Base64Url;
use App\Support\Http\ProblemType;
use App\Support\WebAuthn\OptionsJson;
use App\Support\WebAuthn\WebAuthnServerFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Usernameless passkey sign-in (FR-015–FR-018, R9, R16), ported from legacy `fido2-get-args` and
 * `fido2('verify')`, with the checks legacy lacks (FR-016): the credential must be active, the
 * account active and of the requested role. Failures are throttled per client address, audited,
 * and never reported to fail2ban.
 */
class PasskeySignInService
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SignInThrottle $throttle,
        private readonly SignInAudit $audit,
        private readonly AccountResolver $accounts,
        private readonly PendingActionEvaluator $pendingActions,
        private readonly RecentLoginRecorder $recentLogins,
        private readonly MailcowConfig $config,
        private readonly WebAuthnServerFactory $webauthn,
    ) {}

    /**
     * Issues the session's passkey challenge (empty `allowCredentials`), replacing an earlier one.
     *
     * @return array{publicKey: array<string, mixed>, expiresAt: CarbonImmutable}
     */
    public function issueChallenge(Request $request): array
    {
        $server = $this->webauthn->make($request);
        $args = $server->getGetArgs([], $this->ttl(), false, false, false, false, false, $this->config->fido2UvLogin());
        $expiresAt = $this->sessions->issue($request->session(), SessionManager::PASSKEY_CHALLENGE, $server->getChallenge()->getBinaryString());

        return ['publicKey' => OptionsJson::request($args), 'expiresAt' => $expiresAt];
    }

    /**
     * Verifies the assertion for the session's challenge and signs its account in.
     *
     * @param  array<string, mixed>  $credential  a validated `WebAuthnAuthenticationResponse`
     */
    public function signIn(Request $request, array $credential, ?Role $requestedRole): Principal
    {
        $session = $request->session();
        $ip = (string) $request->ip();
        $key = $this->throttle->passkeyKey($ip);
        $details = ['requestedRole' => $requestedRole?->value, 'step' => 'passkey'];

        $fail = function (?string $username, string $reason, bool $counts = true) use ($key, $requestedRole, $details): never {
            if ($counts) {
                $this->throttle->delay($this->throttle->hit($key));
            }

            $this->audit->failure($username, $requestedRole, AuthMethod::Passkey, $reason, $details);

            throw new ProblemException($reason === 'challengeExpired' ? ProblemType::SignInExpired : ProblemType::SignInFailed);
        };

        try {
            $this->throttle->assertNotThrottled($key);
        } catch (ProblemException $e) {
            $this->audit->failure(null, $requestedRole, AuthMethod::Passkey, 'throttled', $details);

            throw $e;
        }

        $challenge = $this->sessions->consume($session, SessionManager::PASSKEY_CHALLENGE) ?? $fail(null, 'challengeExpired', false);

        $rawId = Base64Url::decode((string) ($credential['rawId'] ?? ''));
        $row = $rawId === null || $rawId === '' ? null : DB::table('fido2')
            ->where('credentialId', $rawId)
            ->first(['username', 'credentialPublicKey', 'active']);

        if ($row === null) {
            $fail(null, 'unknownPasskey');
        }

        $username = (string) $row->username;
        $response = (array) ($credential['response'] ?? []);

        try {
            $this->webauthn->make($request)->processGet(
                Base64Url::decode((string) ($response['clientDataJSON'] ?? '')) ?? '',
                Base64Url::decode((string) ($response['authenticatorData'] ?? '')) ?? '',
                Base64Url::decode((string) ($response['signature'] ?? '')) ?? '',
                (string) $row->credentialPublicKey,
                $challenge['challenge'],
                null,
                $this->config->fido2UvLogin(),
                $this->config->fido2UserPresent(),
            );
        } catch (Throwable) {
            $fail($username, 'passkeyVerificationFailed');
        }

        if ((int) $row->active !== 1) {
            $fail($username, 'passkeyInactive');
        }

        [$role, $reason] = $this->resolveRole($username, $requestedRole);

        if ($role === null) {
            $fail($username, $reason);
        }

        $principal = new Principal(
            username: $username,
            role: $role,
            accountKind: $role->accountKind(),
            authMethod: AuthMethod::Passkey,
            signedInAt: CarbonImmutable::now('UTC'),
        );

        if (! $this->accounts->accountStillActive($principal)) {
            $fail($username, 'accountInactive');
        }

        DB::transaction(function () use ($principal, $ip, $requestedRole): void {
            if ($principal->role === Role::MailboxUser) {
                $this->recentLogins->record($principal->username, $ip);
            }

            $this->audit->success($principal, [
                'requestedRole' => $requestedRole?->value,
                'restricted' => $this->pendingActions->evaluate($principal) !== [],
            ]);
        });

        $this->throttle->clear($key);
        // A completed sign-in also resets the account's second-factor count (FR-024).
        $this->throttle->clear($this->throttle->secondFactorKey($principal->username, $ip));
        $this->sessions->promote($session, $principal, $request->userAgent());

        return $principal;
    }

    /**
     * The role of the credential's account: administrator or domain administrator (`admin`), then
     * mailbox user; with a requested role, only that type. Otherwise null with the reason.
     *
     * @return array{Role, null}|array{null, string}
     */
    private function resolveRole(string $username, ?Role $requested): array
    {
        $superadmin = Admin::query()->whereKey($username)->toBase()->value('superadmin');
        $adminRole = $superadmin === null ? null : ((int) $superadmin === 1 ? Role::Admin : Role::DomainAdmin);
        $mailboxRole = Mailbox::query()->whereKey($username)->exists() ? Role::MailboxUser : null;

        $role = match ($requested) {
            null => $adminRole ?? $mailboxRole,
            Role::MailboxUser => $mailboxRole,
            default => $adminRole === $requested ? $requested : null,
        };

        if ($role !== null) {
            return [$role, null];
        }

        return [null, $adminRole !== null || $mailboxRole !== null ? 'roleMismatch' : 'invalidCredentials'];
    }

    private function ttl(): int
    {
        return max(1, (int) config('restv2.challenge_ttl'));
    }
}
