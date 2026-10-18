<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T042 (FR-033–FR-035, US3): deleteAlias.
 */
class DeleteAliasTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
    }

    public function test_the_alias_and_its_sender_permissions_are_removed(): void
    {
        $this->asAdmin();
        $id = (int) $this->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org'], 'senderAllowed' => false])->json('data.id');
        $this->mailcow()->senderAcl('anna@example.org', 'sales@example.org');
        $this->mailcow()->senderAcl('bob@example.org', 'sales@example.org');
        $this->mailcow()->alias('other@example.org', 'sales@example.org');
        $flushes = $this->memcached()->flushCount();

        $response = $this->deleteAlias($id)->assertNoContent();

        $this->assertSame('', $response->getContent());
        $this->assertNull($this->aliasRow($id));
        $this->assertSame([], $this->senderAcls());
        $this->assertStringNotContainsString('sales@example.org', (string) $this->viewRow('anna@example.org')?->aliases);
        $this->assertSame($flushes + 1, $this->memcached()->flushCount());
        $this->assertSame('sales@example.org', $this->aliasRow('other@example.org')?->goto, 'Other aliases keep the address as a destination.');

        $record = $this->auditOf('aliasDeletion')[0];
        $this->assertSame(['senderPermissions' => 2], $record->details['removed']);
        $this->assertSame([(string) $id, 'sales@example.org'], [$record->target_id, $record->target_name]);
    }

    public function test_deleting_a_catch_all_also_removes_the_domain_permissions_for_its_domain(): void
    {
        $id = $this->mailcow()->alias('@example.org', 'anna@example.org');
        $this->mailcow()->senderAcl('bob@example.org', '@example.org');
        $this->asAdmin();

        $this->deleteAlias($id)->assertNoContent();

        $this->assertSame([], $this->senderAcls());
    }

    public function test_orphaned_aliases_can_be_deleted_by_administrators(): void
    {
        $id = $this->mailcow()->alias('old@gone.example', 'anna@example.org');
        $this->asAdmin();

        $this->deleteAlias($id)->assertNoContent();
        $this->assertNull($this->aliasRow($id));
    }

    public function test_versions_and_unknown_aliases(): void
    {
        $id = $this->mailcow()->alias('sales@example.org', 'anna@example.org');
        $own = (int) DB::table('alias')->where('address', 'anna@example.org')->value('id');
        $this->asAdmin();

        $this->deleteAlias($id, ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertNotNull($this->aliasRow($id));
        $this->deleteAlias($own)->assertNotFound();
        $this->deleteAlias(2147483647)->assertNotFound();
        $this->assertNotNull($this->aliasRow($own));

        $current = (string) $this->getJson("/api/v2/aliases/{$id}")->headers->get('ETag');
        $this->deleteAlias($id, ['If-Match' => $current])->assertNoContent();
        $this->assertSame(['versionOutdated', 'notFound', 'notFound'], array_column(array_values(array_filter($this->auditOf('aliasDeletion'), fn (object $r): bool => $r->outcome === 'failure')), 'failure_reason'));
    }
}
