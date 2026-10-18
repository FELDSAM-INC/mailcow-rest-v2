<?php

namespace App\Services\Logs;

/**
 * Masks secrets in legacy log data by member name (feature 011 research R15, FR-028): the value of
 * every member whose name contains `pass`, `secret`, `private` or `token` (case-insensitive), or
 * whose name is `key`, becomes `*`, whatever its type (a nested object is replaced as a whole), at
 * any depth. List elements have no name and are only descended into. Legacy masked only names
 * matching `pass`, so DKIM private keys, client secrets and Pushover tokens reached its logs.
 */
class SecretMasker
{
    public const PATTERN = '/pass|secret|private|token/i';

    public function mask(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->mask(...), $value);
        }

        $masked = [];

        foreach ($value as $name => $member) {
            $masked[$name] = self::isSecretName((string) $name) ? '*' : $this->mask($member);
        }

        return $masked;
    }

    public static function isSecretName(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1 || strtolower($name) === 'key';
    }
}
