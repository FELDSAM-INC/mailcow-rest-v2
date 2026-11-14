<?php

namespace App\Services\Routing;

use App\Support\Http\FieldViolations;
use App\Support\Routing\NextHop;

/** A stored SMTP password belongs to its existing destination and username. */
final class SmtpCredentialRetention
{
    public static function validate(
        string $oldHop,
        string $newHop,
        string $oldUsername,
        string $newUsername,
        string $password,
        bool $passwordSupplied,
        FieldViolations $violations,
    ): void {
        if ($passwordSupplied || $password === '' || $newUsername === '') {
            return;
        }

        if ($oldUsername !== $newUsername || ! self::sameDestination($oldHop, $newHop)) {
            $violations->add('password', 'required', ['because' => 'connectionChanged'],
                'Supply or explicitly remove the password when changing its SMTP destination or username.');
        }
    }

    private static function sameDestination(string $old, string $new): bool
    {
        if (strcasecmp(trim($old), trim($new)) === 0) {
            return true;
        }

        $before = NextHop::tryParse($old);
        $after = NextHop::tryParse($new);

        return $before !== null && $after !== null
            && $before->host() === $after->host()
            && ($before->port() ?? 25) === ($after->port() ?? 25)
            && $before->bracketed() === $after->bracketed();
    }
}
