<?php

namespace App\Rules;

/**
 * A Keycloak version: one to four whole numbers separated by dots, for example `26.0.7` (feature
 * 012 FR-010, format `keycloakVersion`).
 */
class KeycloakVersion extends CodedRule
{
    protected function check(mixed $value): ?array
    {
        return is_string($value) && preg_match('/^[0-9]+(\.[0-9]+){0,3}$/', $value) === 1
            ? null
            : $this->violation('invalidFormat', ['format' => 'keycloakVersion'], 'Must be one to four whole numbers separated by dots, for example 26.0.7.');
    }
}
