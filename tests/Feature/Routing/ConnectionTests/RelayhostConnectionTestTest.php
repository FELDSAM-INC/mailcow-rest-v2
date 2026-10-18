<?php

namespace Tests\Feature\Routing\ConnectionTests;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\Support\Smtp\FakeSmtpProbe;
use Tests\TestCase;

/**
 * Feature 009 T059 (FR-041–FR-045, FR-085): testRelayhostConnection with the SMTP and DNS fakes.
 */
class RelayhostConnectionTestTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return TestResponse<Response>
     */
    private function test(int $id, ?array $body = null): TestResponse
    {
        return $this->send('POST', '/api/v2/relayhosts/'.$id.'/connection-test', $body);
    }

    public function test_an_accepted_test_returns_every_member(): void
    {
        $id = $this->mailcow()->relayhost(null, '[smtp.example.net]:587', 'acct', 's3cret');
        $this->dns()->aRecords('smtp.example.net', '192.0.2.25');

        $response = $this->test($id)->assertOk()
            ->assertJsonPath('data.accepted', true)
            ->assertJsonPath('data.step', 'message')
            ->assertJsonPath('data.failure', null)
            ->assertJsonPath('data.nextHop', '[smtp.example.net]:587')
            ->assertJsonPath('data.host', 'smtp.example.net')
            ->assertJsonPath('data.port', 587)
            ->assertJsonPath('data.hostSource', 'nextHop')
            ->assertJsonPath('data.tls', true)
            ->assertJsonPath('data.login', 'succeeded')
            ->assertJsonPath('data.sender', 'relay@example.org')
            ->assertJsonPath('data.recipient', 'null@hosted.mailcow.de')
            ->assertJsonPath('data.lastReply', ['code' => 250, 'text' => '2.0.0 Ok: queued as ABC']);

        $this->assertIsInt($response->json('data.durationMs'));
        $this->assertNotEmpty($response->json('data.transcript'));
        $this->assertStringNotContainsString('s3cret', (string) $response->getContent());
        $this->dns()->assertNotQueried('MX');

        $request = $this->smtp()->assertProbed();
        $this->assertSame(['smtp.example.net', ['192.0.2.25'], 587, 'mail.example.org', 'acct', 's3cret'], [$request->host, $request->addresses, $request->port, $request->heloName, $request->username, $request->password]);
        $this->assertSame([15.0, true], [$request->stepTimeout, $request->totalTimeout <= 60.0 && $request->totalTimeout > 59.0]);
    }

    public function test_a_host_without_brackets_uses_its_most_preferred_mail_exchanger(): void
    {
        $id = $this->mailcow()->relayhost(null, 'partner.net');
        $this->dns()->mxRecords('partner.net', ['mx2.partner.net' => 20, 'mx1.partner.net' => 10, 'mx1b.partner.net' => 10])
            ->aRecords('mx1.partner.net', '192.0.2.1')->aaaaRecords('mx1.partner.net', '2001:db8::1');
        $this->smtp()->willReturn(FakeSmtpProbe::accepted(tls: false, login: 'notConfigured'));

        $response = $this->test($id)->assertOk()
            ->assertJsonPath('data.host', 'mx1.partner.net')
            ->assertJsonPath('data.port', 25)
            ->assertJsonPath('data.hostSource', 'mailExchanger')
            ->assertJsonPath('data.login', 'notConfigured');

        $this->assertContains(['direction' => 'info', 'text' => 'Using the most preferred mail exchanger of partner.net: mx1.partner.net'], $response->json('data.transcript'));
        $request = $this->smtp()->assertProbed();
        $this->assertSame(['192.0.2.1', '2001:db8::1'], $request->addresses);
        $this->assertNull($request->username);
    }

    public function test_without_mail_exchangers_or_when_their_lookup_fails_the_host_itself_is_used(): void
    {
        $f = $this->mailcow();
        $plain = $f->relayhost(null, 'relay.example:2525');
        $failing = $f->relayhost(null, 'broken.example');
        $this->dns()->aRecords('relay.example', '192.0.2.2')->aRecords('broken.example', '192.0.2.3')->failing('broken.example', 'MX');

        $this->test($plain)->assertOk()->assertJsonPath('data.host', 'relay.example')->assertJsonPath('data.port', 2525)->assertJsonPath('data.hostSource', 'nextHop');
        $this->test($failing)->assertOk()->assertJsonPath('data.host', 'broken.example')->assertJsonPath('data.port', 25);
    }

    public function test_a_host_without_addresses_stops_at_name_resolution_without_connecting(): void
    {
        $f = $this->mailcow();
        $none = $f->relayhost(null, '[nowhere.example]');
        $temporary = $f->relayhost(null, '[flaky.example]');
        $this->dns()->failing('flaky.example', 'A');

        $this->test($none)->assertOk()
            ->assertJsonPath('data.accepted', false)
            ->assertJsonPath('data.step', 'resolve')
            ->assertJsonPath('data.failure.reason', 'nameNotResolved')
            ->assertJsonPath('data.host', null)
            ->assertJsonPath('data.lastReply', null)
            ->assertJsonPath('data.tls', false);
        $this->test($temporary)->assertOk()->assertJsonPath('data.failure.reason', 'dnsTemporaryFailure');
        $this->smtp()->assertNotProbed();
    }

    public function test_an_ip_literal_is_connected_directly(): void
    {
        $id = $this->mailcow()->relayhost(null, '[192.0.2.10]:25');

        $this->test($id)->assertOk()->assertJsonPath('data.host', '192.0.2.10');
        $this->assertSame(['192.0.2.10'], $this->smtp()->assertProbed()->addresses);
        $this->dns()->assertNothingQueried();
    }

    public function test_a_legacy_host_that_does_not_parse_is_tested_the_legacy_way(): void
    {
        $id = $this->mailcow()->relayhost(null, 'smtp:relay.example.net:2525', 'u\:x', 'x\:y');
        $this->dns()->aRecords('smtp:relay.example.net', '192.0.2.9');

        $this->test($id)->assertOk()->assertJsonPath('data.port', 2525)->assertJsonPath('data.host', 'smtp:relay.example.net');
        $request = $this->smtp()->assertProbed();
        $this->assertSame(['u\:x', 'x\:y'], [$request->username, $request->password]);
    }

    public function test_a_failed_test_is_still_a_successful_request(): void
    {
        $id = $this->mailcow()->relayhost(null, '[smtp.example.net]', 'acct', 'pw');
        $this->dns()->aRecords('smtp.example.net', '192.0.2.25');
        $this->smtp()->willReturn(FakeSmtpProbe::failed('login', 'rejected', 535, 'failed'));

        $this->test($id)->assertOk()
            ->assertJsonPath('data.accepted', false)
            ->assertJsonPath('data.step', 'login')
            ->assertJsonPath('data.failure', ['reason' => 'rejected', 'message' => 'The test failed at login.'])
            ->assertJsonPath('data.login', 'failed')
            ->assertJsonPath('data.lastReply.code', 535);

        [$record] = $this->auditRecords('relayhostTest', 'relayhost', (string) $id);
        $this->assertSame(['sender' => 'relay@example.org', 'recipient' => 'null@hosted.mailcow.de', 'accepted' => false, 'step' => 'login', 'failureReason' => 'rejected'], $record->details);
        $this->assertSame('success', $record->outcome);
    }

    public function test_addresses_are_validated_and_defaults_come_from_the_settings(): void
    {
        $id = $this->mailcow()->relayhost(null, '[192.0.2.10]');

        $this->test($id, ['sender' => 'not-an-address'])->assertStatus(422)
            ->assertJsonPath('errors.sender.0.code', 'invalidFormat')
            ->assertJsonPath('errors.sender.0.params.format', 'email');
        $this->smtp()->assertNotProbed();

        config(['restv2.routing.test_sender' => 'probe@example.com', 'restv2.routing.test_recipient' => 'sink@example.com', 'restv2.routing.test_step_timeout' => 5, 'restv2.routing.test_total_timeout' => 20]);
        $this->test($id)->assertOk()->assertJsonPath('data.sender', 'probe@example.com')->assertJsonPath('data.recipient', 'sink@example.com');
        $request = $this->smtp()->assertProbed();
        $this->assertSame(5.0, $request->stepTimeout);
        $this->assertLessThanOrEqual(20.0, $request->totalTimeout);

        $this->test($id, ['sender' => 'Me@Bücher.Example', 'recipient' => 'you@example.org'])->assertOk()->assertJsonPath('data.sender', 'Me@xn--bcher-kva.example');
    }

    public function test_an_inactive_relayhost_can_be_tested_and_nothing_is_stored(): void
    {
        $id = $this->mailcow()->relayhost(null, '[192.0.2.10]', 'acct', 'pw', 0);
        $before = (array) $this->relayhostRow($id);
        $counts = [DB::table('relayhosts')->count(), DB::table('domain')->count(), DB::table('transports')->count()];

        $this->test($id)->assertOk();

        $this->assertSame($before, (array) $this->relayhostRow($id));
        $this->assertSame($counts, [DB::table('relayhosts')->count(), DB::table('domain')->count(), DB::table('transports')->count()]);
        $this->assertCount(1, $this->auditRecords('relayhostTest', 'relayhost', (string) $id));
        $this->assertArrayNotHasKey('transcript', $this->auditRecords('relayhostTest')[0]->details);
    }

    public function test_refusals(): void
    {
        $id = $this->mailcow()->relayhost(null, '[192.0.2.10]');

        $this->test(999)->assertNotFound();

        $key = $this->apiKey('admin', 'readOnly');
        $this->send('POST', '/api/v2/relayhosts/'.$id.'/connection-test', null, $this->keyHeader($key))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->mailcow()->domain('example.org');
        $this->asRoutingDomainAdmin(['example.org'], ['domain_relayhost' => 1, 'mailbox_relayhost' => 1]);
        $this->test($id)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->smtp()->assertNotProbed();
        $this->assertCount(3, $this->auditRecords('relayhostTest'));
    }
}
