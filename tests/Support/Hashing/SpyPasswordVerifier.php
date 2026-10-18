<?php

namespace Tests\Support\Hashing;

use App\Support\Hashing\LegacyPasswordVerifier;

/**
 * Records every hash the sign-in verifies (never the password).
 */
final class SpyPasswordVerifier extends LegacyPasswordVerifier
{
    /** @var list<string> */
    public array $verifiedHashes = [];

    public function verify(string $hash, string $password): bool
    {
        $this->verifiedHashes[] = $hash;

        return parent::verify($hash, $password);
    }
}
