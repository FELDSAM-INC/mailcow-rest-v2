<?php

namespace App\Services\Auth;

use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Clients\Yubico\YubicoOtpVerifier;
use App\Clients\Yubico\YubicoVerificationResult;
use App\Exceptions\ProblemException;
use App\Models\Mailcow\TfaEntry;
use App\Services\Config\MailcowConfig;
use App\Support\Http\Base64Url;
use App\Support\Http\ProblemType;
use App\Support\Totp\NoQrCodeProvider;
use App\Support\WebAuthn\OptionsJson;
use App\Support\WebAuthn\WebAuthnServerFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RobThree\Auth\Algorithm;
use RobThree\Auth\TwoFactorAuth;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * The second-factor step of a password sign-in (FR-020–FR-025, R9–R11), ported from legacy
 * `get_tfa()`, `verify_tfa_login()` and `json_api.php` `webauthn-tfa-get-args`:
 *
 *  - usable methods are the account's active `tfa` rows of type WebAuthn, TOTP and Yubico OTP, in
 *    that order and then by `id`; U2F and HOTP rows are never offered or accepted;
 *  - TOTP is verified with legacy's library and parameters, and a code is accepted only once
 *    (a Lua check-and-set of the time slice on `restv2:totp:last:<tfa.id>`);
 *  - WebAuthn is verified with the row's PEM key against the pending sign-in's challenge;
 *  - Yubico OTP selects the row by the OTP's public ID and asks the Yubico service.
 *
 * A wrong factor cancels the pending sign-in and counts on the second-factor count of the username
 * and address, which a correct password does not reset (FR-024); only a completed sign-in resets
 * it. A vanished method cancels it without counting. A Yubico outage keeps it open.
 */
class SecondFactorService
{
    /** Contract `SecondFactorType` by mailcow `tfa.authmech`, in presentation order. */
    public const TYPES = ['webauthn' => 'webauthn', 'totp' => 'totp', 'yubi_otp' => 'yubiOtp'];

    /** Seconds a TOTP replay marker is kept: longer than the ±30 s acceptance window. */
    private const REPLAY_TTL = 120;

    private const REPLAY_SCRIPT = <<<'LUA'
        local last = redis.call('GET', KEYS[1])
        if last and tonumber(last) >= tonumber(ARGV[1]) then
          return 0
        end
        redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]))
        return 1
        LUA;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SignInThrottle $throttle,
        private readonly SignInAudit $audit,
        private readonly AccountResolver $accounts,
        private readonly PendingActionEvaluator $pendingActions,
        private readonly MailcowConfig $config,
        private readonly WebAuthnServerFactory $webauthn,
        private readonly YubicoOtpVerifier $yubico,
        private readonly RedisFactory $redis,
    ) {}

    /**
     * The account's active, usable second factors in presentation order.
     *
     * @return Collection<int, TfaEntry>
     */
    public function usableMethods(string $username): Collection
    {
        return TfaEntry::query()
            ->where('username', $username)
            ->where('active', 1)
            ->whereIn('authmech', array_keys(self::TYPES))
            ->orderByRaw("FIELD(`authmech`, 'webauthn', 'totp', 'yubi_otp')")
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the account has any active `tfa` row, usable or not (legacy `get_tfa()`).
     */
    public function hasActiveEntries(string $username): bool
    {
        return TfaEntry::query()->where('username', $username)->where('active', 1)->exists();
    }

    /**
     * Issues the WebAuthn challenge of the pending sign-in, replacing an earlier one.
     *
     * @return array{publicKey: array<string, mixed>, expiresAt: CarbonImmutable}
     */
    public function issueWebAuthnChallenge(Request $request): array
    {
        $session = $request->session();

        if ($this->sessions->pending($session) === null) {
            throw new ProblemException(ProblemType::SignInExpired);
        }

        $username = (string) $this->sessions->pending($session)['username'];
        $credentialIds = TfaEntry::query()
            ->where('username', $username)
            ->where('authmech', 'webauthn')
            ->where('active', 1)
            ->orderBy('id')
            ->pluck('keyHandle')
            ->map(fn (mixed $handle): string|false => base64_decode((string) $handle, true))
            ->filter(fn (string|false $id): bool => $id !== false && $id !== '')
            ->values()
            ->all();

        if ($credentialIds === []) {
            throw new ConflictHttpException('The pending sign-in has no WebAuthn second factor.');
        }

        $server = $this->webauthn->make($request);
        $args = $server->getGetArgs($credentialIds, $this->ttl(), false, false, false, false, false, $this->config->webauthnUvLogin());
        $expiresAt = $this->sessions->issue($session, SessionManager::PENDING_WEBAUTHN, $server->getChallenge()->getBinaryString());

        return [
            'publicKey' => OptionsJson::request($args, 'https://'.WebAuthnServerFactory::rpId($request)),
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Completes the pending sign-in with a second factor, or throws the problem of the failure.
     *
     * @param  array<string, mixed>  $input  a validated `SecondFactorVerification`
     */
    public function verify(Request $request, array $input): Principal
    {
        $session = $request->session();
        $type = (string) $input['type'];
        $details = ['requestedRole' => null, 'secondFactorType' => $type, 'step' => 'secondFactor'];
        $pending = $this->sessions->pending($session);

        if ($pending === null) {
            $stale = $session->get(SessionManager::PENDING.'.username');
            $this->sessions->cancelPending($session);
            $this->audit->failure(is_string($stale) ? $stale : null, null, AuthMethod::PasswordAndSecondFactor, 'challengeExpired', $details);

            throw new ProblemException(ProblemType::SignInExpired);
        }

        $username = $pending['username'];
        $key = $this->throttle->secondFactorKey($username, (string) $request->ip());
        $details['requestedRole'] = $pending['requestedRole']?->value;
        $fail = fn (string $reason) => $this->audit->failure($username, $pending['role'], AuthMethod::PasswordAndSecondFactor, $reason, $details);

        try {
            $this->throttle->assertNotThrottled($key);
        } catch (ProblemException $e) {
            $fail('throttled');

            throw $e;
        }

        $outcome = match ($type) {
            'totp' => $this->checkTotp($pending, (int) $input['methodId'], (string) $input['code']),
            'webauthn' => $this->checkWebAuthn($request, $pending, (array) $input['credential']),
            default => $this->checkYubicoOtp($pending, (string) $input['otp']),
        };

        switch ($outcome) {
            case SecondFactorOutcome::ChallengeExpired:
                $fail('challengeExpired');

                throw new ProblemException(ProblemType::SignInExpired);
            case SecondFactorOutcome::ServiceUnavailable:
                $fail('verificationUnavailable');

                throw new ProblemException(ProblemType::TemporaryVerificationFailure, null, [], ['Retry-After' => '30']);
            case SecondFactorOutcome::MethodVanished:
                $this->sessions->cancelPending($session);
                $fail('secondFactorFailed');

                throw new ProblemException(ProblemType::SecondFactorFailed);
            case SecondFactorOutcome::Failed:
                $this->sessions->cancelPending($session);
                $this->throttle->delay($this->throttle->hit($key));
                $fail('secondFactorFailed');

                throw new ProblemException(ProblemType::SecondFactorFailed);
            case SecondFactorOutcome::Verified:
                break;
        }

        $principal = new Principal(
            username: $username,
            role: $pending['role'],
            accountKind: $pending['accountKind'],
            authMethod: AuthMethod::PasswordAndSecondFactor,
            signedInAt: CarbonImmutable::now('UTC'),
        );

        // The account may have been deactivated since the password step.
        if (! $this->accounts->accountStillActive($principal)) {
            $this->sessions->cancelPending($session);
            $fail('accountInactive');

            throw new ProblemException(ProblemType::SignInFailed);
        }

        DB::transaction(fn () => $this->audit->success($principal, [
            'requestedRole' => $details['requestedRole'],
            'secondFactorType' => $type,
            'restricted' => $this->pendingActions->evaluate($principal) !== [],
        ]));

        $this->sessions->promote($session, $principal, $request->userAgent());
        $this->throttle->clear($key);

        return $principal;
    }

    /**
     * @param  array{username: string, methodIds: list<int>}  $pending
     */
    private function checkTotp(array $pending, int $methodId, string $code): SecondFactorOutcome
    {
        $row = TfaEntry::query()->where('id', $methodId)->where('username', $pending['username'])->first();
        $offered = in_array($methodId, $pending['methodIds'], true);

        if ($row === null || ! $row->active) {
            return $offered && ($row === null || $row->authmech === 'totp') ? SecondFactorOutcome::MethodVanished : SecondFactorOutcome::Failed;
        }

        if ($row->authmech !== 'totp') {
            return SecondFactorOutcome::Failed;
        }

        $timeslice = 0;

        try {
            $totp = new TwoFactorAuth(new NoQrCodeProvider, $this->config->otpLabel(), 6, 30, Algorithm::Sha1);
            $valid = $totp->verifyCode((string) $row->secret, $code, 1, CarbonImmutable::now()->getTimestamp(), $timeslice);
        } catch (Throwable) {
            return SecondFactorOutcome::Failed;
        }

        return $valid && $this->markTotpUsed($row->id, $timeslice) ? SecondFactorOutcome::Verified : SecondFactorOutcome::Failed;
    }

    /**
     * Records the accepted time slice; false when it (or a later one) was accepted before.
     */
    private function markTotpUsed(int $tfaId, int $timeslice): bool
    {
        // phpredis prefixes the key (`restv2:`) like every other key of the connection.
        $result = $this->redis->connection('restv2')->command('eval', [self::REPLAY_SCRIPT, ['totp:last:'.$tfaId, $timeslice, self::REPLAY_TTL], 1]);

        return (int) $result === 1;
    }

    /**
     * @param  array{username: string, methodIds: list<int>}  $pending
     * @param  array<string, mixed>  $credential  a validated `WebAuthnAuthenticationResponse`
     */
    private function checkWebAuthn(Request $request, array $pending, array $credential): SecondFactorOutcome
    {
        $challenge = $this->sessions->consume($request->session(), SessionManager::PENDING_WEBAUTHN);

        if ($challenge === null) {
            return SecondFactorOutcome::ChallengeExpired;
        }

        $rawId = Base64Url::decode((string) ($credential['rawId'] ?? ''));
        $row = $rawId === null || $rawId === '' ? null : TfaEntry::query()
            ->where('username', $pending['username'])
            ->where('authmech', 'webauthn')
            ->get()
            ->first(fn (TfaEntry $entry): bool => base64_decode((string) $entry->keyHandle, true) === $rawId);

        if ($row === null) {
            return SecondFactorOutcome::Failed;
        }

        if (! $row->active) {
            return in_array($row->id, $pending['methodIds'], true) ? SecondFactorOutcome::MethodVanished : SecondFactorOutcome::Failed;
        }

        $response = (array) ($credential['response'] ?? []);

        try {
            $this->webauthn->make($request)->processGet(
                Base64Url::decode((string) ($response['clientDataJSON'] ?? '')) ?? '',
                Base64Url::decode((string) ($response['authenticatorData'] ?? '')) ?? '',
                Base64Url::decode((string) ($response['signature'] ?? '')) ?? '',
                (string) $row->publicKey,
                $challenge['challenge'],
                null,
                $this->config->webauthnUvLogin(),
                $this->config->webauthnUserPresent(),
            );
        } catch (Throwable) {
            return SecondFactorOutcome::Failed;
        }

        return SecondFactorOutcome::Verified;
    }

    /**
     * @param  array{username: string, methodIds: list<int>}  $pending
     */
    private function checkYubicoOtp(array $pending, string $otp): SecondFactorOutcome
    {
        $pattern = '%'.addcslashes(substr($otp, 0, 12), '%_\\');
        $rows = TfaEntry::query()
            ->where('username', $pending['username'])
            ->where('authmech', 'yubi_otp')
            ->where('secret', 'like', $pattern)
            ->orderBy('id')
            ->get();
        $row = $rows->first(fn (TfaEntry $entry): bool => $entry->active);

        if ($row === null) {
            $vanished = $rows->contains(fn (TfaEntry $entry): bool => in_array($entry->id, $pending['methodIds'], true));

            return $vanished ? SecondFactorOutcome::MethodVanished : SecondFactorOutcome::Failed;
        }

        [$clientId, $key] = array_pad(explode(':', (string) $row->secret), 2, '');

        return match ($this->yubico->verify($clientId, $key, $otp)) {
            YubicoVerificationResult::Valid => SecondFactorOutcome::Verified,
            YubicoVerificationResult::Invalid => SecondFactorOutcome::Failed,
            YubicoVerificationResult::TemporaryFailure => SecondFactorOutcome::ServiceUnavailable,
        };
    }

    private function ttl(): int
    {
        return max(1, (int) config('restv2.challenge_ttl'));
    }
}
