<?php

namespace Tests\Feature\Monitoring;

use App\Clients\DockerApi\DockerCallResult;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T025 (US1 AS-2, FR-007, research R8): the usage of the disk holding the mail storage.
 */
class MailStorageUsageTest extends TestCase
{
    use MonitoringTestHelpers;

    private const DF = ['cmd' => 'system', 'task' => 'df', 'dir' => '/var/vmail'];

    public function test_the_usage_is_measured_in_the_dovecot_container(): void
    {
        $this->dockerApi()->withExec('dovecot-mailcow', 'system', 'df', MonitoringFixtures::df('df-ok.txt'));
        $this->asAdmin();

        $data = $this->getJson('/api/v2/host/mail-storage')->assertOk()->json('data');

        $this->assertTrue($data['measured']);
        $this->assertSame('/dev/sda1', $data['device']);
        $this->assertSame(107000000000, $data['totalBytes']);
        $this->assertSame(26, $data['usedPercent']);
        $this->assertTrue($data['approximate']);
        $this->assertNotNull($data['collectedAt']);
        $this->dockerApi()->assertExecuted('dovecot-mailcow', self::DF);
    }

    public function test_a_failed_measurement_is_reported_unavailable(): void
    {
        $this->dockerApi()->withExec('dovecot-mailcow', 'system', 'df', MonitoringFixtures::df('df-failed.txt'));
        $this->asAdmin();

        $data = $this->getJson('/api/v2/host/mail-storage')->assertOk()->json('data');

        $this->assertFalse($data['measured']);
        $this->assertNull($data['totalBytes']);
        $this->assertNull($data['usedPercent']);
    }

    public function test_a_null_answer_is_reported_unavailable(): void
    {
        $this->dockerApi()->withExec('dovecot-mailcow', 'system', 'df', null);
        $this->asAdmin();

        $this->getJson('/api/v2/host/mail-storage')->assertOk()->assertJsonPath('data.measured', false);
    }

    public function test_a_stopped_dovecot_container_is_reported_unavailable_without_exec(): void
    {
        $this->dockerApi()->setRunning('dovecot-mailcow', false);
        $this->asAdmin();

        $this->getJson('/api/v2/host/mail-storage')->assertOk()->assertJsonPath('data.measured', false);
        $this->dockerApi()->assertNotExecuted('dovecot-mailcow', self::DF);
    }

    public function test_the_usage_is_cached_for_sixty_seconds(): void
    {
        $this->dockerApi()->withExec('dovecot-mailcow', 'system', 'df', MonitoringFixtures::df('df-ok.txt'));
        $this->asAdmin();

        $this->getJson('/api/v2/host/mail-storage')->assertOk();
        $this->travel(59)->seconds();
        $this->getJson('/api/v2/host/mail-storage')->assertOk();
        $this->dockerApi()->assertExecuted('dovecot-mailcow', self::DF, 1);

        $this->travel(2)->seconds();
        $this->getJson('/api/v2/host/mail-storage')->assertOk();
        $this->dockerApi()->assertExecuted('dovecot-mailcow', self::DF, 2);
    }

    public function test_an_unavailable_container_service_is_a_temporary_failure(): void
    {
        $this->dockerApi()->withExec('dovecot-mailcow', 'system', 'df', DockerCallResult::unreachable('connection refused'));
        $this->asAdmin();

        $this->getJson('/api/v2/host/mail-storage')
            ->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }

    public function test_a_container_list_failure_is_a_temporary_failure(): void
    {
        $this->dockerApi()->failNext('containers', 'unreachable');
        $this->asAdmin();

        $this->getJson('/api/v2/host/mail-storage')->assertStatus(503);
    }
}
