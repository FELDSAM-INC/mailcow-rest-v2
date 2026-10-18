<?php

namespace App\Services\Resources;

/**
 * The local part of a resource's address, derived from its description exactly as legacy
 * mailbox('add', 'resource') derives it (feature 004 FR-048, research R13):
 * `preg_replace('/[^\da-z]/i', '', preg_quote($description, '/'))`, which keeps the ASCII letters
 * and digits in their letter case (the backslashes preg_quote adds are removed by the same
 * expression; a NUL byte becomes `000`, as in legacy). It is acceptable with 1 to 64 characters.
 */
final class ResourceAddressDeriver
{
    public const MAX_LOCAL_PART = 64;

    public static function localPart(string $description): string
    {
        return (string) preg_replace('/[^\da-z]/i', '', preg_quote($description, '/'));
    }

    public static function acceptable(string $localPart): bool
    {
        return $localPart !== '' && strlen($localPart) <= self::MAX_LOCAL_PART;
    }
}
