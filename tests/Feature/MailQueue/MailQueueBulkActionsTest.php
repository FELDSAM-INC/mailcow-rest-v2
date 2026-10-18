<?php

namespace Tests\Feature\MailQueue;

use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T063 (US4 AS-3, AS-4, AS-7, FR-040, FR-042, research R17): deliver, hold, release
 * and delete for up to 1,000 queue IDs, with one result per distinct ID.
 */
class MailQueueBulkActionsTest extends TestCase
{
    use MonitoringTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dockerApi()->withQueue(MonitoringFixtures::text('postqueue-j.txt'));
    }

    /**
     * @param  list<string>  $ids
     * @return TestResponse<Response>
     */
    private function bulk(string $action, array $ids): TestResponse
    {
        return $this->postJson('/api/v2/mail-queue/messages/bulk-actions', [
            'action' => $action,
            'targets' => array_map(fn (string $id): array => ['id' => $id], $ids),
        ], $this->csrf());
    }

    private function queueOf(string $id): ?string
    {
        foreach ($this->dockerApi()->queue() as $message) {
            if ($message['queue_id'] === $id) {
                return $message['queue_name'];
            }
        }

        return null;
    }

    public function test_hold_reports_done_and_not_found_per_id(): void
    {
        $this->asAdmin();

        $response = $this->bulk('hold', ['4F0A1C2B3D', '5A1B2C3D4E', 'DEADBEEF00', '7C3D4E5F60'])->assertOk();

        $response->assertJsonPath('data.results', [
            ['id' => '4F0A1C2B3D', 'outcome' => 'done'],
            ['id' => '5A1B2C3D4E', 'outcome' => 'done'],
            ['id' => 'DEADBEEF00', 'outcome' => 'notFound'],
            ['id' => '7C3D4E5F60', 'outcome' => 'done'],
        ]);
        $response->assertJsonPath('data.summary', ['total' => 4, 'done' => 3, 'notFound' => 1, 'refused' => 0, 'outdated' => 0, 'failed' => 0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $response->json('data.bulkRequestId'));
        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'hold', 'items' => ['4F0A1C2B3D', '5A1B2C3D4E', '7C3D4E5F60']]);
        $this->assertSame('hold', $this->queueOf('4F0A1C2B3D'));
    }

    public function test_release_deliver_and_delete_send_their_postfix_tasks(): void
    {
        $this->asAdmin();

        $this->bulk('release', ['9E5F607182'])->assertOk();
        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'unhold', 'items' => ['9E5F607182']]);
        $this->assertSame('deferred', $this->queueOf('9E5F607182'));

        $this->bulk('deliver', ['4F0A1C2B3D'])->assertOk();
        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'deliver', 'items' => ['4F0A1C2B3D']]);

        $this->bulk('delete', ['AF60718293'])->assertOk();
        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'delete', 'items' => ['AF60718293']]);
        $this->assertNull($this->queueOf('AF60718293'));
    }

    public function test_duplicates_are_processed_once_in_request_order(): void
    {
        $this->asAdmin();

        $this->bulk('hold', ['5A1B2C3D4E', '4F0A1C2B3D', '5A1B2C3D4E'])
            ->assertOk()
            ->assertJsonPath('data.results', [['id' => '5A1B2C3D4E', 'outcome' => 'done'], ['id' => '4F0A1C2B3D', 'outcome' => 'done']]);
        $this->dockerApi()->assertExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'hold', 'items' => ['5A1B2C3D4E', '4F0A1C2B3D']]);
    }

    public function test_no_present_id_sends_no_command(): void
    {
        $this->asAdmin();

        $this->bulk('delete', ['DEADBEEF00'])->assertOk()->assertJsonPath('data.summary.notFound', 1);
        $this->assertSame([['postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list']]], $this->dockerApi()->commands(), 'only the membership listing was sent');
    }

    public function test_an_id_beyond_the_listing_limit_is_found(): void
    {
        $this->dockerApi()->withQueue(MonitoringFixtures::largeQueue(12000));
        $this->asAdmin();

        $this->bulk('hold', [sprintf('A%05X', 11000)])->assertOk()->assertJsonPath('data.results.0.outcome', 'done');
    }

    public function test_invalid_too_many_ids_are_refused_and_audited(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $ids = array_map(fn (int $i): string => sprintf('%010X', $i), range(1, 1001));
        $this->bulk('delete', $ids)
            ->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany')
            ->assertJsonPath('errors.targets.0.params.max', 1000);

        $record = $this->onlyAuditRecord('mailQueueDeletion');
        $this->assertSame('validationFailed', $record->failure_reason);
        $this->assertCount(1000, $this->details($record)['itemIds']);
        $this->assertSame([], array_values(array_filter($this->dockerApi()->calls(), fn (array $c): bool => $c['command'] !== null)));
    }

    public function test_an_invalid_non_hexadecimal_id_is_refused_and_named(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $errors = (array) $this->bulk('hold', ['4F0A1C2B3D', 'XYZ'])->assertStatus(422)->json('errors');

        $this->assertSame('invalidFormat', $errors['targets.1.id'][0]['code'] ?? null);
        $this->assertSame('queueId', $errors['targets.1.id'][0]['params']['format'] ?? null);
        $record = $this->onlyAuditRecord('mailQueueHold');
        $this->assertSame(['4F0A1C2B3D'], $this->details($record)['itemIds']);
        $this->dockerApi()->assertNotExecuted('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list']);
    }

    public function test_an_invalid_action_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->bulk('requeue', ['4F0A1C2B3D'])->assertStatus(422)->assertJsonPath('errors.action.0.code', 'notInList');
        $this->assertSame('validationFailed', $this->onlyAuditRecord()->failure_reason);
    }

    public function test_an_invalid_target_with_a_version_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $errors = (array) $this->postJson('/api/v2/mail-queue/messages/bulk-actions', ['action' => 'hold', 'targets' => [['id' => '4F0A1C2B3D', 'version' => '"abc"']]], $this->csrf())
            ->assertStatus(422)
            ->json('errors');
        $this->assertSame('unknownField', $errors['targets.0.version'][0]['code'] ?? null);
    }

    public function test_a_postfix_failure_fails_the_whole_request(): void
    {
        $this->dockerApi()->withExec('postfix-mailcow', 'mailq', 'hold', DockerCallResult::failed('command failed: postsuper: fatal: queue_directory'));
        $this->asAdmin();

        $this->bulk('hold', ['4F0A1C2B3D'])
            ->assertStatus(502)
            ->assertJsonPath('type', '/api/v2/problems/queue-action-failed')
            ->assertJsonPath('serviceMessage', 'command failed: postsuper: fatal: queue_directory');
    }

    public function test_a_timeout_has_an_unknown_outcome(): void
    {
        $this->dockerApi()->withExec('postfix-mailcow', 'mailq', 'hold', DockerCallResult::unknown('timed out'));
        $this->asAdmin();

        $this->bulk('hold', ['4F0A1C2B3D'])->assertStatus(504)->assertJsonPath('type', '/api/v2/problems/outcome-unknown')->assertJsonPath('step', 'hold');
    }

    public function test_a_stopped_postfix_container_is_refused(): void
    {
        $this->dockerApi()->setRunning('postfix-mailcow', false);
        $this->asAdmin();

        $this->bulk('hold', ['4F0A1C2B3D'])->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/mail-service-not-running');
    }
}
