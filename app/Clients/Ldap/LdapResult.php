<?php

namespace App\Clients\Ldap;

/**
 * The result of a directory call (research R2): the status, the user's entry when one was found
 * (`dn` and its attributes, lower-cased names, every value a list of strings) and the LDAP result
 * code of a failure. Never carries a password.
 */
final readonly class LdapResult
{
    /**
     * @param  array<string, list<string>>  $attributes
     */
    public function __construct(
        public LdapStatus $status,
        public ?string $dn = null,
        public array $attributes = [],
        public ?int $code = null,
    ) {}

    /**
     * @param  array<string, list<string>>  $attributes
     */
    public static function ok(?string $dn = null, array $attributes = []): self
    {
        return new self(LdapStatus::Ok, $dn, $attributes);
    }

    public static function wrongPassword(?string $dn = null): self
    {
        return new self(LdapStatus::WrongPassword, $dn, [], 49);
    }

    public static function unknownUser(): self
    {
        return new self(LdapStatus::UnknownUser);
    }

    public static function credentialsRejected(): self
    {
        return new self(LdapStatus::CredentialsRejected, null, [], 49);
    }

    public static function unreachable(?int $code = -1): self
    {
        return new self(LdapStatus::Unreachable, null, [], $code);
    }

    public static function timedOut(): self
    {
        return new self(LdapStatus::TimedOut, null, [], -5);
    }

    public static function tlsFailure(?int $code = -11): self
    {
        return new self(LdapStatus::TlsFailure, null, [], $code);
    }

    public static function unexpected(int $code): self
    {
        return new self(LdapStatus::Unexpected, null, [], $code);
    }

    /** The first value of an attribute (legacy reads `[0]`), or null. */
    public function first(string $attribute): ?string
    {
        $values = $this->attributes[strtolower($attribute)] ?? [];

        return $values[0] ?? null;
    }
}
