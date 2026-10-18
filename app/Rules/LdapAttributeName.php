<?php

namespace App\Rules;

/**
 * An LDAP attribute name: a letter followed by letters, digits or hyphens, at most 255 characters
 * (feature 012 FR-012, format `ldapAttribute`).
 */
class LdapAttributeName extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        if (strlen($value) > 255) {
            return $this->violation('tooLong', ['max' => 255]);
        }

        return preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $value) === 1
            ? null
            : $this->violation('invalidFormat', ['format' => 'ldapAttribute'], 'Must be a letter followed by letters, digits or hyphens.');
    }
}
