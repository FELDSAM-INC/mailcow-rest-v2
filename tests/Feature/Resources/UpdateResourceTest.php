<?php

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T074 (FR-051, FR-052, US7): updateResource and deleteResource.
 */
class UpdateResourceTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->asAdmin();
        $this->postJson('/api/v2/resources', ['description' => 'Meeting Room 1', 'domain' => 'example.org', 'kind' => 'location'], $this->csrf())->assertCreated();
    }

    public function test_changes_keep_the_address_and_update_the_groupware(): void
    {
        $response = $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['description' => 'Big room', 'kind' => 'group', 'bookingRule' => 'limited', 'bookingLimit' => 2], $this->csrf())->assertOk();

        $response->assertJsonPath('data.address', 'MeetingRoom1@example.org')->assertJsonPath('data.description', 'Big room');
        $row = $this->mailboxRow('MeetingRoom1@example.org');
        $this->assertSame(['Big room', 'group', 2], [$row?->name, $row?->kind, (int) $row?->multiple_bookings]);
        $this->assertSame(['group', 2], [$this->viewRow('MeetingRoom1@example.org')?->kind, (int) $this->viewRow('MeetingRoom1@example.org')?->multiple_bookings]);
        $this->assertSame(['old' => 'Meeting Room 1', 'new' => 'Big room'], $this->auditOf('resourceUpdate')[0]->details['changes']['description']);

        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['bookingLimit' => 5], $this->csrf())->assertOk()->assertJsonPath('data.bookingLimit', 5);
        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['bookingRule' => 'alwaysFree'], $this->csrf())->assertOk()->assertJsonPath('data.bookingLimit', null);
        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['bookingLimit' => 3], $this->csrf())->assertStatus(422)->assertJsonPath('errors.bookingLimit.0.code', 'conflictsWith');
        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['bookingRule' => 'limited'], $this->csrf())->assertStatus(422)->assertJsonPath('errors.bookingLimit.0.code', 'required');
    }

    public function test_deactivation_removes_the_groupware_entry(): void
    {
        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['active' => false], $this->csrf())->assertOk()->assertJsonPath('data.active', false);

        $this->assertNull($this->viewRow('MeetingRoom1@example.org'));
    }

    public function test_invalid_changes_versions_and_read_only_members(): void
    {
        $read = (array) $this->getJson('/api/v2/resources/MeetingRoom1@example.org')->json('data');

        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['description' => '   '], $this->csrf())->assertStatus(422)->assertJsonPath('errors.description.0.code', 'required');
        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['address' => 'Other@example.org'], $this->csrf())->assertStatus(422)->assertJsonPath('errors.address.0.code', 'immutable');
        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['kind' => 'thing'] + $read, $this->csrf())->assertOk();
        $this->patchMerge('/api/v2/resources/MeetingRoom1@example.org', ['active' => false], ['If-Match' => (string) $read['etag']] + $this->csrf())->assertStatus(412);
    }

    public function test_deletion_removes_the_resource_and_its_groupware_data(): void
    {
        $this->mailcow()->sogoData('MeetingRoom1@example.org');
        $this->mailcow()->sogoData('anna@example.org');

        $this->deleteJson('/api/v2/resources/meetingroom1@example.org', [], $this->csrf())->assertNoContent();

        $this->assertNull($this->mailboxRow('MeetingRoom1@example.org'));
        $this->assertNull($this->viewRow('MeetingRoom1@example.org'));
        foreach (['sogo_user_profile' => 'c_uid', 'sogo_cache_folder' => 'c_uid', 'sogo_folder_info' => 'c_path2'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, 'MeetingRoom1@example.org')->count(), $table);
            $this->assertSame($table === 'sogo_folder_info' ? 2 : 1, DB::table($table)->where($column, 'anna@example.org')->count(), $table);
        }
        $this->assertSame(1, DB::table('sogo_acl')->count());
        $this->assertSame(2, DB::table('sogo_store')->count());
        $this->assertSame(1, DB::table('sogo_quick_appointment')->count());
        $this->assertSame(1, DB::table('sogo_quick_contact')->count());
        $removed = $this->auditOf('resourceDeletion')[0]->details['removed'];
        $this->assertEquals(['groupwareProfiles' => 1, 'groupwareFolders' => 2, 'groupwareItems' => 4, 'groupwareSharingPermissions' => 1, 'groupwareCacheEntries' => 1], $removed);
    }

    public function test_a_mailbox_can_never_be_deleted_as_a_resource(): void
    {
        $this->deleteJson('/api/v2/resources/anna@example.org', [], $this->csrf())->assertNotFound();
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
        $this->deleteJson('/api/v2/resources/MeetingRoom1@example.org', [], ['If-Match' => '"outdated"'] + $this->csrf())->assertStatus(412);
    }
}
