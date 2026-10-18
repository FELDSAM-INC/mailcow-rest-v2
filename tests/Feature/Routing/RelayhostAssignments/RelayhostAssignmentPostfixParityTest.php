<?php

namespace Tests\Feature\Routing\RelayhostAssignments;

use Tests\Support\Routing\LegacyRoutingMaps;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T040 (SC-002, FR-025, FR-027): after assignments made through the API, mailcow's own
 * Postfix lookups (`sender_dependent_default_transport_maps` and the sender-dependent SASL password
 * map of 2026-09a) choose the relayhost and the credentials that the API reports as
 * `effectiveRelayhost` and `credentialsRelayhost`.
 */
class RelayhostAssignmentPostfixParityTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->relayhost(3, '[domain-relay.example]:587', 'dom', 'pa:ss');
        $f->relayhost(5, '[mailbox-relay.example]:25', 'box', 'pw5');
        $f->relayhost(6, '[no-login.example]');
        $f->domain('example.org');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('user@example.org');
        $this->mailbox('peer@example.org');
        $f->alias('direct@example.org', 'user@example.org');
        $this->asAdmin();
    }

    /**
     * The next hop part of the transport the mail server chooses for a sender, or null.
     */
    private static function transportOf(string $sender): ?string
    {
        $value = LegacyRoutingMaps::postfix('mysql_sender_dependent_default_transport_maps', $sender)[0] ?? null;

        return $value === null || $value === 'smtp:' ? null : substr($value, strlen('smtp:'));
    }

    private static function credentialsOf(string $sender): ?string
    {
        return LegacyRoutingMaps::postfix('mysql_sasl_passwd_maps_sender_dependent', $sender)[0] ?? null;
    }

    public function test_a_domain_assignment_routes_the_domain_and_its_alias_domain(): void
    {
        $this->send('PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => 3])->assertOk();
        $api = $this->getJson('/api/v2/mailboxes/user@example.org/relayhost-assignment')->json('data');

        foreach (['user@example.org', 'direct@example.org', 'user@example.net', 'anyone@example.org'] as $sender) {
            $this->assertSame('[domain-relay.example]:587', self::transportOf($sender), $sender);
            $this->assertSame('dom:pa:ss', self::credentialsOf($sender), $sender);
        }

        $this->assertSame(['id' => 3, 'host' => '[domain-relay.example]:587', 'source' => 'domain'], $api['effectiveRelayhost']);
        $this->assertSame(['id' => 3, 'source' => 'domain'], $api['credentialsRelayhost']);
    }

    public function test_a_mailbox_assignment_routes_the_mailbox_and_its_direct_aliases_only(): void
    {
        $this->send('PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => 3])->assertOk();
        $api = $this->send('PUT', '/api/v2/mailboxes/user@example.org/relayhost-assignment', ['relayhostId' => 5])->assertOk()->json('data');

        $this->assertSame('[mailbox-relay.example]:25', self::transportOf('user@example.org'));
        $this->assertSame('[mailbox-relay.example]:25', self::transportOf('direct@example.org'));
        $this->assertSame('[domain-relay.example]:587', self::transportOf('user@example.net'));
        $this->assertSame('[domain-relay.example]:587', self::transportOf('peer@example.org'));
        // Credentials are looked up domain first (upstream behaviour the API reports).
        $this->assertSame('dom:pa:ss', self::credentialsOf('user@example.org'));

        $this->assertSame(['id' => 5, 'host' => '[mailbox-relay.example]:25', 'source' => 'mailbox'], $api['effectiveRelayhost']);
        $this->assertSame(['id' => 3, 'source' => 'domain'], $api['credentialsRelayhost']);
    }

    public function test_a_domain_relayhost_without_a_username_suppresses_the_mailbox_login(): void
    {
        $this->send('PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => 6])->assertOk();
        $api = $this->send('PUT', '/api/v2/mailboxes/user@example.org/relayhost-assignment', ['relayhostId' => 5])->assertOk()->json('data');

        $this->assertSame('[mailbox-relay.example]:25', self::transportOf('user@example.org'));
        $this->assertNull(self::credentialsOf('user@example.org'));
        $this->assertNull($api['credentialsRelayhost']);
    }

    public function test_only_a_mailbox_assignment(): void
    {
        $api = $this->send('PUT', '/api/v2/mailboxes/user@example.org/relayhost-assignment', ['relayhostId' => 5])->assertOk()->json('data');

        $this->assertSame('box:pw5', self::credentialsOf('user@example.org'));
        $this->assertNull(self::transportOf('peer@example.org'));
        $this->assertSame(['id' => 5, 'source' => 'mailbox'], $api['credentialsRelayhost']);
    }

    public function test_an_inactive_relayhost_has_no_effect_and_a_cleared_assignment_delivers_directly(): void
    {
        $this->send('PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => 3])->assertOk();
        $this->send('PATCH', '/api/v2/relayhosts/3', ['active' => false])->assertOk();

        $this->assertNull(self::transportOf('user@example.org'));
        $this->assertNull($this->getJson('/api/v2/mailboxes/user@example.org/relayhost-assignment')->json('data.effectiveRelayhost'));

        $this->send('PATCH', '/api/v2/relayhosts/3', ['active' => true])->assertOk();
        $this->send('PUT', '/api/v2/domains/example.org/relayhost-assignment', ['relayhostId' => null])->assertOk();
        $this->assertNull(self::transportOf('user@example.org'));
    }
}
