<?php

namespace Tests\Feature\Rspamd;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T092 (US6 AS-3, FR-035): Rspamd's statistics, documented fields only.
 */
class RspamdStatisticsTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_the_documented_fields(): void
    {
        $this->asAdmin();

        $data = $this->getJson('/api/v2/rspamd/statistics')->assertOk()->json('data');

        $this->assertSame(12345, $data['scanned']);
        $this->assertSame(67, $data['learned']);
        $this->assertSame(300, $data['spamCount']);
        $this->assertSame(12045, $data['hamCount']);
        $this->assertSame(['reject' => 100, 'soft reject' => 5, 'rewrite subject' => 0, 'add header' => 200, 'greylist' => 50, 'no action' => 11990], $data['actions']);
        $this->assertSame(13000, $data['connections']);
        $this->assertSame(42, $data['controlConnections']);
        $this->assertSame([['symbol' => 'BAYES_SPAM', 'type' => 'redis', 'revision' => 40, 'users' => 1], ['symbol' => 'BAYES_HAM', 'type' => 'redis', 'revision' => 27, 'users' => 1]], $data['statfiles']);
        $this->assertSame(['local' => 1234, 'rspamd.com' => 5678], $data['fuzzyHashes']);
        $this->assertArrayNotHasKey('pools_allocated', $data);
        $this->assertArrayNotHasKey('poolsAllocated', $data);
        $this->assertNotNull($data['collectedAt']);
    }

    public function test_missing_numbers_are_zero(): void
    {
        $this->rspamd()->withStat(['scanned' => 3]);
        $this->asAdmin();

        $this->getJson('/api/v2/rspamd/statistics')
            ->assertOk()
            ->assertJsonPath('data.scanned', 3)
            ->assertJsonPath('data.learned', 0)
            ->assertJsonPath('data.statfiles', [])
            ->assertJsonPath('data.actions', []);
    }
}
