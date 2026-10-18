<?php

namespace App\Services\SenderSettings;

use App\Services\Aliases\AliasAddress;
use App\Support\Domain\DomainName;

/**
 * One explicit sender permission or external sender address in the form legacy stores it in
 * `sender_acl.send_as` (feature 004 FR-054, FR-055, research R14): a domain as `@<ascii domain>`
 * (given as `domain` or `@domain`), an address normalised like alias addresses (local part case
 * kept), or `*` for "any address" (explicit permissions only).
 */
final readonly class SenderEntry
{
    public const ADDRESS = 'address';

    public const DOMAIN = 'domain';

    public const ANY_ADDRESS = 'anyAddress';

    private function __construct(public string $type, public string $value) {}

    /** An explicit permission, or null when the value is neither an address, a domain nor `*`. */
    public static function explicit(string $raw): ?self
    {
        return trim($raw) === '*' ? new self(self::ANY_ADDRESS, '*') : self::addressOrDomain($raw);
    }

    /** An external sender address, or null when the value is neither an address nor a domain. */
    public static function external(string $raw): ?self
    {
        return self::addressOrDomain($raw);
    }

    /** A stored `send_as` value as an entry (stored values are kept exactly). */
    public static function stored(string $sendAs): self
    {
        return match (true) {
            $sendAs === '*' => new self(self::ANY_ADDRESS, '*'),
            str_starts_with($sendAs, '@') => new self(self::DOMAIN, $sendAs),
            default => new self(self::ADDRESS, $sendAs),
        };
    }

    private static function addressOrDomain(string $raw): ?self
    {
        $raw = trim($raw);
        $bare = str_starts_with($raw, '@') ? substr($raw, 1) : $raw;

        if ($bare !== '' && ! str_contains($bare, '@')) {
            $domain = DomainName::normalizeValid($bare);

            return $domain === null ? null : new self(self::DOMAIN, '@'.$domain);
        }

        $address = AliasAddress::parse($raw);

        return $address !== null && ! $address->catchAll && $address->isValid() ? new self(self::ADDRESS, $address->address) : null;
    }

    /** The domain the entry names (the domain part of an address), or null for `*`. */
    public function domain(): ?string
    {
        return match ($this->type) {
            self::ANY_ADDRESS => null,
            self::DOMAIN => substr($this->value, 1),
            default => substr($this->value, (int) strrpos($this->value, '@') + 1),
        };
    }

    /** The case-insensitive comparison key. */
    public function key(): string
    {
        return mb_strtolower($this->value);
    }

    public function unicode(): string
    {
        return $this->type === self::ANY_ADDRESS ? '*' : AliasAddress::unicodeOf($this->value);
    }
}
