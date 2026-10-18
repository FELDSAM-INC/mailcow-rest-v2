<?php

namespace Tests\Feature\Routing\ForwardingHosts;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T088 (FR-076–FR-078, FR-081): createForwardingHost on the real Redis.
 */
class ForwardingHostCreateTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asAdmin();
    }

    /**
     * @return array<string, string>
     */
    private function hash(string $key): array
    {
        /** @var array<string, string> */
        return $this->redis()->hGetAll($key) ?: [];
    }

    public function test_a_prefix_is_added_with_the_spam_filter_enabled_by_default(): void
    {
        $response = $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '192.0.2.0/24'])->assertCreated()
            ->assertHeader('Location', '/api/v2/forwarding-hosts?source=192.0.2.0%2F24')
            ->assertJsonPath('data.source', '192.0.2.0/24')
            ->assertJsonPath('data.entries.0.outcome', 'added')
            ->assertJsonPath('data.entries.0.forwardingHost.address', '192.0.2.0/24')
            ->assertJsonPath('data.entries.0.forwardingHost.spamFilter', true);

        $this->assertSame(['192.0.2.0/24' => '192.0.2.0/24'], $this->hash('WHITELISTED_FWD_HOST'));
        $this->assertSame([], $this->hash('KEEP_SPAM'));
        $this->assertNotEmpty($response->json('data.entries.0.forwardingHost.etag'));

        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '198.51.100.7', 'spamFilter' => false])->assertCreated();
        $this->assertSame(['198.51.100.7' => '1'], $this->hash('KEEP_SPAM'));
    }

    public function test_a_name_is_resolved_through_spf_and_existing_addresses_are_updated(): void
    {
        $this->dns()->txtRecords('forwarder.example', 'v=spf1 ip4:198.51.100.0/24 include:other.example -all')
            ->txtRecords('other.example', 'v=spf1 ip4:203.0.113.7 -all');
        $this->mailcow()->forwardingHost('203.0.113.7', 'other.example', true);

        $response = $this->send('POST', '/api/v2/forwarding-hosts', ['host' => ' forwarder.example ', 'spamFilter' => true])->assertCreated()
            ->assertHeader('Location', '/api/v2/forwarding-hosts?source=forwarder.example')
            ->assertJsonPath('data.source', 'forwarder.example');

        $this->assertSame([['added', '198.51.100.0/24'], ['updated', '203.0.113.7']], array_map(fn (array $e): array => [$e['outcome'], $e['forwardingHost']['address']], (array) $response->json('data.entries')));
        $this->assertSame(['203.0.113.7' => 'forwarder.example', '198.51.100.0/24' => 'forwarder.example'], $this->hash('WHITELISTED_FWD_HOST'));
        $this->assertSame([], $this->hash('KEEP_SPAM'));

        [$record] = $this->auditRecords('forwardingHostCreation', 'forwardingHost', 'forwarder.example');
        $this->assertSame(['entries' => [['address' => '198.51.100.0/24', 'outcome' => 'added'], ['address' => '203.0.113.7', 'outcome' => 'updated']], 'spamFilter' => true], $record->details);
        $this->assertSame('198.51.100.0/24 203.0.113.7', $record->target_name);
    }

    public function test_a_request_whose_addresses_all_exist_answers_ok(): void
    {
        $this->mailcow()->forwardingHost('192.0.2.5', 'old', true);

        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '192.0.2.5'])->assertOk()
            ->assertJsonPath('data.entries.0.outcome', 'updated')
            ->assertJsonPath('data.entries.0.forwardingHost.source', '192.0.2.5')
            ->assertJsonPath('data.entries.0.forwardingHost.spamFilter', true);
        $this->assertSame([], $this->hash('KEEP_SPAM'));
    }

    public function test_fallbacks_to_mail_exchangers_and_host_addresses_and_unresolvable_names(): void
    {
        $this->dns()->mxRecords('mx-only.example', ['mx.mx-only.example' => 10])->aRecords('mx.mx-only.example', '192.0.2.25')
            ->aRecords('plain.example', '192.0.2.80')->aaaaRecords('plain.example', '2001:DB8::80');

        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => 'mx-only.example'])->assertCreated()->assertJsonPath('data.entries.0.forwardingHost.address', '192.0.2.25');
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => 'Plain.Example'])->assertCreated()
            ->assertJsonPath('data.entries.1.forwardingHost.address', '2001:db8::80');
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => 'nothing.example'])->assertStatus(422)->assertJsonPath('errors.host.0.code', 'notResolvable');
    }

    public function test_literal_like_input_and_canonical_forms(): void
    {
        $this->dns()->aRecords('cafe', '192.0.2.99');

        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => 'cafe'])->assertCreated()->assertJsonPath('data.entries.0.forwardingHost.address', '192.0.2.99');
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '192.0.2.5/24'])->assertCreated()->assertJsonPath('data.entries.0.forwardingHost.address', '192.0.2.0/24')->assertJsonPath('data.source', '192.0.2.5/24');
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '198.51.100.5/24'])->assertCreated()->assertJsonPath('data.entries.0.forwardingHost.address', '198.51.100.0/24');
        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '999.1.1.1'])->assertStatus(422)
            ->assertJsonPath('errors.host.0.code', 'invalidFormat')->assertJsonPath('errors.host.0.params.format', 'ipNetwork');
        $this->assertArrayNotHasKey('cafe', $this->hash('WHITELISTED_FWD_HOST'));
    }

    public function test_a_stored_non_canonical_entry_is_updated_in_its_stored_form(): void
    {
        $this->mailcow()->forwardingHost('2001:0db8::0001', 'legacy');

        $this->send('POST', '/api/v2/forwarding-hosts', ['host' => '2001:db8::1', 'spamFilter' => false])->assertOk()
            ->assertJsonPath('data.entries.0.forwardingHost.address', '2001:0db8::0001');

        $this->assertSame(['2001:0db8::0001' => '2001:db8::1'], $this->hash('WHITELISTED_FWD_HOST'));
        $this->assertSame(['2001:0db8::0001' => '1'], $this->hash('KEEP_SPAM'));
    }
}
