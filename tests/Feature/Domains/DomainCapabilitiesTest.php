<?php

namespace Tests\Feature\Domains;

use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T040 (FR-028, FR-064, SC-012, US2 AS-11): getDomainsCapabilities reflects the
 * server's configuration on every request and contains no secret.
 */
class DomainCapabilitiesTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    public function test_administrators_get_every_value(): void
    {
        $this->asAdmin();

        $this->assertSame([
            'groupwareEnabled' => true,
            'taggingLimit' => 25,
            'maxTagLength' => 255,
            'maxDescriptionLength' => 255,
            'footerTextMaxBytes' => 1048576,
            'bulkLimit' => 100,
            'defaultPageSize' => 25,
            'maxPageSize' => 1000,
            'quotaUnit' => 1048576,
            'dkim' => [
                'defaultSelector' => 'dkim',
                'defaultKeySize' => 2048,
                'allowedKeySizes' => [1024, 2048, 3072, 4096],
                'dnsPublicationEnabled' => false,
            ],
            'templateDefaults' => [
                'tags' => [],
                'maxMailboxes' => 10,
                'maxAliases' => 400,
                'defaultMailboxQuota' => 3221225472,
                'maxMailboxQuota' => 10737418240,
                'domainQuota' => 10737418240,
                'rateLimit' => null,
                'active' => true,
                'globalAddressList' => true,
                'backupMx' => false,
                'relayAllRecipients' => false,
                'relayUnknownOnly' => false,
                'dkimSelector' => 'dkim',
                'dkimKeySize' => 2048,
            ],
        ], $this->getJson('/api/v2/capabilities/domains')->assertOk()->json('data'));
    }

    public function test_the_template_defaults_are_what_a_new_template_gets(): void
    {
        // FR-068 (G29).
        config(['restv2.dkim.default_selector' => 'feldhost']);
        $this->asAdmin();

        $defaults = $this->getJson('/api/v2/capabilities/domains')->assertOk()->json('data.templateDefaults');
        $template = $this->postJson('/api/v2/domain-templates', ['name' => 'Fresh'], $this->csrf())->assertCreated()->json('data');

        $this->assertSame('feldhost', $defaults['dkimSelector']);
        foreach ($defaults as $member => $value) {
            $this->assertSame($value, $template[$member], $member);
        }
    }

    public function test_the_values_follow_the_configuration(): void
    {
        config([
            'restv2.skip_sogo' => true,
            'restv2.dkim.default_selector' => 'feldhost',
            'restv2.vars_overrides.TAGGING_LIMIT' => '30',
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example:8080/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => 'Sup3r-Secret',
        ]);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/capabilities/domains')->assertOk();

        $this->assertFalse($response->json('data.groupwareEnabled'));
        $this->assertSame(30, $response->json('data.taggingLimit'));
        $this->assertSame('feldhost', $response->json('data.dkim.defaultSelector'));
        $this->assertTrue($response->json('data.dkim.dnsPublicationEnabled'));
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('ispconfig.example', $body);
        $this->assertStringNotContainsString('remote', $body);
        $this->assertStringNotContainsString('Sup3r-Secret', $body);

        config(['restv2.ispconfig.url' => '']);
        $this->assertFalse($this->getJson('/api/v2/capabilities/domains')->json('data.dkim.dnsPublicationEnabled'));
    }

    public function test_domain_administrators_get_no_dkim_values_and_read_only_keys_may_read(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $data = $this->getJson('/api/v2/capabilities/domains')->assertOk()->json('data');
        $this->assertArrayNotHasKey('dkim', $data);
        $this->assertArrayNotHasKey('templateDefaults', $data);
        $this->assertSame(25, $data['taggingLimit']);

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');
        $this->getJson('/api/v2/capabilities/domains', $this->keyHeader($key))->assertOk()->assertJsonPath('data.dkim.defaultKeySize', 2048);
    }

    public function test_mailbox_users_are_forbidden(): void
    {
        $this->asMailboxUser();

        $this->getJson('/api/v2/capabilities/domains')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
