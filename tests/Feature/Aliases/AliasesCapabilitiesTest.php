<?php

namespace Tests\Feature\Aliases;

use App\Auth\Role;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T035 (FR-068): getAliasesCapabilities.
 */
class AliasesCapabilitiesTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    public function test_administrators_see_every_value(): void
    {
        $this->asAdmin();

        $this->assertSame([
            'groupwareEnabled' => true,
            'externalDestinationsDisabled' => false,
            'bulkLimit' => 100,
            'defaultPageSize' => 25,
            'maxPageSize' => 1000,
            'commentMaxBytes' => 160,
            'destinationsMaxLength' => 65535,
            'resourceDescriptionMaxLength' => 255,
            'resourceBookingLimit' => ['min' => 1, 'max' => 2147483647],
            'rateLimitFrames' => ['second', 'minute', 'hour', 'day'],
            'dkimKeySizes' => [1024, 2048, 3072, 4096],
            'dkimDefaults' => ['selector' => 'dkim', 'keySize' => 2048],
            'dkimDnsPublicationEnabled' => false,
        ], $this->getJson('/api/v2/capabilities/aliases')->assertOk()->json('data'));
    }

    public function test_the_values_follow_the_server_configuration(): void
    {
        config([
            'restv2.skip_sogo' => true,
            'restv2.vars_overrides.ALIAS_DISABLE_EXTERNAL_DOMAINS' => 'true',
            'restv2.dkim.default_selector' => 'feldhost',
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => 'secret',
        ]);
        $this->asAdmin();

        $data = $this->getJson('/api/v2/capabilities/aliases')->assertOk()->json('data');

        $this->assertFalse($data['groupwareEnabled']);
        $this->assertTrue($data['externalDestinationsDisabled']);
        $this->assertSame('feldhost', $data['dkimDefaults']['selector']);
        $this->assertTrue($data['dkimDnsPublicationEnabled']);
        $this->assertStringNotContainsString('secret', (string) json_encode($data));
    }

    public function test_domain_administrators_see_dkim_defaults_only_with_the_alias_domains_flag(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainAdmin('with', ['example.org'], ['alias_domains' => 1], scheme: 'SSHA256');
        $this->mailcow()->domainAdmin('without', ['example.org'], ['alias_domains' => 0], scheme: 'SSHA256');

        $this->signInAs('with', Role::DomainAdmin);
        $data = $this->getJson('/api/v2/capabilities/aliases')->assertOk()->json('data');
        $this->assertSame(['selector' => 'dkim', 'keySize' => 2048], $data['dkimDefaults']);
        $this->assertNull($data['dkimDnsPublicationEnabled']);

        $this->signInAs('without', Role::DomainAdmin);
        $this->assertNull($this->getJson('/api/v2/capabilities/aliases')->json('data.dkimDefaults'));

        $this->forgetCookies();
        $this->getJson('/api/v2/capabilities/aliases', $this->keyHeader($this->apiKey('with', 'readOnly')))->assertOk();
    }

    public function test_mailbox_users_are_refused(): void
    {
        $this->asMailboxUser('anna@example.org');

        $this->getJson('/api/v2/capabilities/aliases')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
