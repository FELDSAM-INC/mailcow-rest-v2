<?php

namespace App\Services\Aliases;

use App\Support\Domain\DomainName;

/**
 * An address as legacy mailbox('add'/'edit', 'alias') normalises it (feature 004 FR-008, FR-009,
 * research R3), used for alias addresses, destinations and sender permission entries: trimmed,
 * split at the last `@`, the local part kept as given (aliases keep its letter case, unlike
 * mailboxes), the domain part converted with 002's DomainName (UTS #46, lower case).
 *
 * isValid() is legacy's `filter_var(FILTER_VALIDATE_EMAIL)` on the result, plus the rules FR-009
 * adds: no quoted local part and no address literal (the domain must be a valid domain name). A
 * catch-all is `@` followed by a valid domain name, also a single label. Addresses compare by key().
 */
final readonly class AliasAddress
{
    private function __construct(
        public string $address,
        public string $localPart,
        public string $domain,
        public bool $catchAll,
        private bool $domainNormalised,
    ) {}

    /** The normalised address, or null when the value has no `@`. */
    public static function parse(string $raw): ?self
    {
        $raw = trim($raw);
        $at = strrpos($raw, '@');

        if ($at === false) {
            return null;
        }

        $local = substr($raw, 0, $at);
        $rawDomain = substr($raw, $at + 1);
        $domain = DomainName::normalize($rawDomain);

        return new self(
            $local.'@'.($domain ?? mb_strtolower(trim($rawDomain))),
            $local,
            $domain ?? mb_strtolower(trim($rawDomain)),
            $local === '',
            $domain !== null,
        );
    }

    /** Whether the address is valid by legacy's rules (FR-009), or a valid catch-all. */
    public function isValid(): bool
    {
        if (! $this->domainNormalised || ! DomainName::isValid($this->domain)) {
            return false;
        }

        if ($this->catchAll) {
            return true;
        }

        return ! str_starts_with($this->localPart, '"')
            && strlen($this->localPart) <= 64
            && filter_var($this->address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** The case-insensitive comparison key. */
    public function key(): string
    {
        return mb_strtolower($this->address);
    }

    /** The address with its domain part in readable (Unicode) form. */
    public function unicode(): string
    {
        return $this->localPart.'@'.DomainName::toUnicode($this->domain);
    }

    /** The readable form of any stored address (`local@domain` or `@domain`); other values unchanged. */
    public static function unicodeOf(string $stored): string
    {
        $at = strrpos($stored, '@');

        return $at === false ? $stored : substr($stored, 0, $at + 1).DomainName::toUnicode(substr($stored, $at + 1));
    }
}
