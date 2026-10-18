<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T041 (FR-025–FR-034, FR-060, FR-061, US3): updateAlias.
 */
class UpdateAliasTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('example.com');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
    }

    public function test_replacing_the_destinations_moves_the_groupware_identity(): void
    {
        $this->asAdmin();
        $id = (int) $this->createAlias(['address' => 'sales@example.org', 'destinations' => ['anna@example.org']])->json('data.id');
        $this->assertStringContainsString('sales@example.org', (string) $this->viewRow('anna@example.org')?->aliases);
        $flushes = $this->memcached()->flushCount();

        $response = $this->patchAlias($id, ['destinations' => ['bob@example.org']])->assertOk();

        $this->assertSame('bob@example.org', $this->aliasRow($id)?->goto);
        $this->assertStringNotContainsString('sales@example.org', (string) $this->viewRow('anna@example.org')?->aliases);
        $this->assertStringContainsString('sales@example.org', (string) $this->viewRow('bob@example.org')?->aliases);
        $this->assertSame($this->getJson("/api/v2/aliases/{$id}")->headers->get('ETag'), $response->headers->get('ETag'));
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame($flushes + 1, $this->memcached()->flushCount());

        $record = $this->auditOf('aliasUpdate')[0];
        $this->assertSame(['destinations' => ['old' => ['anna@example.org'], 'new' => ['bob@example.org']]], $record->details['changes']);
        $this->assertSame('done', $record->details['groupwareUpdate']);
    }

    public function test_an_outdated_version_is_refused_and_a_current_one_or_none_is_applied(): void
    {
        $id = $this->mailcow()->alias('sales@example.org', 'anna@example.org');
        $this->asAdmin();
        $old = (string) $this->getJson("/api/v2/aliases/{$id}")->headers->get('ETag');
        DB::table('alias')->where('id', $id)->update(['public_comment' => 'changed in the legacy UI']);

        $this->patchAlias($id, ['active' => false], ['If-Match' => $old])->assertStatus(412)->assertJsonPath('type', 'about:blank');
        $this->assertSame(1, (int) $this->aliasRow($id)?->active);
        $this->assertSame('versionOutdated', $this->auditOf('aliasUpdate')[0]->failure_reason);

        $current = (string) $this->getJson("/api/v2/aliases/{$id}")->headers->get('ETag');
        $this->patchAlias($id, ['active' => false], ['If-Match' => $current])->assertOk()->assertJsonPath('data.active', false);
        $this->patchAlias($id, ['active' => true])->assertOk()->assertJsonPath('data.active', true);
    }

    public function test_external_destinations_already_on_the_alias_are_kept_and_not_rechecked(): void
    {
        $id = $this->mailcow()->alias('fwd@example.org', 'anna@example.org,someone@gmail.example');
        $this->asDomainAdmin(['example.org'], 'reseller', ['alias_external_goto' => 0]);

        $this->patchAlias($id, ['active' => false])->assertOk();
        $this->assertSame('anna@example.org,someone@gmail.example', $this->aliasRow($id)?->goto);

        $this->patchAlias($id, ['destinations' => ['Someone@gmail.example', 'anna@example.org']])->assertOk();

        $this->patchAlias($id, ['destinations' => ['someone@gmail.example', 'other@external.example']])->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->assertJsonPath('attributes', ['destinations.1']);

        config(['restv2.vars_overrides.ALIAS_DISABLE_EXTERNAL_DOMAINS' => 'true']);
        $this->patchAlias($id, ['internal' => true])->assertOk();
        $this->patchAlias($id, ['destinations' => ['someone@gmail.example', 'bob@example.org']])->assertOk();
        $this->assertSame('someone@gmail.example,bob@example.org', $this->aliasRow($id)?->goto);
    }

    public function test_moving_into_another_primary_domain_checks_its_alias_limit(): void
    {
        $f = $this->mailcow();
        $id = $f->alias('info@example.org', 'anna@example.org');
        $f->alias('full@example.com', 'anna@example.org');
        DB::table('domain')->where('domain', 'example.com')->update(['aliases' => 1]);
        DB::table('domain')->where('domain', 'example.org')->update(['aliases' => 0]);
        $this->asAdmin();

        $this->patchAlias($id, ['address' => 'info@example.com'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/domain-alias-limit-reached')
            ->assertJsonPath('domain', 'example.com')
            ->assertJsonPath('requested', 1);

        // Within the same primary domain the (exceeded) limit is never checked.
        $this->patchAlias($id, ['address' => 'contact@example.org'])->assertOk()->assertJsonPath('data.address', 'contact@example.org');
    }

    public function test_explicit_permissions_follow_an_address_change(): void
    {
        $f = $this->mailcow();
        $id = $f->alias('info@example.org', 'anna@example.org', senderAllowed: 0);
        $f->senderAcl('bob@example.org', 'info@example.org');
        $f->senderAcl('carl@example.org', 'info@example.org');
        $f->senderAcl('carl@example.org', 'contact@example.org');
        $f->senderAcl('dora@example.org', 'info@example.org', 1);
        $this->asAdmin();

        $this->patchAlias($id, ['address' => 'contact@example.org'])->assertOk();

        $this->assertSame([
            'bob@example.org contact@example.org',
            'carl@example.org contact@example.org',
            'dora@example.org info@example.org external',
        ], $this->senderAcls());
    }

    public function test_sending_flag_and_explicit_permissions(): void
    {
        $f = $this->mailcow();
        $id = $f->alias('info@example.org', 'anna@example.org,bob@example.org', senderAllowed: 0);
        $f->senderAcl('anna@example.org', 'info@example.org');
        $this->asAdmin();

        $this->patchAlias($id, ['publicComment' => 'kept'])->assertOk();
        $this->assertSame(['anna@example.org info@example.org'], $this->senderAcls());

        $this->patchAlias($id, ['senderAllowed' => true])->assertOk();
        $this->assertSame([], $this->senderAcls());
    }

    public function test_letter_case_changes_and_switching_destination_types(): void
    {
        $id = $this->mailcow()->alias('info@example.org', 'anna@example.org');
        $this->asAdmin();

        $this->patchAlias($id, ['address' => 'Info@Example.org'])->assertOk()->assertJsonPath('data.address', 'Info@example.org');
        $this->patchAlias($id, ['destinationType' => 'discard'])->assertOk()->assertJsonPath('data.destinations', []);
        $this->assertSame('null@localhost', $this->aliasRow($id)?->goto);
        $this->patchAlias($id, ['destinationType' => 'addresses'])->assertStatus(422)->assertJsonPath('errors.destinations.0.code', 'required');
        $this->patchAlias($id, ['destinations' => ['bob@example.org']])->assertOk()->assertJsonPath('data.destinationType', 'addresses');
        $this->patchAlias($id, ['destinationType' => 'addresses'])->assertOk()->assertJsonPath('data.destinations', ['bob@example.org']);
    }

    public function test_the_representation_can_be_sent_back_and_read_only_members_are_immutable(): void
    {
        $id = $this->mailcow()->alias('info@example.org', 'anna@example.org');
        $this->asAdmin();
        $read = (array) $this->getJson("/api/v2/aliases/{$id}")->json('data');

        $this->patchAlias($id, ['internal' => true] + $read)->assertOk()->assertJsonPath('data.internal', true);

        $this->patchAlias($id, ['id' => $id + 1])->assertStatus(422)->assertJsonPath('errors.id.0.code', 'immutable');
        $this->patchAlias($id, ['createdAt' => '2001-01-01T00:00:00Z'])->assertStatus(422)->assertJsonPath('errors.createdAt.0.code', 'immutable');
        $this->patchAlias($id, ['kind' => 'catchAll'])->assertStatus(422)->assertJsonPath('errors.kind.0.code', 'immutable');
        $this->patchAlias($id, ['etag' => '"stale"', 'active' => false])->assertOk();
        // A kind and domain that follow from the new address are accepted.
        $this->patchAlias($id, ['address' => '@example.com', 'kind' => 'catchAll', 'domain' => 'example.com'])->assertOk()->assertJsonPath('data.kind', 'catchAll');
    }

    public function test_long_stored_comments_sent_back_unchanged_are_accepted(): void
    {
        $long = str_repeat('x', 200);
        $id = $this->mailcow()->alias('info@example.org', 'anna@example.org', privateComment: $long);
        $this->asAdmin();

        $this->patchAlias($id, ['privateComment' => $long, 'active' => false])->assertOk();
        $this->patchAlias($id, ['privateComment' => $long.'y'])->assertStatus(422)->assertJsonPath('errors.privateComment.0.code', 'tooLong');
    }

    public function test_a_replacement_must_make_every_destination_valid(): void
    {
        $id = $this->mailcow()->alias('info@example.org', 'anna@example.org,broken entry');
        $this->asAdmin();

        $this->getJson("/api/v2/aliases/{$id}")->assertJsonPath('data.destinations', ['anna@example.org', 'broken entry']);
        $this->patchAlias($id, ['active' => false])->assertOk();
        $errors = $this->patchAlias($id, ['destinations' => ['anna@example.org', 'broken entry']])->assertStatus(422)->json('errors');
        $this->assertSame('invalidFormat', $errors['destinations.1'][0]['code']);
        $this->patchAlias($id, ['destinations' => ['anna@example.org']])->assertOk();
    }

    public function test_bcc_and_recipient_maps_are_not_changed_by_an_address_change(): void
    {
        $id = $this->mailcow()->alias('info@example.org', 'anna@example.org');
        $this->mailcow()->bccMap('info@example.org', 'archive@example.org', 'example.org');
        $this->mailcow()->recipientMap('info@example.org', 'anna@example.org');
        $this->asAdmin();

        $this->patchAlias($id, ['address' => 'contact@example.org'])->assertOk();

        $this->assertSame(1, DB::table('bcc_maps')->where('local_dest', 'info@example.org')->count());
        $this->assertSame(1, DB::table('recipient_maps')->where('old_dest', 'info@example.org')->count());
    }

    public function test_an_orphaned_alias_can_only_be_moved_to_an_existing_domain(): void
    {
        $id = $this->mailcow()->alias('old@gone.example', 'anna@example.org');
        $this->asAdmin();

        $this->patchAlias($id, ['active' => false])->assertStatus(422)->assertJsonPath('errors.address.0.code', 'notFound');
        $this->patchAlias($id, ['address' => 'old@example.org'])->assertOk()->assertJsonPath('data.orphaned', false);
    }
}
