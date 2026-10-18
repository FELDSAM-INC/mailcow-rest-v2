<?php

namespace App\Clients\Pushover;

/**
 * Pushover's credential validation (`users/validate`, feature 005 FR-057, research R14), as legacy
 * pushover('test') calls it: no notification is sent. Faked in tests.
 */
interface PushoverClient
{
    public function validate(string $token, string $user): PushoverVerdict;
}
