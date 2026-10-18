<?php

namespace App\Rules;

/**
 * A Keycloak realm name: 1 to 255 characters without `/` (feature 012 FR-010, format
 * `keycloakRealm`).
 */
class KeycloakRealm extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        if (mb_strlen($value) > 255) {
            return $this->violation('tooLong', ['max' => 255]);
        }

        return $value !== '' && ! str_contains($value, '/') ? null : $this->violation('invalidFormat', ['format' => 'keycloakRealm'], 'Must be 1 to 255 characters without "/".');
    }
}
