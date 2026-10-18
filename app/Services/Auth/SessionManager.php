<?php

namespace App\Services\Auth;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\IdentityProviderType;
use App\Auth\PasswordSource;
use App\Auth\PendingAction;
use App\Auth\Principal;
use App\Auth\Role;
use App\Auth\SessionState;
use App\Services\IdentityProvider\SsoLinkStore;
use App\Support\Http\Base64Url;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The browser session's API data (R6, data-model "Session (Redis)"):
 *
 *  - `restv2.principal`: the signed-in account (`restricted` or `active`);
 *  - `restv2.pending`: a sign-in whose password was verified and which awaits a second factor;
 *  - `restv2.passkeyChallenge` and `restv2.passkeyRegistration`: single-use WebAuthn challenges;
 *  - `restv2.lastActivity`: Unix seconds of the last request (idle timeout, FR-009);
 *  - `restv2.handle`: the stable handle of a signed-in session (feature 005 research R2), which
 *    survives identifier renewals and keys the account's entry in SessionRegistry.
 *
 * The principal and the pending sign-in carry the SHA-256 of the User-Agent they were created
 * with (FR-012). Every step that changes who is signed in renews the session identifier (FR-008).
 * Session data is JSON-serialised, so only arrays and scalars are stored.
 */
class SessionManager
{
    public const PRINCIPAL = 'restv2.principal';

    public const PENDING = 'restv2.pending';

    public const PASSKEY_CHALLENGE = 'restv2.passkeyChallenge';

    public const PASSKEY_REGISTRATION = 'restv2.passkeyRegistration';

    /** The WebAuthn challenge of a pending sign-in, kept inside it. */
    public const PENDING_WEBAUTHN = 'restv2.pending.webauthn';

    public const LAST_ACTIVITY = 'restv2.lastActivity';

    public const HANDLE = 'restv2.handle';

    public function __construct(
        private readonly PendingActionEvaluator $evaluator,
        private readonly SessionRegistry $registry,
    ) {}

    public function state(Session $session): SessionState
    {
        $principal = $this->principal($session);

        if ($principal !== null) {
            return $this->evaluator->evaluate($principal) === [] ? SessionState::Active : SessionState::Restricted;
        }

        return $this->pending($session) !== null ? SessionState::PendingSecondFactor : SessionState::Anonymous;
    }

    /**
     * @return list<PendingAction>
     */
    public function pendingActions(Session $session): array
    {
        $principal = $this->principal($session);

        return $principal === null ? [] : $this->evaluator->evaluate($principal);
    }

    public function principal(Session $session): ?Principal
    {
        $data = $session->get(self::PRINCIPAL);

        if (! is_array($data)) {
            return null;
        }

        $role = Role::tryFrom((string) ($data['role'] ?? ''));
        $kind = AccountKind::tryFrom((string) ($data['accountKind'] ?? ''));
        $method = AuthMethod::tryFrom((string) ($data['authMethod'] ?? ''));

        if ($role === null || $kind === null || $method === null || ! is_string($data['username'] ?? null)) {
            return null;
        }

        return new Principal(
            username: $data['username'],
            role: $role,
            accountKind: $kind,
            authMethod: $method,
            signedInAt: isset($data['signedInAt']) ? CarbonImmutable::createFromTimestampUTC((int) $data['signedInAt']) : null,
            identityProvider: IdentityProviderType::tryFrom((string) ($data['identityProvider'] ?? '')),
            passwordSource: PasswordSource::tryFrom((string) ($data['passwordSource'] ?? '')),
            ssoLinkId: is_string($data['ssoLinkId'] ?? null) ? $data['ssoLinkId'] : null,
        );
    }

    /**
     * The pending sign-in, unless it is missing or expired.
     *
     * @return array{username: string, role: Role, accountKind: AccountKind, methodIds: list<int>, expiresAt: CarbonImmutable, requestedRole: Role|null, passwordSource: PasswordSource}|null
     */
    public function pending(Session $session): ?array
    {
        $data = $session->get(self::PENDING);

        if (! is_array($data) || (int) ($data['expiresAt'] ?? 0) <= CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        $role = Role::tryFrom((string) ($data['role'] ?? ''));
        $kind = AccountKind::tryFrom((string) ($data['accountKind'] ?? ''));

        if ($role === null || $kind === null || ! is_string($data['username'] ?? null)) {
            return null;
        }

        return [
            'username' => $data['username'],
            'role' => $role,
            'accountKind' => $kind,
            'methodIds' => array_values(array_map('intval', (array) ($data['methodIds'] ?? []))),
            'expiresAt' => CarbonImmutable::createFromTimestampUTC((int) $data['expiresAt']),
            'requestedRole' => Role::tryFrom((string) ($data['requestedRole'] ?? '')),
            'passwordSource' => PasswordSource::tryFrom((string) ($data['passwordSource'] ?? '')) ?? PasswordSource::Mailcow,
        ];
    }

    /**
     * Starts a pending sign-in after a verified password: renews the session identifier and
     * replaces any earlier sign-in of the session. Returns its expiry.
     *
     * @param  list<int>  $methodIds  the usable second factors, in presentation order
     * @param  Role|null  $requestedRole  the role the sign-in named, kept for the audit record
     * @param  PasswordSource  $passwordSource  who verified the password (feature 012)
     */
    public function beginPending(Session $session, string $username, Role $role, AccountKind $kind, array $methodIds, ?string $userAgent, ?Role $requestedRole = null, PasswordSource $passwordSource = PasswordSource::Mailcow): CarbonImmutable
    {
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds($this->challengeTtl());

        $this->unregister($session);
        $session->migrate(true);
        $session->forget([self::PRINCIPAL, self::HANDLE]);
        $session->put(self::PENDING, [
            'username' => $username,
            'role' => $role->value,
            'accountKind' => $kind->value,
            'methodIds' => $methodIds,
            'expiresAt' => $expiresAt->getTimestamp(),
            'userAgentHash' => self::userAgentHash($userAgent),
            'requestedRole' => $requestedRole?->value,
            // Feature 012: who verified the password (the method of the session after the second factor).
            'passwordSource' => $passwordSource->value,
        ]);
        $this->touch($session);

        return $expiresAt;
    }

    /**
     * Signs the principal in: renews the session identifier, ends any pending sign-in, keeps the
     * anti-forgery token (R7) and registers the session under a new handle (feature 005 R2). The
     * second factor that completed the sign-in is kept for `usedForCurrentSignIn` (feature 005
     * FR-027).
     */
    public function promote(Session $session, Principal $principal, ?string $userAgent, ?int $secondFactorId = null): void
    {
        $this->unregister($session);
        $session->migrate(true);
        $session->forget([self::PENDING, self::PASSKEY_CHALLENGE, self::PASSKEY_REGISTRATION]);
        $session->put(self::PRINCIPAL, array_filter([
            'username' => $principal->username,
            'role' => $principal->role->value,
            'accountKind' => $principal->accountKind->value,
            'authMethod' => $principal->authMethod->value,
            'signedInAt' => ($principal->signedInAt ?? CarbonImmutable::now('UTC'))->getTimestamp(),
            'userAgentHash' => self::userAgentHash($userAgent),
            // Feature 012: the provider of a sign-in through it, and the single sign-on link.
            'identityProvider' => $principal->identityProvider?->value,
            'passwordSource' => $principal->passwordSource?->value,
            'ssoLinkId' => $principal->ssoLinkId,
            'secondFactorId' => $secondFactorId,
        ], fn (mixed $value): bool => $value !== null));
        $session->put(self::HANDLE, (string) Str::ulid());
        $this->touch($session);
        $this->register($session);
    }

    /**
     * Renews the identifier of a signed-in session, keeping its data and its handle (feature 005
     * R4: the session that changed its password stays signed in with a new identifier).
     */
    public function renew(Session $session): void
    {
        $session->migrate(true);
        $this->register($session);
    }

    /**
     * The stable handle of the signed-in session, or null.
     */
    public function handle(Session $session): ?string
    {
        $handle = $session->get(self::HANDLE);

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * The `tfa.id` of the second factor that completed the session's sign-in, or null.
     */
    public function secondFactorId(Session $session): ?int
    {
        $id = $session->get(self::PRINCIPAL.'.secondFactorId');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Records the signed-in session in the account's registry entry, under its handle (one is
     * given to a session signed in before feature 005), and refreshes the entry's lifetime. Called
     * on every request of a signed-in session.
     */
    public function register(Session $session): void
    {
        $principal = $this->principal($session);

        if ($principal === null) {
            return;
        }

        $handle = $this->handle($session);

        if ($handle === null) {
            $handle = (string) Str::ulid();
            $session->put(self::HANDLE, $handle);
        }

        $this->registry->register($principal->accountKind, $principal->username, $handle, $session->getId());
    }

    /**
     * Cancels a pending sign-in, keeping the anonymous session and its token.
     */
    public function cancelPending(Session $session): void
    {
        $session->forget(self::PENDING);
    }

    /**
     * Ends the session: its data is destroyed, the identifier and the anti-forgery token renewed
     * (FR-010). The provider tokens of a single sign-on session are discarded with it (feature
     * 012 FR-027); the user stays signed in at the provider. Its registry entry and what is tied to
     * it (feature 005: the webmail hand-off) end with it.
     */
    public function end(Session $session): void
    {
        $linkId = $this->principal($session)?->ssoLinkId;

        if ($linkId !== null) {
            app(SsoLinkStore::class)->delete($linkId);
        }

        $handle = $this->handle($session);
        $this->unregister($session);

        if ($handle !== null) {
            $this->registry->ended($handle);
        }

        $session->invalidate();
    }

    /**
     * Removes the signed-in session's registry entry, if any.
     */
    private function unregister(Session $session): void
    {
        $principal = $this->principal($session);
        $handle = $this->handle($session);

        if ($principal !== null && $handle !== null) {
            $this->registry->forget($principal->accountKind, $principal->username, $handle);
        }
    }

    /**
     * Issues a single-use challenge under $key (replacing an earlier one), valid for
     * `RESTV2_CHALLENGE_TTL` seconds.
     *
     * @param  array<string, mixed>  $extra  scalar data kept with the challenge
     */
    public function issue(Session $session, string $key, string $challenge, array $extra = []): CarbonImmutable
    {
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds($this->challengeTtl());

        $session->put($key, ['challenge' => Base64Url::encode($challenge), 'expiresAt' => $expiresAt->getTimestamp()] + $extra);

        return $expiresAt;
    }

    /**
     * Removes the challenge under $key and returns it with the binary `challenge`, or null when it
     * is missing or expired. A challenge is consumed by the first attempt, whatever its outcome.
     *
     * @return array<string, mixed>|null
     */
    public function consume(Session $session, string $key): ?array
    {
        $data = $session->pull($key);

        if (! is_array($data) || (int) ($data['expiresAt'] ?? 0) <= CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        $challenge = Base64Url::decode((string) ($data['challenge'] ?? ''));

        return $challenge === null ? null : ['challenge' => $challenge] + $data;
    }

    public function touch(Session $session): void
    {
        $session->put(self::LAST_ACTIVITY, CarbonImmutable::now()->getTimestamp());
    }

    public function lastActivity(Session $session): ?int
    {
        $value = $session->get(self::LAST_ACTIVITY);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The User-Agent hash the session's sign-in (or pending sign-in) is bound to.
     */
    public function boundUserAgentHash(Session $session): ?string
    {
        $hash = $session->get(self::PRINCIPAL.'.userAgentHash') ?? $session->get(self::PENDING.'.userAgentHash');

        return is_string($hash) ? $hash : null;
    }

    public static function userAgentHash(?string $userAgent): string
    {
        return hash('sha256', (string) $userAgent);
    }

    private function challengeTtl(): int
    {
        return max(1, (int) config('restv2.challenge_ttl'));
    }
}
