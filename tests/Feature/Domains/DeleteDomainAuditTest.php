<?php

namespace Tests\Feature\Domains;

use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T070 (FR-057, SC-007): one domainDeletion record per deletion, completed with the
 * stored-mail outcome; refusals audited.
 */
class DeleteDomainAuditTest extends TestCase
{
    use DomainTestHelpers;

    public function test_one_completed_record_per_deletion(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
        $this->mailcow()->domainFooter('example.org', 'x', 'y');
        $this->mailcow()->domainAdmin('only-this', ['example.org']);
        $this->asAdmin();

        $this->deleteJson('/api/v2/domains/example.org', [], $this->csrf())->assertNoContent();

        $records = $this->auditOf('domainDeletion');
        $this->assertCount(1, $records);
        $this->assertSame('success', $records[0]->outcome);
        $this->assertSame('example.org', $records[0]->target_id);
        $this->assertSame(['alias.example'], $records[0]->details['removedAliasDomains']);
        $this->assertSame(['only-this'], $records[0]->details['removedDomainAdmins']);
        $this->assertTrue($records[0]->details['footerRemoved']);
        $this->assertSame(['outcome' => 'done'], $records[0]->details['maildirCleanup']);
        // Feature 007 (FR-032): the domain administrator left without a domain is deleted with the
        // full cascade and its own record.
        $this->assertSame(['domainAdminDeletion', 'domainDeletion'], DB::table('restv2_audit_events')->orderBy('event_type')->pluck('event_type')->all());
    }

    public function test_failed_and_unknown_mail_moves_are_completed_with_a_message(): void
    {
        $this->mailcow()->domain('example.org');
        $this->dockerApi()->respondTo('exec', DockerCallResult::unknown('timed out'));
        $this->asAdmin();

        $this->deleteJson('/api/v2/domains/example.org', [], $this->csrf())->assertNoContent();

        $cleanup = $this->auditOf('domainDeletion')[0]->details['maildirCleanup'];
        $this->assertSame('unknown', $cleanup['outcome']);
        $this->assertNotEmpty($cleanup['message']);
        $this->assertFalse($this->auditOf('domainDeletion')[0]->details['footerRemoved']);
    }

    public function test_refusals_are_audited(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->mailbox('user@example.org');
        $this->mailcow()->domain('stale.example');
        $this->asAdmin();

        $this->deleteJson('/api/v2/domains/example.org', [], $this->csrf())->assertStatus(409);
        $this->deleteJson('/api/v2/domains/stale.example', [], $this->csrf() + ['If-Match' => '"outdated"'])->assertStatus(412);

        $records = $this->auditOf('domainDeletion');
        $this->assertSame(['conflict', 'versionOutdated'], array_column($records, 'failure_reason'));
        $this->assertSame('/api/v2/problems/domain-not-empty', $records[0]->details['problemType']);
    }
}
