<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T085 (FR-067, SC-001): rows written with legacy's own statements (copied from
 * functions.mailbox.inc.php of mailcow-dockerized 2026-09a) appear unchanged in the next API
 * response, and rows written by the API are read back by legacy's own queries with the same values.
 */
class LegacyCoexistenceTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->mailbox('bob@example.org');
        $this->asAdmin();
    }

    public function test_rows_written_by_legacy_are_read_by_the_api(): void
    {
        // mailbox('add', 'alias')
        DB::insert('INSERT INTO `alias` (`address`, `public_comment`, `private_comment`, `goto`, `domain`, `sogo_visible`, `internal`, `sender_allowed`, `active`)
            VALUES (:address, :public_comment, :private_comment, :goto, :domain, :sogo_visible, :internal, :sender_allowed, :active)', [
            'address' => 'Legacy@example.org', 'public_comment' => 'pub', 'private_comment' => 'priv', 'goto' => 'anna@example.org,bob@example.org',
            'domain' => 'example.org', 'sogo_visible' => 0, 'internal' => 1, 'sender_allowed' => 0, 'active' => 1,
        ]);
        // mailbox('add', 'alias_domain')
        DB::insert('INSERT INTO `alias_domain` (`alias_domain`, `target_domain`, `active`) VALUES (:alias_domain, :target_domain, :active)', ['alias_domain' => 'example.net', 'target_domain' => 'example.org', 'active' => 0]);
        // mailbox('add', 'resource')
        DB::insert("INSERT INTO `mailbox` (`username`, `password`, `name`, `quota`, `local_part`, `domain`, `active`, `multiple_bookings`, `kind`)
            VALUES (:name, 'RESOURCE', :description, 0, :local_part, :domain, :active, :multiple_bookings, :kind)", [
            'name' => 'Room@example.org', 'description' => 'Room', 'local_part' => 'Room', 'domain' => 'example.org', 'active' => 1, 'kind' => 'thing', 'multiple_bookings' => 3,
        ]);
        // mailbox('edit', 'mailbox') sender ACL branches
        DB::insert('INSERT INTO `sender_acl` (`send_as`, `logged_in_as`) VALUES (:sender_acl, :username)', ['sender_acl' => '@example.org', 'username' => 'anna@example.org']);
        DB::insert('INSERT INTO `sender_acl` (`send_as`, `logged_in_as`, `external`) VALUES (:sender_acl, :username, 1)', ['sender_acl' => 'news@mailer.example', 'username' => 'anna@example.org']);

        $alias = $this->getJson('/api/v2/aliases?address=legacy@example.org')->json('data.0');
        $this->assertSame(['Legacy@example.org', ['anna@example.org', 'bob@example.org'], false, true, false, true, 'pub', 'priv'],
            [$alias['address'], $alias['destinations'], $alias['groupwareVisible'], $alias['internal'], $alias['senderAllowed'], $alias['active'], $alias['publicComment'], $alias['privateComment']]);
        $this->getJson('/api/v2/alias-domains/example.net')->assertJsonPath('data.targetDomain', 'example.org')->assertJsonPath('data.active', false);
        $this->getJson('/api/v2/resources/room@example.org')->assertJsonPath('data.kind', 'thing')->assertJsonPath('data.bookingLimit', 3)->assertJsonPath('data.description', 'Room');
        $settings = $this->getJson('/api/v2/mailboxes/anna@example.org/sender-settings')->json('data');
        $this->assertSame(['@example.org'], array_column($settings['explicitPermissions'], 'value'));
        $this->assertSame(['news@mailer.example'], $settings['externalSenderAddresses']);
    }

    public function test_rows_written_by_the_api_are_read_by_legacy(): void
    {
        $id = (int) $this->createAlias(['address' => 'Sales@example.org', 'destinations' => ['anna@example.org'], 'internal' => true, 'publicComment' => 'pub'])->assertCreated()->json('data.id');
        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org', 'dkimSelector' => ''], $this->csrf())->assertCreated();
        $this->postJson('/api/v2/resources', ['description' => 'Big Room', 'domain' => 'example.org', 'kind' => 'location', 'bookingRule' => 'alwaysFree'], $this->csrf())->assertCreated();
        $this->patchMerge('/api/v2/mailboxes/bob@example.org/sender-settings', ['explicitPermissions' => ['Sales@example.org', 'example.org'], 'externalSenderAddresses' => ['partner.example']], $this->csrf())->assertOk();

        // mailbox('get', 'alias_details')
        $alias = DB::selectOne('SELECT `id`, `domain`, `goto`, `address`, `public_comment`, `private_comment`, `internal`, `active`, `sogo_visible`, `sender_allowed`, `created`, `modified`
            FROM `alias` WHERE (`id` = :id OR `address` = :address) AND `address` != `goto`', ['id' => $id, 'address' => $id]);
        // The private comment was not sent: NULL, as legacy's add form stores it (its list shows "-").
        $this->assertSame(['Sales@example.org', 'example.org', 'anna@example.org', 'pub', null, 1, 1, 1, 1], [$alias->address, $alias->domain, $alias->goto, $alias->public_comment, $alias->private_comment, (int) $alias->internal, (int) $alias->active, (int) $alias->sogo_visible, (int) $alias->sender_allowed]);

        // mailbox('get', 'alias_domain_details')
        $aliasDomain = DB::selectOne('SELECT `alias_domain`, `target_domain`, `active`, `created`, `modified` FROM `alias_domain` WHERE `alias_domain` = :aliasdomain', ['aliasdomain' => 'example.net']);
        $this->assertSame(['example.org', 1], [$aliasDomain->target_domain, (int) $aliasDomain->active]);

        // mailbox('get', 'resource_details')
        $resource = DB::selectOne("SELECT `username`, `name`, `kind`, `multiple_bookings`, `local_part`, `active`, `domain` FROM `mailbox` WHERE `kind` REGEXP 'location|thing|group' AND `username` = :resource", ['resource' => 'BigRoom@example.org']);
        $this->assertSame(['Big Room', 'location', 0, 'BigRoom', 1, 'example.org'], [$resource->name, $resource->kind, (int) $resource->multiple_bookings, $resource->local_part, (int) $resource->active, $resource->domain]);

        // mailbox('get', 'sender_acl_handles')
        $domains = DB::select("SELECT REPLACE(`send_as`, '@', '') AS `send_as` FROM `sender_acl` WHERE `logged_in_as` = :logged_in_as AND `external` = '0' AND (`send_as` LIKE '@%' OR `send_as` = '*')", ['logged_in_as' => 'bob@example.org']);
        $addresses = DB::select("SELECT `send_as` FROM `sender_acl` WHERE `logged_in_as` = :logged_in_as AND `external` = '0' AND (`send_as` NOT LIKE '@%' AND `send_as` != '*')", ['logged_in_as' => 'bob@example.org']);
        $external = DB::select("SELECT `send_as` as `send_as_external` FROM `sender_acl` WHERE `logged_in_as` = :logged_in_as AND `external` = '1'", ['logged_in_as' => 'bob@example.org']);
        $fixed = DB::select("SELECT `address`, `sender_allowed` FROM `alias` WHERE `goto` REGEXP :goto AND `address` NOT LIKE '@%'", ['goto' => '(^|,)'.preg_quote('anna@example.org', '/').'($|,)']);
        $this->assertSame(['example.org'], array_column($domains, 'send_as'));
        $this->assertSame(['Sales@example.org'], array_column($addresses, 'send_as'));
        $this->assertSame(['@partner.example'], array_column($external, 'send_as_external'));
        $this->assertEqualsCanonicalizing(['anna@example.org', 'Sales@example.org'], array_column($fixed, 'address'));
    }
}
