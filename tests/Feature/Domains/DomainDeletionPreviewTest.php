<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T069 (FR-065, SC-011, US4 AS-6): previewDomainDeletion computes what a deletion would
 * be blocked by, remove and keep, without changing anything.
 */
class DomainDeletionPreviewTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        $counts = [];
        foreach (['domain', 'alias', 'alias_domain', 'admin', 'da_acl', 'domain_admins', 'domain_wide_footer', 'restv2_audit_events'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts + ['redis' => count($this->redis()->keys('*'))];
    }

    public function test_the_preview_lists_blockers_removals_and_kept_key_and_changes_nothing(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->mailbox('room@example.org', kind: 'location');
        $f->aliasDomain('alias.example', 'example.org');
        $f->alias('a@example.org', 'x@external.example');
        $f->alias('b@example.org', 'x@external.example');
        $f->alias('c@alias.example', 'x@external.example');
        $f->domainFooter('example.org', '<p>f</p>', 'f');
        $f->domainAdmin('only-this', ['example.org']);
        $f->domain('other.example');
        $f->domainAdmin('also-other', ['example.org', 'other.example']);
        $this->redis()->hSet('DKIM_SELECTORS', 'example.org', 'dkim');
        $this->asAdmin();
        $before = $this->snapshot();

        $data = $this->getJson('/api/v2/domains/example.org/deletion-preview')->assertOk()->json('data');

        $this->assertSame('example.org', $data['domain']);
        $this->assertFalse($data['allowed']);
        $this->assertSame(['count' => 0, 'addresses' => []], $data['blockers']['mailboxes']);
        $this->assertSame(['count' => 1, 'addresses' => ['room@example.org']], $data['blockers']['resources']);
        $this->assertSame(['alias.example'], $data['removals']['aliasDomains']);
        $this->assertSame(['count' => 3, 'addresses' => ['a@example.org', 'b@example.org', 'c@alias.example']], $data['removals']['aliases']);
        $this->assertTrue($data['removals']['footer']);
        $this->assertSame(['only-this'], $data['domainAdminsToDelete']);
        $this->assertTrue($data['dkimKeyKept']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_samples_are_capped_and_the_etag_matches_the_domains(): void
    {
        $this->mailcow()->domain('example.org');
        for ($i = 150; $i >= 1; $i--) {
            $this->mailcow()->alias(sprintf('a%03d@example.org', $i), 'x@external.example');
        }
        $this->asAdmin();

        $data = $this->getJson('/api/v2/domains/example.org/deletion-preview')->assertOk()->json('data');

        $this->assertTrue($data['allowed']);
        $this->assertSame(150, $data['removals']['aliases']['count']);
        $this->assertCount(100, $data['removals']['aliases']['addresses']);
        $this->assertSame('a001@example.org', $data['removals']['aliases']['addresses'][0]);
        $this->assertFalse($data['removals']['footer']);
        $this->assertFalse($data['dkimKeyKept']);
        $this->assertSame($this->getJson('/api/v2/domains/example.org')->headers->get('ETag'), $data['etag']);
    }

    public function test_access(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/domains/example.org/deletion-preview')->assertStatus(403);

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');
        $this->getJson('/api/v2/domains/example.org/deletion-preview', $this->keyHeader($key))->assertOk();
        $this->getJson('/api/v2/domains/missing.example/deletion-preview', $this->keyHeader($key))->assertNotFound();
    }

    public function test_the_deletion_removes_exactly_what_the_preview_listed(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('alias.example', 'example.org');
        $f->alias('a@example.org', 'x@external.example');
        $f->alias('b@alias.example', 'x@external.example');
        $f->alias('keep@other.example', 'x@external.example');
        $f->domainAdmin('only-this', ['example.org']);
        $f->orphanedDomainAdmin('orphan');
        $this->asAdmin();

        $preview = $this->getJson('/api/v2/domains/example.org/deletion-preview')->json('data');
        $aliasesBefore = DB::table('alias')->count();
        $this->deleteJson('/api/v2/domains/example.org', [], $this->csrf() + ['If-Match' => $preview['etag']])->assertNoContent();

        $record = $this->auditOf('domainDeletion')[0];
        $this->assertSame($preview['removals']['aliasDomains'], $record->details['removedAliasDomains']);
        $this->assertSame($preview['domainAdminsToDelete'], $record->details['removedDomainAdmins']);
        $this->assertSame(['only-this', 'orphan'], $record->details['removedDomainAdmins']);
        $this->assertSame($aliasesBefore - $preview['removals']['aliases']['count'], DB::table('alias')->count());
    }

    public function test_a_mailbox_added_after_the_preview_blocks_the_deletion(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->assertTrue($this->getJson('/api/v2/domains/example.org/deletion-preview')->json('data.allowed'));
        $this->mailcow()->mailbox('late@example.org');

        $this->deleteJson('/api/v2/domains/example.org', [], $this->csrf())->assertStatus(409);
    }
}
