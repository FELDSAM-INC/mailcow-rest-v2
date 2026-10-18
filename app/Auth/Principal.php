<?php

namespace App\Auth;

use Carbon\CarbonImmutable;

/**
 * The authenticated caller of a request, built per request from the session or the API key.
 * Scope, permission flags and pending actions are not part of it: they are evaluated from
 * mailcow's current data on every request (FR-029).
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
    ) {}

    public function isApiKey(): bool
    {
        return $this->apiKey !== null || $this->authMethod === AuthMethod::ApiKey;
    }

    /**
     * The principal as written to the log context: `username (role)` or `apiKey:<id>`.
     */
    public function logLabel(): string
    {
        return $this->apiKey !== null
            ? 'apiKey:'.$this->apiKey->id
            : "{$this->username} ({$this->role->value})";
    }
}
