<?php

namespace Tests\Feature\Logs;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T051 (US3 AS-7, FR-003): a log type that does not exist is "not found".
 */
class UnknownLogTypeTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_unknown_log_types_are_not_found(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/logs/rspamd')->assertNotFound()->assertJsonPath('type', 'about:blank');
        $this->getJson('/api/v2/logs/unknown')->assertNotFound();
    }
}
