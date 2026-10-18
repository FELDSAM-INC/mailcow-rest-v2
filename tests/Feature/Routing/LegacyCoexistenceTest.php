<?php

namespace Tests\Feature\Routing;

use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T100 (FR-089, FR-092, SC-001, SC-005): rows and hash fields written exactly as the
 * legacy UI writes them (with legacy's variants) are returned with the attributes the legacy screens
 * show, passwords only as `passwordSet`; changes made directly appear in the next response with a
 * new version tag; and no operation calls mailcow's container service or flushes the groupware
 * cache.
 */
class LegacyCoexistenceTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_legacy_rows_are_shown_as_the_legacy_screens_show_them(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org', attributes: ['relayhost' => '03']);
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('anna@example.org');
        $f->relayhost(3, '[smtp.example.net]:587', 'u\\:x', 'x\\:y');
        $f->mailboxRelayhost('anna@example.org', '3');
        $transport = $f->transport('.*\\.google\\.com', '[gw.example]:25', 'gw', 'secret', 1, 1);
        $bcc = $f->bccMap('@example.net', 'archive@backup.net', 'example.net', 'sender');
        $recipient = $f->recipientMap('@old.example', '@new.example', 0);
        $tls = $f->tlsPolicyMap('[mail.example.org]:25', 'dane-only', 'protocols=!SSLv2');
        $f->forwardingHost('2001:0db8::0001', 'legacy.example', true);
        $f->forwardingHost('192.0.2.5/24', '192.0.2.5/24');
        $this->asAdmin();

        $this->getJson('/api/v2/relayhosts/3')->assertOk()->assertJsonPath('data', array_merge($this->getJson('/api/v2/relayhosts/3')->json('data'), [
            'id' => 3, 'host' => '[smtp.example.net]:587', 'username' => 'u\\:x', 'passwordSet' => true, 'active' => true, 'usage' => ['domains' => 1, 'mailboxes' => 1],
        ]));
        $this->getJson('/api/v2/relayhosts/3/usage')->assertJsonPath('data', [['kind' => 'domain', 'name' => 'example.org'], ['kind' => 'mailbox', 'name' => 'anna@example.org']]);
        $this->getJson('/api/v2/domains/example.org/relayhost-assignment')->assertJsonPath('data.relayhost', ['id' => 3, 'host' => '[smtp.example.net]:587', 'state' => 'active']);
        $this->getJson('/api/v2/transports/'.$transport)->assertJsonPath('data.mxBased', true)->assertJsonPath('data.username', 'gw')->assertJsonPath('data.passwordSet', true);
        $this->getJson('/api/v2/bcc-maps/'.$bcc)->assertJsonPath('data.type', 'sender')->assertJsonPath('data.localDestination', '@example.net')->assertJsonPath('data.orphaned', false);
        $this->getJson('/api/v2/recipient-maps/'.$recipient)->assertJsonPath('data.active', false)->assertJsonPath('data.newDestination', '@new.example');
        $this->getJson('/api/v2/tls-policy-maps/'.$tls)->assertJsonPath('data.parameters', 'protocols=!SSLv2')->assertJsonPath('data.policy', 'dane-only');
        $this->getJson('/api/v2/forwarding-hosts/2001%3A0db8%3A%3A0001')->assertJsonPath('data.spamFilter', false)->assertJsonPath('data.source', 'legacy.example');
        $this->getJson('/api/v2/forwarding-hosts/192.0.2.5%2F24')->assertJsonPath('data.address', '192.0.2.5/24');

        $bodies = (string) $this->getJson('/api/v2/relayhosts')->getContent().$this->getJson('/api/v2/transports')->getContent();
        $this->assertStringNotContainsString('secret', $bodies);
        $this->assertStringNotContainsString('x\\\\:y', $bodies);
    }

    public function test_direct_changes_appear_in_the_next_response_with_a_new_version(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $relayhost = $f->relayhost(null, 'r.example', 'u', 'p');
        $transport = $f->transport('example.com', 'hop.example');
        $bcc = $f->bccMap('@example.org', 'a@backup.net', 'example.org');
        $recipient = $f->recipientMap('@old.example', '@new.example');
        $tls = $f->tlsPolicyMap('tls.example', 'may');
        $f->forwardingHost('192.0.2.1', 'src');
        $this->asAdmin();
        $uris = [
            '/api/v2/relayhosts/'.$relayhost, '/api/v2/transports/'.$transport, '/api/v2/bcc-maps/'.$bcc, '/api/v2/recipient-maps/'.$recipient,
            '/api/v2/tls-policy-maps/'.$tls, '/api/v2/forwarding-hosts/192.0.2.1', '/api/v2/domains/example.org/relayhost-assignment',
        ];
        $before = array_map(fn (string $uri): ?string => $this->getJson($uri)->assertOk()->headers->get('ETag'), $uris);

        DB::table('relayhosts')->where('id', $relayhost)->update(['hostname' => 'legacy-edit.example']);
        DB::table('transports')->where('id', $transport)->update(['active' => 0]);
        DB::table('bcc_maps')->where('id', $bcc)->update(['bcc_dest' => 'b@backup.net']);
        DB::table('recipient_maps')->where('id', $recipient)->update(['new_dest' => '@newer.example']);
        DB::table('tls_policy_override')->where('id', $tls)->update(['policy' => 'encrypt']);
        $this->redis()->hSet('KEEP_SPAM', '192.0.2.1', '1');
        $f->domainRelayhost('example.org', (string) $relayhost);

        foreach ($uris as $i => $uri) {
            $this->assertNotSame($before[$i], $this->getJson($uri)->headers->get('ETag'), $uri);
        }

        $this->getJson('/api/v2/relayhosts/'.$relayhost)->assertJsonPath('data.host', 'legacy-edit.example');
        $this->getJson('/api/v2/forwarding-hosts/192.0.2.1')->assertJsonPath('data.spamFilter', false);
    }

    public function test_no_operation_calls_a_container_service_or_flushes_the_groupware_cache(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->asAdmin();

        $relayhost = (int) $this->send('POST', '/api/v2/relayhosts', ['host' => '[192.0.2.10]', 'username' => 'u', 'password' => 'p'])->json('data.id');
        $this->send('PATCH', '/api/v2/relayhosts/'.$relayhost, ['active' => false])->assertOk();
        $this->send('PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => $relayhost])->assertOk();
        $this->send('PUT', '/api/v2/mailboxes/anna@example.org/relayhost-assignment', ['relayhostId' => $relayhost])->assertOk();
        $this->send('POST', '/api/v2/relayhosts/'.$relayhost.'/connection-test')->assertOk();
        $transport = (int) $this->send('POST', '/api/v2/transports', ['destination' => 'example.com', 'nextHop' => 'hop.example'])->json('data.id');
        $this->send('PATCH', '/api/v2/transports/'.$transport, ['active' => true])->assertOk();
        $bcc = (int) $this->send('POST', '/api/v2/bcc-maps', ['localDestination' => 'example.org', 'type' => 'recipient', 'bccDestination' => 'a@backup.net'])->json('data.id');
        $this->send('POST', '/api/v2/recipient-maps', ['originalDestination' => 'old.example', 'newDestination' => 'new.example'])->assertCreated();
        $this->send('POST', '/api/v2/tls-policy-maps', ['destination' => 'tls.example', 'policy' => 'may'])->assertCreated();
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '192.0.2.0/24'])->assertCreated();
        $this->send('POST', '/api/v2/bcc-maps/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $bcc]]])->assertOk();
        $this->send('DELETE', '/api/v2/transports/'.$transport)->assertNoContent();
        $this->send('DELETE', '/api/v2/relayhosts/'.$relayhost)->assertNoContent();

        $this->assertSame([], $this->dockerApi()->calls());
        $this->assertSame(0, $this->memcached()->flushCount());
    }
}
