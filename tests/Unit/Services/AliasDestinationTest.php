<?php

namespace Tests\Unit\Services;

use App\Services\Aliases\AliasDestination;
use PHPUnit\Framework\TestCase;

/**
 * Feature 004 T007 (FR-012, FR-025, FR-026, research R4): the stored `goto` maps to a special action
 * or to the destination list as stored; duplicates that differ in letter case are merged keeping
 * the first spelling; added destinations are compared case-insensitively; the joined list may have
 * 65,535 characters.
 */
class AliasDestinationTest extends TestCase
{
    public function test_reserved_addresses_are_special_actions(): void
    {
        $this->assertSame('discard', AliasDestination::fromGoto('null@localhost')->type);
        $this->assertSame('learnSpam', AliasDestination::fromGoto('spam@localhost')->type);
        $this->assertSame('learnHam', AliasDestination::fromGoto('ham@localhost')->type);
        $this->assertSame([], AliasDestination::fromGoto('spam@localhost')->addresses);
        $this->assertSame('null@localhost', AliasDestination::special('discard')->toGoto());
        $this->assertSame('spam@localhost', AliasDestination::special('learnSpam')->toGoto());
        $this->assertSame('ham@localhost', AliasDestination::special('learnHam')->toGoto());
    }

    public function test_other_destinations_are_listed_exactly_as_stored(): void
    {
        $destination = AliasDestination::fromGoto('Anna@example.org,not an address,spam@localhost');

        $this->assertSame('addresses', $destination->type);
        $this->assertSame(['Anna@example.org', 'not an address', 'spam@localhost'], $destination->addresses);
        $this->assertSame('Anna@example.org,not an address,spam@localhost', $destination->toGoto());
        $this->assertSame([], AliasDestination::fromGoto('')->addresses);
    }

    public function test_letter_case_duplicates_are_merged_keeping_the_first_spelling_and_order(): void
    {
        $destination = AliasDestination::addresses(['Bob@example.org', 'anna@example.org', 'bob@example.org', 'ANNA@example.org', 'carl@example.org']);

        $this->assertSame(['Bob@example.org', 'anna@example.org', 'carl@example.org'], $destination->addresses);
        $this->assertSame('Bob@example.org,anna@example.org,carl@example.org', $destination->toGoto());
    }

    public function test_added_destinations_are_those_not_stored_case_insensitively(): void
    {
        $stored = AliasDestination::fromGoto('anna@example.org,someone@gmail.example');
        $requested = AliasDestination::addresses(['Someone@gmail.example', 'bob@example.org', 'other@external.example']);

        $this->assertSame([1 => 'bob@example.org', 2 => 'other@external.example'], $requested->addedComparedTo($stored));
        $this->assertSame([0 => 'anna@example.org', 1 => 'someone@gmail.example'], $stored->addedComparedTo(AliasDestination::special('discard')));
    }

    public function test_the_joined_list_limit_is_65535_characters(): void
    {
        $this->assertSame(65535, AliasDestination::MAX_LENGTH);
        $fits = AliasDestination::addresses([str_repeat('a', 64).'@'.str_repeat('b', 65535 - 67).'.x']);
        $this->assertTrue($fits->fitsStorage());
        $tooLong = AliasDestination::addresses(array_map(fn (int $i): string => "user{$i}@".str_repeat('d', 200).'.example', range(1, 400)));
        $this->assertFalse($tooLong->fitsStorage());
    }
}
