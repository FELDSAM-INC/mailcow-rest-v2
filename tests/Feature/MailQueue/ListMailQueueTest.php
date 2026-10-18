<?php

namespace Tests\Feature\MailQueue;

use App\Clients\DockerApi\DockerCallResult;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T061 (US4 AS-1, AS-8, FR-037, FR-038, FR-043): the queued messages.
 */
class ListMailQueueTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
    }

    /**
     * @return list<string>
     */
    private function ids(string $query = ''): array
    {
        return array_column((array) $this->getJson('/api/v2/mail-queue/messages'.($query === '' ? '' : "?{$query}"))->assertOk()->json('data'), 'id');
    }

    public function test_the_deferred_messages_of_a_partner(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mail-queue/messages?queue=deferred&recipient=partner.example')->assertOk();

        $this->assertSame(['6B2C3D4E5F', '4F0A1C2B3D', '5A1B2C3D4E'], array_column((array) $response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 3);
        $this->assertSame('connect to mx.partner.example[192.0.2.10]:25: Connection timed out', $response->json('data.1.recipients.0.deferralReason'));
        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list']);
    }

    public function test_queue_counts_are_independent_of_filters(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages?queue=hold&limit=1')
            ->assertOk()
            ->assertJsonPath('meta.queueCounts', ['active' => 2, 'deferred' => 3, 'hold' => 2, 'incoming' => 1, 'maildrop' => 0, 'total' => 8])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.truncated', false);
    }

    public function test_sorting(): void
    {
        $this->asAdmin();

        $this->assertSame(['9E5F607182', 'AF60718293', '6B2C3D4E5F'], array_slice($this->ids(), 0, 3), 'oldest first by default');
        $this->assertSame('B071829304', $this->ids('order=desc')[0]);
        $this->assertSame(['6B2C3D4E5F', 'B071829304'], array_slice($this->ids('sort=size'), 0, 2));
        $this->assertSame('8D4E5F6071', $this->ids('sort=sender')[0], 'the empty bounce sender sorts first');
        $this->assertSame(['7C3D4E5F60', '8D4E5F6071'], array_slice($this->ids('sort=queue'), 0, 2), 'active first, ties by ID');
        $this->assertSame('4F0A1C2B3D', $this->ids('sort=id')[0]);
    }

    public function test_search_and_filters(): void
    {
        $this->asAdmin();

        $this->assertSame(['9E5F607182', 'AF60718293'], $this->ids('q=SPAMMER'));
        $this->assertSame(['6B2C3D4E5F'], $this->ids('q=451+4.7.1'));
        $this->assertSame(['5A1B2C3D4E'], $this->ids('q=5a1b2c'));
        $this->assertSame(['7C3D4E5F60'], $this->ids('sender=newsletter&queue=active'));
        $this->assertSame(['9E5F607182', 'AF60718293', '6B2C3D4E5F', '4F0A1C2B3D', '5A1B2C3D4E', 'B071829304'], $this->ids('sender=spammer&sender=ALICE&sender=carol'));
        $this->assertSame(['4F0A1C2B3D', '5A1B2C3D4E'], $this->ids('from=2025-10-18T10:00:00Z&to=2025-10-18T10:01:40Z'));
    }

    public function test_an_invalid_queue_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $errors = (array) $this->getJson('/api/v2/mail-queue/messages?queue=bounce')->assertStatus(422)->json('errors');
        $this->assertSame('notInList', $errors['queue.0'][0]['code'] ?? null);
    }

    public function test_a_stopped_postfix_container_is_refused_without_exec(): void
    {
        $this->dockerApi()->setRunning('postfix-mailcow', false);
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages')
            ->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/mail-service-not-running')
            ->assertJsonPath('container', 'postfix-mailcow');
        $this->dockerApi()->assertNotExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list']);
    }

    public function test_a_postfix_container_that_stopped_since_the_snapshot_is_refused(): void
    {
        $this->asAdmin();
        $this->getJson('/api/v2/containers')->assertOk();
        $this->dockerApi()->setRunning('postfix-mailcow', false);

        $this->getJson('/api/v2/mail-queue/messages')->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/mail-service-not-running');
    }

    public function test_a_postfix_failure_is_a_queue_action_failure(): void
    {
        $this->dockerApi()->withExec('postfix-mailcow', 'mailq', 'list', DockerCallResult::failed('postqueue: fatal: Queue report unavailable - mail system is down'));
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages')
            ->assertStatus(502)
            ->assertJsonPath('type', '/api/v2/problems/queue-action-failed')
            ->assertJsonPath('serviceMessage', 'postqueue: fatal: Queue report unavailable - mail system is down');
    }

    public function test_an_unreachable_container_service_is_a_temporary_failure(): void
    {
        $this->dockerApi()->withExec('postfix-mailcow', 'mailq', 'list', DockerCallResult::unreachable('connection refused'));
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages')->assertStatus(503);
    }

    public function test_a_large_queue_is_truncated(): void
    {
        $this->dockerApi()->withQueue(MonitoringFixtures::largeQueue(12000));
        $this->asAdmin();

        $this->getJson('/api/v2/mail-queue/messages?limit=1')
            ->assertOk()
            ->assertJsonPath('meta.truncated', true)
            ->assertJsonPath('meta.total', 10000)
            ->assertJsonPath('meta.queueCounts.total', 12000);
    }
}
