<?php

namespace Tests\Feature\MailboxGroups;

use App\Clients\DockerApi\DockerCallResult;
use App\Models\Mailcow\SchemaVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

class MailboxGroupsTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.org');
        foreach (['anna@example.org', 'bob@example.org', 'shared@example.org', 'foreign@other.org'] as $address) {
            $this->mailcow()->mailbox($address);
            $this->mailcow()->alias($address, $address);
        }
        $this->asAdmin();
    }

    private function modern(): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->markTestSkipped('Requires the 2026-11 group schema.');
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function create(array $values = []): array
    {
        return $this->postJson('/api/v2/mailbox-groups', $values + ['domain' => 'example.org', 'name' => 'Staff', 'members' => ['anna@example.org']], $this->csrf())
            ->assertCreated()->json('data');
    }

    public function test_old_schema_is_gated_without_attempting_new_tables(): void
    {
        $this->getJson('/api/v2/capabilities/mailboxes')->assertOk()->assertJsonPath('data.mailboxGroups', SchemaVersion::hasNovember2026Features());
        if (! SchemaVersion::hasNovember2026Features()) {
            $this->getJson('/api/v2/mailbox-groups')->assertConflict();
            $this->postJson('/api/v2/mailbox-groups', ['domain' => 'example.org', 'name' => 'Staff'], $this->csrf())->assertConflict();
            $this->getJson('/api/v2/mailboxes/anna@example.org/groups')->assertOk()->assertExactJson(['data' => []]);
        }
    }

    public function test_distribution_and_sender_rows_follow_members_and_active_state_and_are_audited(): void
    {
        $this->modern();
        $group = $this->create(['address' => 'staff@example.org', 'senderAllowed' => true, 'sogoVisible' => true, 'sendAs' => ['shared@example.org']]);
        $id = $group['id'];
        $this->assertDatabaseHas('alias', ['group_id' => $id, 'address' => 'staff@example.org', 'goto' => 'anna@example.org', 'sender_allowed' => 1, 'active' => 1]);
        $this->assertDatabaseHas('sender_acl', ['group_id' => $id, 'logged_in_as' => 'anna@example.org', 'send_as' => 'shared@example.org']);
        DB::table('sender_acl')->insert(['logged_in_as' => 'anna@example.org', 'send_as' => 'foreign@other.org', 'external' => 0]);
        $this->patchMerge('/api/v2/mailbox-groups/'.$id, ['active' => false], $this->csrf())->assertOk();
        $this->assertDatabaseMissing('sender_acl', ['group_id' => $id]);
        $this->assertDatabaseHas('sender_acl', ['group_id' => null, 'send_as' => 'foreign@other.org']);
        $this->patchMerge('/api/v2/mailbox-groups/'.$id, ['active' => true, 'members' => []], $this->csrf())->assertOk();
        $this->assertDatabaseHas('alias', ['group_id' => $id, 'goto' => 'null@localhost', 'active' => 0]);
        $this->assertDatabaseHas('restv2_audit_events', ['event_type' => 'mailboxGroupCreation', 'target_id' => (string) $id, 'outcome' => 'success']);
    }

    public function test_domain_admin_membership_edits_preserve_identity_and_foreign_members(): void
    {
        $this->modern();
        $group = $this->create(['members' => ['anna@example.org', 'bob@example.org', 'foreign@other.org']]);
        DB::table('mailbox_group_member')->where('group_id', $group['id'])->where('username', 'anna@example.org')->update(['source' => 'idp']);
        $this->asDomainAdmin(['example.org']);
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['members' => []], $this->csrf())->assertOk();
        $this->assertDatabaseHas('mailbox_group_member', ['group_id' => $group['id'], 'username' => 'anna@example.org', 'source' => 'idp']);
        $this->assertDatabaseHas('mailbox_group_member', ['group_id' => $group['id'], 'username' => 'foreign@other.org']);
        $this->assertDatabaseMissing('mailbox_group_member', ['group_id' => $group['id'], 'username' => 'bob@example.org']);
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['priority' => 5], $this->csrf())->assertForbidden();
        DB::table('da_acl')->where('username', 'reseller')->update(['mailbox_groups' => 0]);
        $this->getJson('/api/v2/mailbox-groups')->assertForbidden();
    }

    public function test_foreign_grants_make_group_read_only_and_new_foreign_grants_are_rejected(): void
    {
        $this->modern();
        $group = $this->create(['sendAs' => ['foreign@other.org']]);
        $other = $this->create(['name' => 'Local']);
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/mailbox-groups/'.$group['id'])->assertOk()->assertJsonPath('data.editable', false);
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['sendAs' => []], $this->csrf())->assertForbidden();
        $this->deleteJson('/api/v2/mailbox-groups/'.$group['id'], [], $this->csrf())->assertForbidden();
        foreach (['sendAs' => ['foreign@other.org'], 'members' => ['foreign@other.org'], 'imapGrants' => [['owner' => 'foreign@other.org', 'rights' => 'read']]] as $field => $value) {
            $this->patchMerge('/api/v2/mailbox-groups/'.$other['id'], [$field => $value], $this->csrf())->assertUnprocessable();
        }
        $this->asDomainAdmin(['other.org'], 'foreign-admin');
        $this->getJson('/api/v2/mailbox-groups/'.$group['id'])->assertNotFound();
    }

    public function test_imap_revocation_is_not_lost_when_dovecot_is_down(): void
    {
        $this->modern();
        $group = $this->create(['imapGrants' => [['owner' => 'shared@example.org', 'rights' => 'write', 'subscribe' => true]]]);
        $this->assertSame('applied', $group['imapGrants'][0]['state']);
        $this->dockerApi()->script('dovecot-mailcow', 'doveadm', 'set_acl_all', DockerCallResult::unreachable('offline'));
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['imapGrants' => []], $this->csrf())
            ->assertOk()->assertJsonPath('data.imapGrants.0.state', 'removing');
        $this->assertDatabaseHas('mailbox_group_imap_grant', ['group_id' => $group['id'], 'state' => 'removing', 'owner' => 'shared@example.org']);
        $this->dockerApi()->assertExecuted('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'set_acl_all', 'user' => 'shared@example.org', 'id' => 'group=mcg_'.$group['id'], 'rights' => []]);
        $this->dockerApi()->script('dovecot-mailcow', 'doveadm', 'set_acl_all', DockerCallResult::success());
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['description' => 'Retry'], $this->csrf())->assertOk();
        $this->assertDatabaseMissing('mailbox_group_imap_grant', ['group_id' => $group['id']]);
    }

    public function test_sogo_revocation_removes_only_group_owned_acl_rows(): void
    {
        $this->modern();
        $this->mailcow()->sogoData('shared@example.org');
        $group = $this->create(['sogoGrants' => [['owner' => 'shared@example.org', 'type' => 'Calendar', 'folder' => 'personal', 'access' => 'read']]]);
        $this->assertSame('applied', $group['sogoGrants'][0]['state']);
        $owned = DB::table('mailbox_group_sogo_acl')->pluck('acl_id')->all();
        $this->assertCount(3, $owned);
        $manual = (array) DB::table('sogo_acl')->where('id', $owned[0])->first();
        unset($manual['id']);
        $manualId = DB::table('sogo_acl')->insertGetId($manual);
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['members' => []], $this->csrf())->assertOk();
        $this->assertSame(0, DB::table('sogo_acl')->whereIn('id', $owned)->count());
        $this->assertDatabaseHas('sogo_acl', ['id' => $manualId]);
    }

    public function test_invalid_relations_are_versioned_and_validation_cannot_take_an_existing_address(): void
    {
        $this->modern();
        $group = $this->create();
        DB::table('mailbox_group_member')->insert(['group_id' => $group['id'], 'username' => 'bob@example.org', 'source' => 'idp']);
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['description' => 'Stale'], $this->csrf() + ['If-Match' => $group['etag']])->assertStatus(412);
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['address' => 'anna@example.org'], $this->csrf())->assertUnprocessable();
        $this->withoutRequestValidation();
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['domain' => 'other.org'], $this->csrf())->assertUnprocessable();
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['active' => 'false'], $this->csrf())->assertUnprocessable();
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'].'?active=false', ['description' => 'Body only'], $this->csrf())->assertOk()->assertJsonPath('data.active', true);
    }

    public function test_mailbox_users_only_see_their_own_memberships_without_members_or_grants(): void
    {
        $this->modern();
        $this->create(['members' => ['anna@example.org', 'bob@example.org'], 'sendAs' => ['shared@example.org']]);
        $this->asMailboxUser('anna@example.org');
        $this->getJson('/api/v2/mailbox-groups')->assertForbidden();
        $result = $this->getJson('/api/v2/mailboxes/anna@example.org/groups')->assertOk();
        $this->assertSame(['id', 'name', 'address', 'active', 'source'], array_keys($result->json('data.0')));
        $this->getJson('/api/v2/mailboxes/bob@example.org/groups')->assertNotFound();
    }

    public function test_delete_cascades_derived_rows_without_deleting_members(): void
    {
        $this->modern();
        $group = $this->create(['address' => 'staff@example.org', 'sendAs' => ['shared@example.org']]);
        $this->deleteJson('/api/v2/mailbox-groups/'.$group['id'], [], $this->csrf() + ['If-Match' => $group['etag']])->assertOk();
        $this->assertDatabaseMissing('mailbox_group', ['id' => $group['id']]);
        $this->assertDatabaseMissing('alias', ['address' => 'staff@example.org']);
        $this->assertDatabaseMissing('sender_acl', ['group_id' => $group['id']]);
        $this->assertDatabaseHas('mailbox', ['username' => 'anna@example.org']);
    }

    public function test_csrf_and_read_only_keys_are_enforced_and_lists_are_sorted_and_paged(): void
    {
        $this->modern();
        $this->postJson('/api/v2/mailbox-groups', ['domain' => 'example.org', 'name' => 'Denied'])->assertForbidden();
        $a = $this->create(['name' => 'A']);
        $this->create(['name' => 'B']);
        $this->getJson('/api/v2/mailbox-groups?sort=name&order=desc&limit=1')->assertOk()
            ->assertJsonPath('data.0.name', 'B')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.memberCount', 1);
        $this->getJson('/api/v2/mailbox-groups?q=A&sort=id')->assertOk()->assertJsonPath('meta.total', 2);
        $key = $this->apiKey(accessLevel: 'readOnly');
        $this->forgetCookies();
        $this->getJson('/api/v2/mailbox-groups/'.$a['id'], $this->keyHeader($key))->assertOk()->assertJsonPath('data.editable', false);
        $this->patchMerge('/api/v2/mailbox-groups/'.$a['id'], ['name' => 'Denied'], $this->keyHeader($key))->assertForbidden();
        $this->deleteJson('/api/v2/mailbox-groups/'.$a['id'], [], $this->keyHeader($key))->assertForbidden();
    }

    public function test_effective_template_changes_follow_priority_and_leaving_all_groups_keeps_settings(): void
    {
        $this->modern();
        $low = $this->mailcow()->mailboxTemplate('Small', ['quota' => 256 * self::MIB]);
        $high = $this->mailcow()->mailboxTemplate('Large', ['quota' => 512 * self::MIB, 'tls_enforce_in' => 1, 'quarantine_notification' => 'daily']);
        $a = $this->create(['name' => 'A', 'templateId' => $low]);
        $this->assertSame(256 * self::MIB, (int) $this->mailboxRow('anna@example.org')->quota);
        $b = $this->create(['name' => 'B', 'templateId' => $high, 'priority' => 10]);
        $this->assertSame([], $b['warnings']);
        $this->assertSame(512 * self::MIB, (int) $this->mailboxRow('anna@example.org')->quota);
        $this->assertSame('Large', $this->mailboxAttributes('anna@example.org')['template']);
        $this->assertSame('daily', $this->mailboxAttributes('anna@example.org')['quarantine_notification']);
        $this->assertSame(1, $this->mailboxAttributes('anna@example.org')['tls_enforce_in']);
        $this->patchMerge('/api/v2/mailbox-groups/'.$b['id'], ['members' => []], $this->csrf())->assertOk();
        $this->assertSame(256 * self::MIB, (int) $this->mailboxRow('anna@example.org')->quota);
        $this->patchMerge('/api/v2/mailbox-groups/'.$a['id'], ['members' => []], $this->csrf())->assertOk();
        $this->assertSame(256 * self::MIB, (int) $this->mailboxRow('anna@example.org')->quota);
    }

    public function test_template_reapplication_preserves_manual_and_identity_provider_suspensions(): void
    {
        $this->modern();
        $template = $this->mailcow()->mailboxTemplate('Staff', ['quota' => 256 * self::MIB, 'active' => 1]);
        $group = $this->create(['templateId' => $template]);
        $this->patchMailbox('anna@example.org', ['status' => 'inactive'])->assertOk();
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['reapplyTemplate' => true], $this->csrf())->assertOk();
        $this->assertDatabaseHas('mailbox', ['username' => 'anna@example.org', 'active' => 0]);
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['active' => 2, 'authsource' => 'ldap']);
        DB::table('identity_link')->insert(['username' => 'anna@example.org', 'provider' => 'ldap', 'external_id' => 'id-1', 'idp_disabled' => 1]);
        $another = $this->mailcow()->mailboxTemplate('Replacement', ['quota' => 512 * self::MIB, 'active' => 1]);
        $this->patchMerge('/api/v2/mailbox-groups/'.$group['id'], ['templateId' => $another], $this->csrf())->assertOk();
        $this->assertDatabaseHas('mailbox', ['username' => 'anna@example.org', 'active' => 2, 'quota' => 512 * self::MIB]);
    }

    public function test_editing_and_deleting_a_template_updates_only_its_effective_members(): void
    {
        $this->modern();
        $low = $this->mailcow()->mailboxTemplate('Low', ['quota' => 128 * self::MIB]);
        $high = $this->mailcow()->mailboxTemplate('High', ['quota' => 256 * self::MIB]);
        $this->create(['name' => 'Low group', 'templateId' => $low, 'members' => ['anna@example.org', 'bob@example.org']]);
        $this->create(['name' => 'High group', 'templateId' => $high, 'priority' => 10]);
        $this->patchMerge('/api/v2/mailbox-templates/'.$low, ['quota' => 192 * self::MIB], $this->csrf())->assertOk()
            ->assertJsonPath('data.applicationWarnings', []);
        $this->assertDatabaseHas('mailbox', ['username' => 'anna@example.org', 'quota' => 256 * self::MIB]);
        $this->assertDatabaseHas('mailbox', ['username' => 'bob@example.org', 'quota' => 192 * self::MIB]);
        $this->deleteJson('/api/v2/mailbox-templates/'.$high, [], $this->csrf())->assertNoContent();
        $this->assertDatabaseHas('mailbox', ['username' => 'anna@example.org', 'quota' => 192 * self::MIB]);
    }
}
