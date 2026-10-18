<?php

namespace Tests\Feature\Mailboxes;

use App\Audit\AuditEventType;
use App\Clients\DockerApi\DockerCallResult;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\SogoDirectory;
use App\Services\Mailboxes\MailboxDataMover;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T081 (FR-048, US8 AS-4): a failing step undoes the steps already done (`503` with the
 * steps), a failing undo leaves a rename incomplete (`500`), a step without an answer is "outcome
 * unknown" (`504`), and each outcome has exactly one audit record.
 */
class RenameMailboxRollbackTest extends TestCase
{
    use MailboxTestHelpers;

    private const OLD = 'anna@example.org';

    private const NEW = 'anna.smith@example.org';

    private const ACL = [
        ['user' => self::OLD, 'id' => 'bob@example.org', 'mailbox' => 'Projects', 'rights' => ['lookup', 'read']],
    ];

    private const STEPS = ['sharedFolderPermissionsRemoval', 'sharedStoreMove', 'database', 'mailStoreMove', 'webmailRename', 'sharedFolderPermissionsRecreation'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox(self::OLD, tags: ['Sales']);
        $this->mailcow()->tfaTotp(self::OLD, 'JBSWY3DPEHPK3PXP');
        $this->redis()->hSet('RL_VALUE', self::OLD, '10 / 1h');
        $this->dockerApi()->aclList(self::ACL);
    }

    /**
     * @return TestResponse<Response>
     */
    private function rename(bool $keep = true): TestResponse
    {
        return $this->postJson('/api/v2/mailboxes/'.self::OLD.'/rename', ['newLocalPart' => 'anna.smith', 'keepOldAddressAsAlias' => $keep], $this->csrf());
    }

    /**
     * @param  list<array{step: string, outcome: string}>  $steps
     * @return array<string, string>
     */
    private static function outcomes(array $steps): array
    {
        return array_combine(array_column($steps, 'step'), array_column($steps, 'outcome'));
    }

    private function assertStateRestored(): void
    {
        $this->assertNotNull($this->mailboxRow(self::OLD));
        $this->assertNull($this->mailboxRow(self::NEW));
        $this->assertSame(self::OLD, DB::table('alias')->where('address', self::OLD)->value('goto'));
        $this->assertSame(1, DB::table('tfa')->where('username', self::OLD)->count());
        $this->assertSame(['Sales'], $this->mailboxTags(self::OLD));
        $this->assertSame('10 / 1h', $this->redis()->hGet('RL_VALUE', self::OLD));
        $this->assertFalse($this->redis()->hExists('RL_VALUE', self::NEW));
    }

    public function test_a_failure_of_each_container_step_undoes_the_steps_done(): void
    {
        $failures = [
            'sharedFolderPermissionsRemoval' => ['dovecot-mailcow', 'doveadm', 'delete_acl', 'mailStore'],
            'mailStoreMove' => ['dovecot-mailcow', 'maildir', 'move', 'mailStore'],
            'webmailRename' => ['sogo-mailcow', 'sogo', 'rename_user', 'webmail'],
            'sharedFolderPermissionsRecreation' => ['dovecot-mailcow', 'doveadm', 'set_acl', 'mailStore'],
        ];
        $this->asAdmin();

        foreach ($failures as $step => [$service, $cmd, $task, $dependency]) {
            $this->dockerApi()->script($service, $cmd, $task, DockerCallResult::failed('boom'), DockerCallResult::success());

            $problem = $this->rename()->assertStatus(503)->assertJsonPath('dependency', $dependency)->json();
            $outcomes = self::outcomes($problem['steps']);
            $index = array_search($step, self::STEPS, true);

            foreach (self::STEPS as $i => $name) {
                $expected = $i < $index ? 'undone' : ($i === $index ? 'failed' : 'notDone');
                $this->assertSame($expected, $outcomes[$name], "{$step}: {$name}");
            }

            $this->assertStateRestored();
            $this->dockerApi()->script($service, $cmd, $task, DockerCallResult::success());
        }

        $this->dockerApi()->assertExecuted('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => 'example.org/anna.smith', 'new_maildir' => 'example.org/anna'], 2);
        $this->dockerApi()->assertExecuted('sogo-mailcow', ['cmd' => 'sogo', 'task' => 'rename_user', 'old_username' => self::NEW, 'new_username' => self::OLD], 1);
        $this->dockerApi()->assertExecuted('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'set_acl', 'user' => self::OLD, 'mailbox' => 'Projects', 'id' => 'bob@example.org', 'rights' => ['lookup', 'read']], 3);

        $records = $this->auditOf('mailboxRename');
        $this->assertCount(4, $records);
        $this->assertSame(['temporaryFailure'], array_values(array_unique(array_column($records, 'failure_reason'))));
        $this->assertCount(6, $records[0]->details['steps']);
    }

    public function test_a_failure_of_the_shared_store_step(): void
    {
        $this->app->instance(SharedStore::class, new class($this->app->make(RedisFactory::class)) extends SharedStore
        {
            public function run(callable $operation): mixed
            {
                $trace = (new \Exception)->getTraceAsString();

                if (str_contains($trace, 'moveSharedStore')) {
                    throw new SharedStoreUnavailable('down');
                }

                return parent::run($operation);
            }
        });
        $this->asAdmin();

        $problem = $this->rename()->assertStatus(503)->assertJsonPath('dependency', 'sharedStore')->json();

        $this->assertSame(['undone', 'failed', 'notDone'], array_slice(array_values(self::outcomes($problem['steps'])), 0, 3));
        $this->assertStateRestored();
    }

    public function test_a_failure_of_the_database_step(): void
    {
        $this->app->instance(MailboxDataMover::class, new class($this->app->make(SogoDirectory::class)) extends MailboxDataMover
        {
            public function move(string $from, string $to, bool $forward, ?string $aliasComment): void
            {
                throw new RuntimeException('database failed');
            }
        });
        $this->asAdmin();

        $problem = $this->rename()->assertStatus(503)->assertJsonPath('dependency', 'database')->json();

        $this->assertSame(['undone', 'undone', 'failed', 'notDone', 'notDone', 'notDone'], array_values(self::outcomes($problem['steps'])));
        $this->assertStateRestored();
    }

    public function test_a_failing_undo_leaves_the_rename_incomplete(): void
    {
        $this->dockerApi()->script('sogo-mailcow', 'sogo', 'rename_user', DockerCallResult::failed('boom'));
        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'move', DockerCallResult::success(), DockerCallResult::failed('cannot move back'));
        $this->asAdmin();

        $problem = $this->rename()->assertStatus(500)
            ->assertJsonPath('type', '/api/v2/problems/mailbox-rename-incomplete')
            ->assertJsonPath('previousAddress', self::OLD)
            ->assertJsonPath('newAddress', self::NEW)
            ->json();

        $this->assertSame(['undone', 'undone', 'undone', 'undoFailed', 'failed', 'notDone'], array_values(self::outcomes($problem['steps'])));
        $record = $this->auditOf('mailboxRename')[0];
        $this->assertSame(['failure', 'partiallyCompleted'], [$record->outcome, $record->failure_reason]);
        $this->assertSame($problem['steps'], $record->details['steps']);
    }

    public function test_a_step_without_an_answer_is_an_unknown_outcome(): void
    {
        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'move', DockerCallResult::unknown('timed out'));
        $this->asAdmin();

        $problem = $this->rename()->assertStatus(504)
            ->assertJsonPath('type', '/api/v2/problems/outcome-unknown')
            ->assertJsonPath('step', 'mailStoreMove')
            ->json();

        $this->assertSame(['undone', 'undone', 'undone', 'unknown', 'notDone', 'notDone'], array_values(self::outcomes($problem['steps'])));
        $this->dockerApi()->assertExecuted('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => 'example.org/anna', 'new_maildir' => 'example.org/anna.smith'], 1);
        $this->dockerApi()->assertNotExecuted('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => 'example.org/anna.smith', 'new_maildir' => 'example.org/anna']);
        $this->assertStateRestored();
        $this->assertSame('outcomeUnknown', $this->auditOf('mailboxRename')[0]->failure_reason);
    }

    public function test_a_failing_final_audit_record_undoes_the_rename(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
            {
                if ($success && $type === AuditEventType::MailboxRename) {
                    throw new RuntimeException('audit storage failed');
                }

                return parent::record($type, $success, $actor, $target, $authMethod, $failureReason, $details);
            }
        });
        $this->asAdmin();

        $this->rename()->assertStatus(500);

        $this->assertStateRestored();
        $this->assertFalse(DB::table('alias')->where('address', self::OLD)->where('goto', self::NEW)->exists());
        $this->assertCount(1, $this->auditOf('mailboxRename'));
    }
}
