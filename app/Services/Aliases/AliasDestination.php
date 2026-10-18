<?php

namespace App\Services\Aliases;

/**
 * What an alias does with mail (feature 004 FR-012, FR-025, FR-026, research R4): deliver to a list
 * of addresses, or one special action stored as the reserved address the legacy UI uses
 * (`null@localhost` discard, `spam@localhost` learn as spam, `ham@localhost` learn as ham).
 *
 * A stored `goto` maps to a special action only when it is exactly a reserved address; any other
 * value is the comma-separated list exactly as stored. A list built from a request keeps the first
 * spelling of addresses that differ only in letter case, in the order first given.
 */
final readonly class AliasDestination
{
    public const ADDRESSES = 'addresses';

    /** Contract `destinationType` => reserved address. */
    public const SPECIAL = ['discard' => 'null@localhost', 'learnSpam' => 'spam@localhost', 'learnHam' => 'ham@localhost'];

    /** The `alias.goto` TEXT column holds at most 65,535 characters. */
    public const MAX_LENGTH = 65535;

    /**
     * @param  list<string>  $addresses
     */
    private function __construct(public string $type, public array $addresses) {}

    public static function fromGoto(string $goto): self
    {
        $special = array_search($goto, self::SPECIAL, true);

        if (is_string($special)) {
            return new self($special, []);
        }

        return new self(self::ADDRESSES, $goto === '' ? [] : explode(',', $goto));
    }

    public static function special(string $type): self
    {
        return new self(array_key_exists($type, self::SPECIAL) ? $type : 'discard', []);
    }

    /**
     * A destination list, letter-case duplicates merged (first spelling and order kept).
     *
     * @param  list<string>  $addresses
     */
    public static function addresses(array $addresses): self
    {
        $unique = [];

        foreach ($addresses as $address) {
            $unique[mb_strtolower($address)] ??= $address;
        }

        return new self(self::ADDRESSES, array_values($unique));
    }

    public function isSpecial(): bool
    {
        return $this->type !== self::ADDRESSES;
    }

    public function toGoto(): string
    {
        return $this->isSpecial() ? self::SPECIAL[$this->type] : implode(',', $this->addresses);
    }

    public function fitsStorage(): bool
    {
        return mb_strlen($this->toGoto()) <= self::MAX_LENGTH;
    }

    /**
     * The destinations of this list that the stored destination does not have (case-insensitive),
     * keyed by their position in this list. Every address is added when the stored destination is
     * a special action.
     *
     * @return array<int, string>
     */
    public function addedComparedTo(self $stored): array
    {
        $existing = array_flip(array_map('mb_strtolower', $stored->addresses));

        return array_filter($this->addresses, fn (string $a): bool => ! isset($existing[mb_strtolower($a)]));
    }

    /**
     * The addresses whose groupware entries an alias change affects: the destinations that are not
     * reserved addresses (legacy's `$update_sogo_mailboxes`).
     *
     * @return list<string>
     */
    public function mailboxCandidates(): array
    {
        return array_values(array_filter($this->addresses, fn (string $a): bool => ! in_array(mb_strtolower($a), self::SPECIAL, true) && str_contains($a, '@')));
    }
}
