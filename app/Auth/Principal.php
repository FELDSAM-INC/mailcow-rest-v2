<?php

namespace App\Auth;

use Carbon\CarbonImmutable;

/**
 * The authenticated caller of a request, built per request from the session or the API key.
 * Scope, permission flags and pending actions are not part of it: they are evaluated from
 * mailcow's current data on every request (FR-029).
 *
 * Feature 007: during an impersonation the request's principal is the impersonated account (its
 * username, role and account kind) with the signed-in account in `impersonator`; the sign-in
 * method and time are the impersonator's. `accountCreatedAt` is the raw mailcow `created` value of
 * the account when it signed in (or was impersonated), so that a session never acts for a later
 * account with the same username (007 FR-036).
 */
final readonly class Principal
{
    public function __construct(
        public string $username,
        public Role $role,
        public AccountKind $accountKind,
        public AuthMethod $authMethod,
        public ?CarbonImmutable $signedInAt = null,
        public ?ApiKeyReference $apiKey = null,
        // Feature 012: the provider of a sign-in through the identity provider, who verified the
        // password of a password sign-in, and the provider link of a single sign-on session.
        public ?IdentityProviderType $identityProvider = null,
        public ?PasswordSource $passwordSource = null,
        public ?string $ssoLinkId = null,
        // Feature 007: the impersonating account, the impersonation's ULID and start, and the
        // account's raw creation time.
        public ?Principal $impersonator = null,
        public ?string $impersonationId = null,
        public ?CarbonImmutable $impersonationStartedAt = null,
        public ?string $accountCreatedAt = null,
    ) {}

    public function isApiKey(): bool
    {
        return $this->apiKey !== null || $this->authMethod === AuthMethod::ApiKey;
    }

    /**
     * Whether the principal is an impersonated account acting for its impersonator (feature 007).
     */
    public function isImpersonating(): bool
    {
        return $this->impersonator !== null;
    }

    /**
     * The account that signed in and acts: the impersonator during an impersonation, otherwise the
     * principal itself (feature 007). Pending actions, preferences and the audit actor are its.
     */
    public function actingAccount(): self
    {
        return $this->impersonator ?? $this;
    }

    /**
     * The principal as written to the log context: `username (role)`, `apiKey:<id>`, or during an
     * impersonation `impersonator (role) as target (role)`.
     */
    public function logLabel(): string
    {
        if ($this->impersonator !== null) {
            return $this->impersonator->logLabel()." as {$this->username} ({$this->role->value})";
        }

        return $this->apiKey !== null
            ? 'apiKey:'.$this->apiKey->id
            : "{$this->username} ({$this->role->value})";
    }
}
