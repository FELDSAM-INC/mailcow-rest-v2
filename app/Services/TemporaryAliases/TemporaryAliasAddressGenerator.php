<?php

namespace App\Services\TemporaryAliases;

use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Closure;

/**
 * The address of a new temporary alias (feature 005 FR-044, research R12): legacy's readable form,
 * two dot-separated parts of alternating consonants and vowels starting with a consonant, each now
 * 6 to 8 letters long and drawn from a cryptographically secure source (legacy: 2 to 8 letters from
 * rand()). The caller claims each candidate (checks that no mailbox, resource, alias, alias-domain
 * address or temporary alias has it, and inserts it, under the shared address lock); after ten
 * taken candidates the creation fails as a temporary failure.
 */
class TemporaryAliasAddressGenerator
{
    public const ATTEMPTS = 10;

    private const CONSONANTS = 'bcdfghjklmnprstvwxyz';

    private const VOWELS = 'aeiou';

    /** @var Closure(int, int): int */
    private readonly Closure $random;

    /**
     * @param  (Closure(int, int): int)|null  $random  random_int() unless a test seeds it
     */
    public function __construct(?Closure $random = null)
    {
        $this->random = $random ?? random_int(...);
    }

    /**
     * A new local part: `<part>.<part>`.
     */
    public function localPart(): string
    {
        return $this->part().'.'.$this->part();
    }

    /**
     * Generates candidates in $domain until $claim accepts one.
     *
     * @param  Closure(string): bool  $claim  claims a candidate address; false when it is taken
     *
     * @throws ProblemException temporary-failure (`dependency: addressGeneration`)
     */
    public function generate(string $domain, Closure $claim): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $address = $this->localPart().'@'.$domain;

            if ($claim($address)) {
                return $address;
            }
        }

        throw new ProblemException(ProblemType::TemporaryFailure, 'No free temporary alias address was found. Nothing was stored; retry.', ['dependency' => 'addressGeneration'], ['Retry-After' => '10']);
    }

    private function part(): string
    {
        $length = ($this->random)(6, 8);
        $part = '';

        for ($i = 0; $i < $length; $i++) {
            $alphabet = $i % 2 === 0 ? self::CONSONANTS : self::VOWELS;
            $part .= $alphabet[($this->random)(0, strlen($alphabet) - 1)];
        }

        return $part;
    }
}
