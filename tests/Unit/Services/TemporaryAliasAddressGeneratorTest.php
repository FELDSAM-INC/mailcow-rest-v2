<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProblemException;
use App\Services\TemporaryAliases\TemporaryAliasAddressGenerator;
use PHPUnit\Framework\TestCase;

/**
 * T079 (feature 005 FR-044): temporary alias addresses are two readable parts of 6 to 8 letters
 * alternating consonants and vowels, drawn from a secure source, retried while taken.
 */
class TemporaryAliasAddressGeneratorTest extends TestCase
{
    public function test_local_parts_have_the_readable_form(): void
    {
        $generator = new TemporaryAliasAddressGenerator;

        for ($i = 0; $i < 1000; $i++) {
            $local = $generator->localPart();
            $this->assertMatchesRegularExpression('/^[a-z]{6,8}\.[a-z]{6,8}$/', $local);

            foreach (explode('.', $local) as $part) {
                $this->assertGreaterThanOrEqual(6, strlen($part));
                $this->assertLessThanOrEqual(8, strlen($part));
                $this->assertMatchesRegularExpression('/^(?:[bcdfghjklmnprstvwxyz][aeiou])+[bcdfghjklmnprstvwxyz]?$/', $part);
            }
        }
    }

    public function test_taken_candidates_are_skipped(): void
    {
        $generator = new TemporaryAliasAddressGenerator;
        $tried = [];

        $address = $generator->generate('example.org', function (string $candidate) use (&$tried): bool {
            $tried[] = $candidate;

            return count($tried) === 3;
        });

        $this->assertCount(3, $tried);
        $this->assertSame($tried[2], $address);
        $this->assertStringEndsWith('@example.org', $address);
    }

    public function test_ten_taken_candidates_are_a_temporary_failure(): void
    {
        $generator = new TemporaryAliasAddressGenerator;

        try {
            $generator->generate('example.org', fn (): bool => false);
            $this->fail('A temporary failure was expected.');
        } catch (ProblemException $e) {
            $this->assertSame('temporary-failure', $e->type->value);
            $this->assertSame(['dependency' => 'addressGeneration'], $e->extensions);
        }
    }

    public function test_the_random_source_drives_the_address(): void
    {
        $generator = new TemporaryAliasAddressGenerator(fn (int $min, int $max): int => $min);

        $this->assertSame('bababa.bababa', $generator->localPart());
    }
}
