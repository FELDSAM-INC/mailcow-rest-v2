<?php

namespace Tests\Feature\MailQueue;

use App\Clients\DockerApi\DockerCallResult;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T064 (US4 AS-5, AS-6, FR-041, FR-042): flushing the queue and deleting all queued
 * messages.
 */
class MailQueueFlushPurgeTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
    }

    public function test_flush_asks_postfix_to_deliver_and_keeps_held_messages(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/mail-queue/flush', $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.action', 'flush')
            ->assertJsonStructure(['data' => ['completedAt']]);

        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'flush']);
        $this->assertCount(2, array_filter($this->dockerApi()->queue(), fn (array $m): bool => $m['queue_name'] === 'hold'));
    }

    public function test_purge_empties_every_queue(): void
    {
        $this->asAdmin();

        $this->postEmpty('/api/v2/mail-queue/purge', $this->csrf())->assertOk()->assertJsonPath('data.action', 'purge');

        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'super_delete']);
        $this->assertSame([], $this->dockerApi()->queue());
    }

    public function test_failures_and_timeouts(): void
    {
        $this->dockerApi()->withExec('postfix-mailcow', 'mailq', 'flush', DockerCallResult::failed('command failed: postqueue: fatal: Cannot flush'));
        $this->dockerApi()->withExec('postfix-mailcow', 'mailq', 'super_delete', DockerCallResult::unknown('timed out'));
        $this->asAdmin();

        $this->postEmpty('/api/v2/mail-queue/flush', $this->csrf())->assertStatus(502)->assertJsonPath('serviceMessage', 'command failed: postqueue: fatal: Cannot flush');
        $this->postEmpty('/api/v2/mail-queue/purge', $this->csrf())->assertStatus(504)->assertJsonPath('step', 'purge');
    }

    public function test_a_stopped_postfix_container_is_refused(): void
    {
        $this->dockerApi()->setRunning('postfix-mailcow', false);
        $this->asAdmin();

        $this->postEmpty('/api/v2/mail-queue/purge', $this->csrf())->assertStatus(409);
        $this->dockerApi()->assertNotExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'super_delete']);
    }
}
