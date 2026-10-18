<?php

namespace Tests\Feature\Routing\Capabilities;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T061 (FR-093, SC-012, G36): getRoutingCapabilities.
 */
class RoutingCapabilitiesTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_an_administrator_reads_every_value(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/capabilities/routing')->assertOk()->assertExactJson(['data' => [
            'bulkLimit' => 100,
            'pageSize' => ['default' => 25, 'maximum' => 1000],
            'bccMaps' => ['localDestinationMaxLength' => 255, 'bccDestinationMaxLength' => 255],
            'administration' => [
                'connectionTest' => ['defaultSender' => 'relay@example.org', 'defaultRecipient' => 'null@hosted.mailcow.de', 'stepTimeoutSeconds' => 15, 'totalTimeoutSeconds' => 60],
                'maxLengths' => [
                    'host' => 255,
                    'nextHop' => 255,
                    'username' => 255,
                    'password' => 255,
                    'transportDestination' => 255,
                    'recipientMapDestination' => 255,
                    'tlsPolicyDestination' => 255,
                    'tlsPolicyParameters' => 255,
                    'forwardingHost' => 255,
                    'testSender' => 255,
                    'testRecipient' => 255,
                ],
                'tlsPolicies' => ['none', 'may', 'encrypt', 'dane', 'dane-only', 'fingerprint', 'verify', 'secure'],
                'forwardingHostDnsLookupLimit' => 10,
            ],
        ]]);
    }

    public function test_a_changed_setting_shows_on_the_next_request_and_invalid_ones_fall_back(): void
    {
        $this->asAdmin();
        config(['restv2.routing.test_recipient' => 'sink@example.com', 'restv2.routing.test_step_timeout' => '7']);

        $this->getJson('/api/v2/capabilities/routing')->assertOk()
            ->assertJsonPath('data.administration.connectionTest.defaultRecipient', 'sink@example.com')
            ->assertJsonPath('data.administration.connectionTest.stepTimeoutSeconds', 7);

        config(['restv2.routing.test_sender' => 'not-an-address', 'restv2.routing.test_step_timeout' => 0, 'restv2.routing.test_total_timeout' => 3, 'restv2.routing.test_recipient' => 'sink@example.com']);
        $this->getJson('/api/v2/capabilities/routing')->assertOk()
            ->assertJsonPath('data.administration.connectionTest.defaultSender', 'relay@example.org')
            ->assertJsonPath('data.administration.connectionTest.stepTimeoutSeconds', 15)
            ->assertJsonPath('data.administration.connectionTest.totalTimeoutSeconds', 60);
    }

    public function test_domain_administrators_read_the_reduced_set_and_keys_may_read(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asRoutingDomainAdmin(['example.org']);

        $this->getJson('/api/v2/capabilities/routing')->assertOk()->assertJsonPath('data.administration', null)->assertJsonPath('data.bulkLimit', 100);

        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin', 'readOnly');
        $this->getJson('/api/v2/capabilities/routing', $this->keyHeader($key))->assertOk()->assertJsonPath('data.administration.forwardingHostDnsLookupLimit', 10);
    }

    public function test_a_mailbox_user_is_refused(): void
    {
        $this->mailbox('anna@example.org');
        $this->signInAsMailboxUser('anna@example.org');

        $this->getJson('/api/v2/capabilities/routing')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
