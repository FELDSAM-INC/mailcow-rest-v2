<?php

namespace Tests\Unit\MtaSts;

use App\Services\MtaSts\MtaStsPolicyId;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Dns\FakeClock;

/**
 * Feature 010 T071 (FR-035, research R14): a policy identifier is `YmdHis` in mailcow's time zone,
 * as legacy writes it; when that is not greater than the previous identifier, it is the previous
 * identifier's time plus one second, so that two changes within one second still differ.
 */
class MtaStsPolicyIdTest extends TestCase
{
    private static function at(string $utc): FakeClock
    {
        return new FakeClock(CarbonImmutable::parse($utc, 'UTC'));
    }

    public function test_the_identifier_is_the_local_time(): void
    {
        $this->assertSame('20261019013000', MtaStsPolicyId::next(self::at('2026-10-18 23:30:00'), 'Europe/Prague'));
        $this->assertSame('20261018233000', MtaStsPolicyId::next(self::at('2026-10-18 23:30:00'), 'UTC'));
    }

    public function test_a_later_time_than_the_previous_identifier_is_used_as_is(): void
    {
        $this->assertSame('20261019013000', MtaStsPolicyId::next(self::at('2026-10-18 23:30:00'), 'Europe/Prague', '20261019012959'));
    }

    public function test_an_identifier_not_greater_than_the_previous_one_is_one_second_later(): void
    {
        $clock = self::at('2026-10-18 23:30:00');

        $this->assertSame('20261019013001', MtaStsPolicyId::next($clock, 'Europe/Prague', '20261019013000'));
        $this->assertSame('20261019020000', MtaStsPolicyId::next($clock, 'Europe/Prague', '20261019015959'));
        $this->assertSame('20261020000000', MtaStsPolicyId::next($clock, 'Europe/Prague', '20261019235959'));
        $this->assertSame('20270101000000', MtaStsPolicyId::next($clock, 'Europe/Prague', '20261231235959'));
    }

    public function test_the_identifier_always_has_14_digits(): void
    {
        $this->assertMatchesRegularExpression('/^\d{14}$/', MtaStsPolicyId::next(self::at('2026-01-02 03:04:05'), 'UTC', 'garbage'));
        $this->assertMatchesRegularExpression('/^\d{14}$/', MtaStsPolicyId::next(self::at('2026-01-02 03:04:05'), 'UTC', '12'));
    }
}
