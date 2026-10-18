<?php

namespace Tests\Unit\Monitoring;

use App\Services\Monitoring\DfOutputParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 011 T025 (FR-007, research R8): `df -H` output of dockerapi's `system df` task.
 */
class DfOutputParserTest extends TestCase
{
    public function test_a_line_is_converted_to_approximate_bytes(): void
    {
        $usage = (new DfOutputParser)->parse('/dev/sda1,107G,27G,76G,26%,/var/vmail');

        $this->assertSame([
            'measured' => true,
            'device' => '/dev/sda1',
            'totalBytes' => 107000000000,
            'usedBytes' => 27000000000,
            'availableBytes' => 76000000000,
            'usedPercent' => 26,
            'approximate' => true,
        ], $usage);
    }

    public function test_a_hundred_gigabyte_disk_with_a_quarter_used(): void
    {
        $usage = (new DfOutputParser)->parse('/dev/vdb,100G,25G,75G,25%,/var/vmail');

        $this->assertSame(100000000000, $usage['totalBytes']);
        $this->assertSame(25000000000, $usage['usedBytes']);
        $this->assertSame(25, $usage['usedPercent']);
    }

    public function test_a_wrapped_line_has_no_device(): void
    {
        $usage = (new DfOutputParser)->parse(',1.1T,250G,800G,24%,/var/vmail');

        $this->assertTrue($usage['measured']);
        $this->assertNull($usage['device']);
        $this->assertSame(1100000000000, $usage['totalBytes']);
        $this->assertSame(250000000000, $usage['usedBytes']);
        $this->assertSame(800000000000, $usage['availableBytes']);
    }

    public function test_decimal_and_small_suffixes(): void
    {
        $usage = (new DfOutputParser)->parse('overlay,4.2M,512k,3.7M,13%,/var/vmail');

        $this->assertSame(4200000, $usage['totalBytes']);
        $this->assertSame(512000, $usage['usedBytes']);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function failures(): array
    {
        return [
            'failure answer' => ['0,0,0,0,0,0'],
            'null' => [null],
            'garbage' => ['df: /var/vmail: No such file or directory'],
            'empty' => [''],
            'bad size' => ['/dev/sda1,lots,27G,76G,26%,/var/vmail'],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failed_measurement_is_unavailable_never_zeros(?string $line): void
    {
        $this->assertSame([
            'measured' => false,
            'device' => null,
            'totalBytes' => null,
            'usedBytes' => null,
            'availableBytes' => null,
            'usedPercent' => null,
            'approximate' => false,
        ], (new DfOutputParser)->parse($line));
    }
}
