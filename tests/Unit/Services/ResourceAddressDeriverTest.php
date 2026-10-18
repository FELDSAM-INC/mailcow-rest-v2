<?php

namespace Tests\Unit\Services;

use App\Services\Resources\ResourceAddressDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Feature 004 T009 (FR-048, FR-049, research R13): a resource's local part is the ASCII letters and
 * digits of its description, letter case kept, exactly as legacy derives it; it must have 1 to 64
 * characters.
 */
class ResourceAddressDeriverTest extends TestCase
{
    public function test_the_local_part_keeps_ascii_letters_and_digits(): void
    {
        $this->assertSame('MeetingRoom1', ResourceAddressDeriver::localPart('Meeting Room 1'));
        $this->assertSame('MeetingRoom1', ResourceAddressDeriver::localPart('Meeting-Room 1'));
        $this->assertSame('RaumSd', ResourceAddressDeriver::localPart('Raum Süd'));
        $this->assertSame('', ResourceAddressDeriver::localPart('!!!'));
    }

    public function test_the_local_part_has_1_to_64_characters(): void
    {
        $this->assertFalse(ResourceAddressDeriver::acceptable(''));
        $this->assertTrue(ResourceAddressDeriver::acceptable(str_repeat('a', 64)));
        $this->assertFalse(ResourceAddressDeriver::acceptable(str_repeat('a', 65)));
        $this->assertFalse(ResourceAddressDeriver::acceptable(ResourceAddressDeriver::localPart('!!!')));
    }

    public function test_it_equals_legacys_expression(): void
    {
        $alphabet = "abcXYZ019 -_.!?\\/@#\$%^&*()[]{}äöüßéçłøÅ€\t\"'";
        mt_srand(4);

        for ($i = 0; $i < 50; $i++) {
            $description = '';

            for ($j = mt_rand(1, 40); $j > 0; $j--) {
                $description .= mb_substr($alphabet, mt_rand(0, mb_strlen($alphabet) - 1), 1);
            }

            $legacy = preg_replace('/[^\da-z]/i', '', preg_quote($description, '/'));
            $this->assertSame($legacy, ResourceAddressDeriver::localPart($description), $description);
        }
    }
}
