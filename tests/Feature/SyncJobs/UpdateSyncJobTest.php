<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\ImapsyncRunnerSimulator;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T047 (FR-016–FR-018, US3 AS-1, AS-2, AS-4, AS-6 to AS-9): a merge patch changes only
 * the sent members and only the changed configuration columns; the password is write-only, the
 * target mailbox fixed, activation an ordinary change; members sent with their stored value are
 * accepted as stored; the runner's status is never overwritten; every change is audited.
 */
class UpdateSyncJobTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    private int $job;

    private ImapsyncRunnerSimulator $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new ImapsyncRunnerSimulator;
        $this->syncMailbox('a@example.org');
        $this->syncMailbox('b@example.org');
        $this->syncMailbox('carol@example.net');
        $this->job = $this->mailcow()->syncJob('a@example.org', ['custom_params' => '--maxsize=1000']);
    }

    public function test_sending_only_the_interval_changes_only_the_interval(): void
    {
        $before = (array) $this->syncJobRow($this->job);
        $this->asAdmin();

        $this->patchSyncJob($this->job, ['intervalMinutes' => 30])->assertOk()->assertJsonPath('data.intervalMinutes', 30);

        $after = (array) $this->syncJobRow($this->job);
        $this->assertSame(30, (int) $after['mins_interval']);
        unset($before['mins_interval'], $after['mins_interval'], $before['modified'], $after['modified']);
        $this->assertSame($before, $after);
    }

    public function test_a_new_password_replaces_the_stored_one_and_an_empty_one_keeps_it(): void
    {
        $this->asAdmin();

        $this->patchSyncJob($this->job, ['remotePassword' => 'new'])->assertOk()->assertJsonPath('data.remotePasswordSet', true);
        $this->assertSame('new', $this->syncJobRow($this->job)?->password1);

        $this->patchSyncJob($this->job, ['remotePassword' => '', 'intervalMinutes' => 25])->assertOk();
        $this->assertSame('new', $this->syncJobRow($this->job)?->password1);
    }

    public function test_deactivating_and_activating_change_what_the_runner_selects_without_interrupting_a_run(): void
    {
        $this->asAdmin();

        $this->patchSyncJob($this->job, ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->assertNotContains($this->job, $this->runner->selectableIds());
        $this->patchSyncJob($this->job, ['active' => true])->assertOk();
        $this->assertContains($this->job, $this->runner->selectableIds());

        $this->runner->startRun($this->job);
        $this->patchSyncJob($this->job, ['active' => false])->assertOk()->assertJsonPath('data.runStatus.running', true);
        $this->assertSame(1, (int) $this->syncJobRow($this->job)?->is_running);
        $this->runner->finishRun($this->job, 'done', 1, 'EX_OK');
        $this->assertSame(0, (int) $this->syncJobRow($this->job)?->is_running);
    }

    public function test_an_invalid_value_is_refused_and_nothing_changes(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();
        $before = (array) $this->syncJobRow($this->job);

        $this->patchSyncJob($this->job, ['customParameters' => '--bogus'])->assertUnprocessable()->assertJsonPath('errors.customParameters.0.code', 'optionNotAllowed');
        $this->patchSyncJob($this->job, ['exclude' => '('])->assertUnprocessable()->assertJsonPath('errors.exclude.0.code', 'invalidRegex');
        $this->patchSyncJob($this->job, ['remoteHost' => '2001:db8::1'])->assertUnprocessable()->assertJsonPath('errors.remoteHost.0.code', 'invalidFormat');
        $this->patchSyncJob($this->job, ['remotePort' => 0])->assertUnprocessable()->assertJsonPath('errors.remotePort.0.code', 'outOfRange');

        $this->assertEquals($before, (array) $this->syncJobRow($this->job));
    }

    public function test_callers_without_the_flag_read_only_keys_and_foreign_jobs_are_refused(): void
    {
        $foreign = $this->mailcow()->syncJob('carol@example.net');
        DB::table('user_acl')->where('username', 'a@example.org')->update(['syncjobs' => 0]);
        $this->signInAs('a@example.org', Role::MailboxUser);
        $this->patchSyncJob($this->job, ['active' => false])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required');
        $this->forgetCookies();

        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->patchSyncJob($this->job, ['active' => false], $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->asDomainAdmin(['example.org']);
        $this->patchSyncJob($foreign, ['active' => false])->assertNotFound();
        $this->assertSame(1, (int) $this->syncJobRow($this->job)?->active);
        $this->assertSame(1, (int) $this->syncJobRow($foreign)?->active);
    }

    public function test_the_target_mailbox_cannot_change_but_may_be_sent_back(): void
    {
        $this->asAdmin();

        $this->patchSyncJob($this->job, ['mailbox' => 'b@example.org', 'intervalMinutes' => 30])->assertUnprocessable()
            ->assertJsonPath('errors.mailbox.0.code', 'immutable');
        $this->assertSame(20, (int) $this->syncJobRow($this->job)?->mins_interval);

        $this->patchSyncJob($this->job, ['mailbox' => 'A@example.org', 'intervalMinutes' => 30])->assertOk()->assertJsonPath('data.intervalMinutes', 30);
        $this->assertSame('a@example.org', $this->syncJobRow($this->job)?->user2);
    }

    public function test_an_invalid_body_is_refused_and_read_only_members_are_ignored(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->patchSyncJob($this->job, [])->assertUnprocessable()->assertJsonPath('errors.body.0.code', 'required');
        $this->patchSyncJob($this->job, ['intervalMinutes' => null])->assertUnprocessable()->assertJsonPath('errors.intervalMinutes.0.code', 'invalidType');

        $read = (array) $this->getJson('/api/v2/sync-jobs/'.$this->job)->json('data');
        $this->patchSyncJob($this->job, ['id' => 999, 'etag' => '"x"', 'runStatus' => ['running' => true], 'createdAt' => '2000-01-01T00:00:00Z', 'remotePasswordSet' => false, 'authMechanism' => 'plain', 'intervalMinutes' => 21])
            ->assertOk()->assertJsonPath('data.id', $this->job)->assertJsonPath('data.intervalMinutes', 21);

        $read['intervalMinutes'] = 21;
        $this->patchSyncJob($this->job, $read)->assertOk();
        $this->assertSame('remote-secret', $this->syncJobRow($this->job)?->password1);
    }

    public function test_members_sent_with_their_stored_value_are_accepted_even_if_they_predate_the_rules(): void
    {
        $legacy = $this->mailcow()->syncJob('a@example.org', ['host1' => 'bad_host', 'user1' => 'legacy', 'custom_params' => '--removed-option']);
        $this->asSyncUser('a@example.org');

        $this->patchSyncJob($legacy, ['active' => false])->assertOk();
        $this->patchSyncJob($legacy, ['remoteHost' => 'bad_host', 'customParameters' => '--removed-option', 'active' => true])->assertOk();
        $this->patchSyncJob($legacy, ['remoteHost' => 'other_bad'])->assertUnprocessable()
            ->assertJsonPath('errors.remoteHost.0.code', 'invalidFormat');
        $this->assertSame('bad_host', $this->syncJobRow($legacy)?->host1);
    }

    public function test_the_host_is_stored_as_sent_and_the_runner_s_status_is_never_overwritten(): void
    {
        $this->asAdmin();
        $read = $this->getJson('/api/v2/sync-jobs/'.$this->job)->assertOk();
        $this->runner->startRun($this->job);
        $this->runner->finishRun($this->job, 'the runner wrote this', 0, 'EXIT_CONNECTION_FAILURE');
        $status = DB::table('imapsync')->where('id', $this->job)->first(['last_run', 'success', 'exit_status', 'returned_text', 'is_running']);

        $this->patchSyncJob($this->job, ['remoteHost' => 'IMAP.New.example'], ['If-Match' => (string) $read->headers->get('ETag')])->assertOk();

        $this->assertSame('IMAP.New.example', $this->syncJobRow($this->job)?->host1);
        $this->assertEquals($status, DB::table('imapsync')->where('id', $this->job)->first(['last_run', 'success', 'exit_status', 'returned_text', 'is_running']));
    }

    public function test_each_change_is_audited_with_old_and_new_values_and_the_password_only_as_changed(): void
    {
        $this->asAdmin();
        $this->patchSyncJob($this->job, ['intervalMinutes' => 30])->assertOk();
        $this->patchSyncJob($this->job, ['remotePassword' => self::REMOTE_PASSWORD])->assertOk();
        $this->patchSyncJob($this->job, ['active' => true])->assertOk();
        $this->patchSyncJob($this->job, ['customParameters' => '--bogus'])->assertUnprocessable();

        $records = $this->auditOfTarget('syncJob', (string) $this->job);
        $this->assertCount(4, $records);
        $this->assertSame(['intervalMinutes' => ['old' => 20, 'new' => 30]], $records[0]->details['changes']);
        $this->assertSame('a@example.org', $records[0]->details['mailbox']);
        $this->assertSame('a@example.org', $records[0]->target_name);
        $this->assertSame(['remotePassword' => 'changed'], $records[1]->details['changes']);
        $this->assertSame([], $records[2]->details['changes']);
        $this->assertSame(['success', 'success', 'success', 'failure'], array_column($records, 'outcome'));
        $this->assertSame('validationFailed', $records[3]->failure_reason);
        $this->assertStringNotContainsString(self::REMOTE_PASSWORD, (string) DB::table('restv2_audit_events')->get()->toJson());
    }

    public function test_a_failed_audit_insert_changes_nothing(): void
    {
        $this->asAdmin();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->event_type === 'syncJobUpdate' && $event->outcome === 'success') {
                throw new RuntimeException('audit store unavailable');
            }
        });

        $this->patchSyncJob($this->job, ['intervalMinutes' => 30])->assertStatus(500);

        $this->assertSame(20, (int) $this->syncJobRow($this->job)?->mins_interval);
    }
}
