<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\DB;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T038 (FR-006–FR-010, FR-016, US1): getDomain with every member, IDN addressing and
 * scope.
 */
class GetDomainTest extends TestCase
{
    use DomainTestHelpers;

    public function test_every_member_from_the_stored_columns(): void
    {
        $this->mailcow()->domain('example.org', attributes: [
            'description' => 'Customer A',
            'aliases' => 50,
            'mailboxes' => 20,
            'defquota' => 3072,
            'maxquota' => 5120,
            'quota' => 20480,
            'backupmx' => 1,
            'relay_all_recipients' => 1,
            'gal' => 0,
            'created' => '2026-07-01 12:00:00',
        ]);
        $this->mailcow()->domainTags('example.org', ['zeta', 'Alpha', 'beta']);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/domains/example.org')->assertOk()->json('data');

        $this->assertSame('example.org', $data['name']);
        $this->assertSame('example.org', $data['unicodeName']);
        $this->assertSame('Customer A', $data['description']);
        $this->assertTrue($data['active']);
        $this->assertSame(20, $data['maxMailboxes']);
        $this->assertSame(50, $data['maxAliases']);
        $this->assertSame(3221225472, $data['defaultMailboxQuota']);
        $this->assertSame(5120 * self::MIB, $data['maxMailboxQuota']);
        $this->assertSame(20480 * self::MIB, $data['domainQuota']);
        $this->assertTrue($data['backupMx']);
        $this->assertTrue($data['relayAllRecipients']);
        $this->assertFalse($data['relayUnknownOnly']);
        $this->assertFalse($data['globalAddressList']);
        $this->assertSame(['Alpha', 'beta', 'zeta'], $data['tags']);
        $this->assertNull($data['relayhost']);
        $this->assertNull($data['rateLimit']);
        // mailcow stores local time (TZ=Europe/Prague, CEST in July).
        $this->assertSame('2026-07-01T10:00:00Z', $data['createdAt']);
        $this->assertNull($data['modifiedAt']);
        $this->assertSame([], $data['domainAdmins']);
        $this->assertSame(0, $data['usage']['mailboxes']);
    }

    public function test_relayhosts_and_rate_limits(): void
    {
        $this->mailcow()->relayhost(3, 'smtp.relay.example');
        $this->mailcow()->domain('a.example', attributes: ['relayhost' => '3']);
        $this->mailcow()->domain('b.example', attributes: ['relayhost' => '9']);
        $this->mailcow()->domain('c.example', attributes: ['relayhost' => '']);
        $this->redis()->hSet('RL_VALUE', 'a.example', '10 / 1h');
        $this->asAdmin();

        $a = $this->getJson('/api/v2/domains/a.example')->assertOk()->json('data');
        $this->assertSame(['id' => 3, 'hostname' => 'smtp.relay.example'], $a['relayhost']);
        $this->assertSame(['value' => 10, 'frame' => 'hour'], $a['rateLimit']);

        $b = $this->getJson('/api/v2/domains/b.example')->assertOk()->json('data');
        $this->assertSame(['id' => 9, 'hostname' => null], $b['relayhost']);
        $this->assertNull($b['rateLimit']);

        $this->assertNull($this->getJson('/api/v2/domains/c.example')->assertOk()->json('data.relayhost'));
    }

    public function test_internationalised_names_in_any_form_and_case(): void
    {
        $this->mailcow()->domain('xn--mnchen-3ya.de');
        $this->asAdmin();

        foreach (['münchen.de', 'MÜNCHEN.de', 'xn--mnchen-3ya.de', 'XN--MNCHEN-3YA.DE'] as $spelling) {
            $data = $this->getJson('/api/v2/domains/'.rawurlencode($spelling))->assertOk()->json('data');
            $this->assertSame('xn--mnchen-3ya.de', $data['name'], $spelling);
            $this->assertSame('münchen.de', $data['unicodeName'], $spelling);
        }
    }

    public function test_alias_domains_foreign_domains_and_invalid_names_are_not_found(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('foreign.example');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
        $this->asDomainAdmin(['example.org']);

        $missing = $this->getJson('/api/v2/domains/missing.example')->assertNotFound();
        $foreign = $this->getJson('/api/v2/domains/foreign.example')->assertNotFound();

        $this->assertSame([$missing->json('type'), $missing->json('title'), $missing->json('status')], [$foreign->json('type'), $foreign->json('title'), $foreign->json('status')]);
        $this->assertSame('about:blank', $foreign->json('type'));
        $this->getJson('/api/v2/domains/alias.example')->assertNotFound();
        $this->getJson('/api/v2/domains/exa_mple.org')->assertNotFound();
        $this->getJson('/api/v2/domains/example.org')->assertOk();
    }

    public function test_the_etag_header_and_changes_made_in_the_legacy_ui(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $first = $this->getJson('/api/v2/domains/example.org')->assertOk();
        $this->assertSame($first->headers->get('ETag'), $first->json('data.etag'));

        DB::table('domain')->where('domain', 'example.org')->update(['mailboxes' => 99, 'modified' => '2026-08-01 08:00:00']);

        $second = $this->getJson('/api/v2/domains/example.org')->assertOk();
        $this->assertSame(99, $second->json('data.maxMailboxes'));
        $this->assertSame('2026-08-01T06:00:00Z', $second->json('data.modifiedAt'));
        $this->assertNotSame($first->headers->get('ETag'), $second->headers->get('ETag'));
    }

    public function test_mailbox_users_are_forbidden(): void
    {
        $this->asMailboxUser('user@example.org');

        $this->getJson('/api/v2/domains/example.org')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
