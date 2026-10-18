<?php

namespace Tests\Feature\Rspamd;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T093 (US6 AS-4, FR-036): an unreachable Rspamd controller is a temporary failure.
 */
class RspamdUnreachableTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_both_reads_fail_temporarily(): void
    {
        $this->rspamd()->unreachable();
        $this->asAdmin();

        foreach (['/api/v2/rspamd/history', '/api/v2/rspamd/statistics'] as $path) {
            $this->getJson($path)->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure')->assertHeader('Retry-After');
        }
    }
}
