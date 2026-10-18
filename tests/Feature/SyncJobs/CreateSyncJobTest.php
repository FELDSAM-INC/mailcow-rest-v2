<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use App\Models\AuditEvent;
use App\Services\SyncJobs\SyncJobDefaults;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T034 (FR-016–FR-018, US2 AS-1, AS-2, AS-9, AS-10): creating a sync job stores it with
 * the legacy form's defaults, answers `201` with its address and version tag, never returns the
 * remote password, and audits the creation and every refusal.
 */
class CreateSyncJobTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    public function test_a_mailbox_user_creates_a_job_for_their_own_mailbox_with_the_defaults(): void
    {
        $this->asSyncUser('alice@example.org');

        $response = $this->createSyncJob(self::syncJobBody())->assertCreated();

        $id = (int) $response->json('data.id');
        $this->assertSame('/api/v2/sync-jobs/'.$id, $response->headers->get('Location'));
        $this->assertSame($response->json('data.etag'), $response->headers->get('ETag'));
        $this->assertSame('alice@example.org', $response->json('data.mailbox'));
        $this->assertTrue($response->json('data.remotePasswordSet'));

        foreach (SyncJobDefaults::VALUES as $member => $default) {
            $this->assertSame($default, $response->json('data.'.$member), $member);
        }

        $this->assertSame(['lastRunAt' => null, 'lastResult' => 'unknown', 'exitStatus' => null, 'running' => false, 'logAvailable' => false], $response->json('data.runStatus'));
        $this->assertStringNotContainsString(self::REMOTE_PASSWORD, (string) $response->getContent());
        $this->assertStringNotContainsString(self::REMOTE_PASSWORD, (string) json_encode($response->headers->all()));
        $this->assertSame(self::REMOTE_PASSWORD, $this->syncJobRow($id)?->password1);
    }

    public function test_an_administrator_and_a_domain_administrator_name_a_mailbox_in_their_scope(): void
    {
        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('carol@example.net');
        $this->asAdmin();

        $this->createSyncJob(self::syncJobBody(['mailbox' => 'carol@example.net']))->assertCreated()->assertJsonPath('data.mailbox', 'carol@example.net');

        $this->asDomainAdmin(['example.org']);
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertCreated()->assertJsonPath('data.mailbox', 'alice@example.org');
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'carol@example.net', 'remoteUser' => 'x']))->assertNotFound()->assertJsonPath('type', 'about:blank');
        $this->assertSame(2, $this->syncJobCount());
    }

    public function test_a_user_without_the_flag_and_a_read_only_key_are_refused_and_nothing_is_stored(): void
    {
        $this->asSyncUser('alice@example.org', syncjobs: false);
        $this->createSyncJob(self::syncJobBody())->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['syncjobs']);
        $this->forgetCookies();

        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']), $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertSame(0, $this->syncJobCount());
    }

    public function test_the_creation_and_every_refusal_are_audited_without_the_password(): void
    {
        $this->syncMailbox('alice@example.org');
        $this->asAdmin();
        $id = (int) $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertCreated()->json('data.id');

        $created = $this->auditOf('syncJobCreation');
        $this->assertCount(1, $created);
        $this->assertSame('success', $created[0]->outcome);
        $this->assertSame(['syncJob', (string) $id, 'alice@example.org'], [$created[0]->target_type, $created[0]->target_id, $created[0]->target_name]);
        $this->assertSame('alice@example.org', $created[0]->details['mailbox']);
        $this->assertSame('imap.old-provider.example', $created[0]->details['values']['remoteHost']);
        $this->assertTrue($created[0]->details['values']['remotePasswordSet']);
        $this->assertArrayNotHasKey('remotePassword', $created[0]->details['values']);

        $this->createSyncJob(self::syncJobBody(['mailbox' => 'nobody@example.org']))->assertNotFound();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org', 'remotePort' => 0]))->assertUnprocessable();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertStatus(409);
        $this->mailcow()->domainAdmin('noflag', ['example.org'], ['syncjobs' => 0]);
        $this->signInAs('noflag', Role::DomainAdmin);
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertForbidden();
        $this->forgetCookies();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']), $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden();
        $this->asAdmin();
        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']), ['X-CSRF-Token' => 'forged'])->assertForbidden();

        $refusals = array_values(array_filter($this->auditOf('syncJobCreation'), fn (object $r): bool => $r->outcome === 'failure'));
        $this->assertSame(['notFound', 'validationFailed', 'conflict', 'permissionDenied', 'permissionDenied'], array_column($refusals, 'failure_reason'));
        $this->assertSame([['code' => 'outOfRange', 'params' => ['min' => 1, 'max' => 65535]]], $refusals[1]->details['errors']['remotePort']);
        $this->assertSame('/api/v2/problems/permission-flag-required', $refusals[3]->details['problemType']);
        $this->assertSame('/api/v2/problems/insufficient-key-scope', $refusals[4]->details['problemType']);
        $this->assertSame('nobody@example.org', $refusals[0]->target_name);
        $this->assertStringNotContainsString(self::REMOTE_PASSWORD, (string) DB::table('restv2_audit_events')->get()->toJson());
    }

    public function test_a_failed_audit_insert_stores_nothing(): void
    {
        $this->syncMailbox('alice@example.org');
        $this->asAdmin();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->event_type === 'syncJobCreation' && $event->outcome === 'success') {
                throw new RuntimeException('audit store unavailable');
            }
        });

        $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertStatus(500);

        $this->assertSame(0, $this->syncJobCount());
    }
}
