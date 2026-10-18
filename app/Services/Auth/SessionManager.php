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

/**
 * The browser session's API data (R6, data-model "Session (Redis)"):
 *
 *  - `restv2.principal`: the signed-in account (`restricted` or `active`);
 *  - `restv2.pending`: a sign-in whose password was verified and which awaits a second factor;
 *  - `restv2.passkeyChallenge` and `restv2.passkeyRegistration`: single-use WebAuthn challenges;
 *  - `restv2.lastActivity`: Unix seconds of the last request (idle timeout, FR-009).
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

    public function __construct(private readonly PendingActionEvaluator $evaluator) {}

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
     * @return array{username: string, role: Role, accountKind: AccountKind, methodIds: list<int>, expiresAt: CarbonImmutable, requestedRole: Role|null}|null
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
        ];
    }

    /**
     * Starts a pending sign-in after a verified password: renews the session identifier and
     * replaces any earlier sign-in of the session. Returns its expiry.
     *
     * @param  list<int>  $methodIds  the usable second factors, in presentation order
     * @param  Role|null  $requestedRole  the role the sign-in named, kept for the audit record
     */
    public function beginPending(Session $session, string $username, Role $role, AccountKind $kind, array $methodIds, ?string $userAgent, ?Role $requestedRole = null): CarbonImmutable
    {
        $expiresAt = CarbonImmutable::now('UTC')->addSeconds($this->challengeTtl());

        $session->migrate(true);
        $session->forget(self::PRINCIPAL);
        $session->put(self::PENDING, [
            'username' => $username,
            'role' => $role->value,
            'accountKind' => $kind->value,
            'methodIds' => $methodIds,
            'expiresAt' => $expiresAt->getTimestamp(),
            'userAgentHash' => self::userAgentHash($userAgent),
            'requestedRole' => $requestedRole?->value,
        ]);
        $this->touch($session);

        return $expiresAt;
    }

    /**
     * Signs the principal in: renews the session identifier, ends any pending sign-in and keeps
     * the anti-forgery token (R7).
     */
    public function promote(Session $session, Principal $principal, ?string $userAgent): void
    {
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
        ], fn (mixed $value): bool => $value !== null));
        $this->touch($session);
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
     * 012 FR-027); the user stays signed in at the provider.
     */
    public function end(Session $session): void
    {
        $linkId = $this->principal($session)?->ssoLinkId;

        if ($linkId !== null) {
            app(SsoLinkStore::class)->delete($linkId);
        }

        $session->invalidate();
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
