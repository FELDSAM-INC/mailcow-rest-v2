<?php

namespace Tests\Feature\Mailboxes;

use App\Audit\AuditEventType;
use App\Clients\DockerApi\DockerCallResult;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T059 (FR-040, FR-042, US4 AS-2): nothing is removed unless the mail store confirmed the
 * move to the garbage area; a timeout answers "outcome unknown" and a retry completes; cluster mode
 * broadcasts the move; a failure after the move keeps the rows and restores the shared store.
 */
class DeleteMailboxFailureTest extends TestCase
{
    use MailboxTestHelpers;

    private const CLEANUP = ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org/anna'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->mailcow()->resetToken('anna@example.org', '3F9A1C-0B7E22-9D4F10-A6C3E8-51B2D7');
        $this->redis()->hSet('RL_VALUE', 'anna@example.org', '10 / 1h');
    }

    private function assertNothingRemoved(): void
    {
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
        $this->assertTrue(DB::table('reset_password')->where('username', 'anna@example.org')->exists());
        $this->assertTrue(DB::table('alias')->where('address', 'anna@example.org')->exists());
        $this->assertSame('10 / 1h', $this->redis()->hGet('RL_VALUE', 'anna@example.org'));
    }

    public function test_a_failed_or_unreachable_mail_store_removes_nothing(): void
    {
        $this->asAdmin();

        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::failed('command failed: mv: cannot move'));
        $this->deleteMailbox('anna@example.org')->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'mailStore')
            ->assertHeader('Retry-After', '30');
        $this->assertNothingRemoved();

        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::unreachable('connection refused'));
        $this->deleteMailbox('anna@example.org')->assertStatus(503)->assertJsonPath('dependency', 'mailStore');
        $this->assertNothingRemoved();

        $records = $this->auditOf('mailboxDeletion');
        $this->assertCount(2, $records);
        $this->assertSame(['temporaryFailure', 'failed'], [$records[0]->failure_reason, $records[0]->details['mailStoreCleanup']]);
    }

    public function test_a_timeout_is_an_unknown_outcome_and_a_retry_completes(): void
    {
        $this->asAdmin();
        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::unknown('timed out'), DockerCallResult::success());

        $this->deleteMailbox('anna@example.org')->assertStatus(504)
            ->assertJsonPath('type', '/api/v2/problems/outcome-unknown')
            ->assertJsonPath('step', 'mailStoreCleanup');
        $this->assertNothingRemoved();
        $record = $this->auditOf('mailboxDeletion')[0];
        $this->assertSame(['outcomeUnknown', 'unknown'], [$record->failure_reason, $record->details['mailStoreCleanup']]);

        $this->deleteMailbox('anna@example.org')->assertNoContent();
        $this->assertNull($this->mailboxRow('anna@example.org'));
        $this->dockerApi()->assertExecuted('dovecot-mailcow', self::CLEANUP, 2);
    }

    public function test_an_unavailable_shared_store_refuses_before_the_mail_store_is_asked(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();
        $this->breakSharedStore();

        $this->deleteJson('/api/v2/mailboxes/anna@example.org', [], $csrf)->assertStatus(503)->assertJsonPath('dependency', 'sharedStore');
        $this->dockerApi()->assertNothingCalled();
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
    }

    public function test_cluster_mode_moves_on_this_node_and_broadcasts_to_every_node(): void
    {
        config(['restv2.cluster_mode' => 'replication']);
        $this->asAdmin();

        $this->deleteMailbox('anna@example.org')->assertNoContent();

        $this->dockerApi()->assertExecuted('dovecot-mailcow', self::CLEANUP);
        $this->dockerApi()->assertBroadcast('dovecot-mailcow', self::CLEANUP);
    }

    public function test_a_failure_after_the_move_keeps_the_rows_and_restores_the_shared_store(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
            {
                if ($success && $type === AuditEventType::MailboxDeletion) {
                    throw new \RuntimeException('audit storage failed');
                }

                return parent::record($type, $success, $actor, $target, $authMethod, $failureReason, $details);
            }
        });
        $this->asAdmin();

        $this->deleteMailbox('anna@example.org')->assertStatus(500);

        $this->assertNothingRemoved();
        $record = $this->auditOf('mailboxDeletion')[0];
        $this->assertSame(['failure', 'done'], [$record->outcome, $record->details['mailStoreCleanup']]);
    }
}
