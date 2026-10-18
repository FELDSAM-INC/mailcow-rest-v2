<?php

namespace Tests\Feature\MtaSts;

use Illuminate\Support\Facades\DB;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T075 (US4, FR-036, FR-058): one policy with its identifier, address, served text and
 * DNS records; foreign domains, alias domains and domains without a policy are `404`.
 */
class GetMtaStsPolicyTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('foreign.example');
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->mailcow()->mtaSts('example.org', 'testing', 'mail.example.org,*.example.net', 604800, 1, '20261018123000');
        $this->mailcow()->mtaSts('foreign.example');
        $this->mailcow()->mtaSts('xn--bcher-kva.example');
    }

    public function test_the_policy_with_its_text_and_records(): void
    {
        config(['restv2.mailcow_hostname' => 'MX.Example.NET']);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mta-sts-policies/example.org')->assertOk()
            ->assertJsonPath('data.policyId', '20261018123000')
            ->assertJsonPath('data.mxHosts', ['mail.example.org', '*.example.net'])
            ->assertJsonPath('data.policyText', "version: STSv1\nmode: testing\nmax_age: 604800\nmx: mail.example.org\nmx: *.example.net\n")
            ->assertJsonPath('data.dnsRecords.0.value', 'v=STSv1;id=20261018123000;')
            ->assertJsonPath('data.dnsRecords.1.value', 'mx.example.net');

        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_a_unicode_name_finds_its_policy_and_a_legacy_change_shows_at_once(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/mta-sts-policies/B%C3%BCcher.example')->assertOk()->assertJsonPath('data.domain', 'xn--bcher-kva.example');

        DB::table('mta_sts')->where('domain', 'example.org')->update(['active' => 0]);
        $this->getJson('/api/v2/mta-sts-policies/example.org')->assertJsonPath('data.active', false);
    }

    public function test_out_of_scope_and_missing_policies_are_not_found(): void
    {
        $this->mailcow()->domain('nopolicy.example');
        $this->asDomainAdmin(['example.org', 'nopolicy.example']);

        $this->getJson('/api/v2/mta-sts-policies/example.org')->assertOk();
        $this->getJson('/api/v2/mta-sts-policies/foreign.example')->assertNotFound();
        $this->getJson('/api/v2/mta-sts-policies/nopolicy.example')->assertNotFound();
        $this->getJson('/api/v2/mta-sts-policies/nothing.example')->assertNotFound();
    }
}
