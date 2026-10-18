<?php

namespace Tests\Feature\Dkim;

use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T036 (FR-012, US4 AS8, G4): the server values and limits that the DKIM, MTA-STS and
 * diagnostics forms need, read from the current configuration, without secrets or connection
 * details. Domain administrators do not learn the export setting and get no server-wide checks.
 */
class DnsCapabilitiesTest extends TestCase
{
    use DkimTestHelpers;

    public function test_an_administrator_gets_every_value(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/capabilities/dns')->assertOk();

        $response->assertExactJson(['data' => [
            'serverHostname' => 'mail.example.org',
            'defaultPageSize' => 25,
            'maxPageSize' => 1000,
            'dkim' => [
                'defaultSelector' => 'dkim',
                'defaultKeySize' => 2048,
                'allowedKeySizes' => [1024, 2048, 3072, 4096],
                'selectorMaxLength' => 255,
                'privateKeyMaxBytes' => 16384,
                'importKeyLengthMin' => 1024,
                'importKeyLengthMax' => 8192,
                'bulkLimit' => 100,
                'dnsPublicationEnabled' => false,
                'privateKeyExportEnabled' => false,
            ],
            'mtaSts' => [
                'version' => 'STSv1',
                'modes' => ['enforce', 'testing', 'none'],
                'defaultActive' => false,
                'maxAgeMin' => 1,
                'maxAgeMax' => 31557600,
                'mxMaxLength' => 255,
            ],
            'dnsDiagnostics' => [
                'runsPerMinute' => 10,
                'lookupTimeoutSeconds' => 5,
                'runTimeoutSeconds' => 30,
                'serverChecks' => true,
            ],
        ]]);
    }

    public function test_server_settings_are_reflected(): void
    {
        config([
            'restv2.mailcow_hostname' => 'MX.Example.NET',
            'restv2.dkim.default_selector' => 'feldhost',
            'restv2.dns.runs_per_minute' => 4,
            'restv2.dns.lookup_timeout' => 3,
            'restv2.dns.run_timeout' => 20,
            'restv2.vars_overrides.SHOW_DKIM_PRIV_KEYS' => 'true',
        ]);
        $this->asAdmin();

        $this->getJson('/api/v2/capabilities/dns')->assertOk()
            ->assertJsonPath('data.serverHostname', 'mx.example.net')
            ->assertJsonPath('data.dkim.defaultSelector', 'feldhost')
            ->assertJsonPath('data.dkim.privateKeyExportEnabled', true)
            ->assertJsonPath('data.dnsDiagnostics.runsPerMinute', 4)
            ->assertJsonPath('data.dnsDiagnostics.lookupTimeoutSeconds', 3)
            ->assertJsonPath('data.dnsDiagnostics.runTimeoutSeconds', 20);
    }

    public function test_the_publication_counts_as_enabled_only_when_complete(): void
    {
        $this->asAdmin();

        config(['restv2.dkim.dns_publication' => true]);
        $this->getJson('/api/v2/capabilities/dns')->assertJsonPath('data.dkim.dnsPublicationEnabled', false);

        $this->enablePublication();
        $response = $this->getJson('/api/v2/capabilities/dns')->assertJsonPath('data.dkim.dnsPublicationEnabled', true);

        $body = (string) $response->baseResponse->getContent();
        $this->assertStringNotContainsString('ispconfig.example', $body);
        $this->assertStringNotContainsString('remote', $body);
        $this->assertStringNotContainsString('IspSecret-42', $body);
    }

    public function test_a_domain_administrator_gets_no_export_setting_and_no_server_checks(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/capabilities/dns')->assertOk()
            ->assertJsonPath('data.dkim.privateKeyExportEnabled', null)
            ->assertJsonPath('data.dkim.defaultSelector', 'dkim')
            ->assertJsonPath('data.dnsDiagnostics.serverChecks', false);
    }

    public function test_a_read_only_key_may_read_and_a_mailbox_user_may_not(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');

        $this->getJson('/api/v2/capabilities/dns', $this->keyHeader($key))->assertOk();

        $this->asMailboxUser();
        $this->getJson('/api/v2/capabilities/dns')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
