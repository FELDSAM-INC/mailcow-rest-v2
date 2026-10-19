<?php

namespace Tests\Feature\DomainAdmins;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T027 (FR-003, FR-007, FR-009, FR-013, FR-041): one domain administrator by its
 * username in any letter case, with its version tag; the wrong role and an unknown name answer the
 * same "not found".
 */
class GetDomainAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->asAdmin();
    }

    public function test_any_letter_case_finds_the_account(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);

        $response = $this->getJson('/api/v2/domain-admins/ReSeller1')->assertOk();

        $response->assertJsonPath('data.username', 'reseller1')
            ->assertJsonPath('data.modifiedAt', null)
            ->assertJsonPath('data.domains.0.domainUnicode', 'a.example');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
    }

    public function test_an_administrator_and_an_unknown_name_answer_the_same_not_found(): void
    {
        $administrator = $this->getJson('/api/v2/domain-admins/admin')->assertNotFound()->json();
        $unknown = $this->getJson('/api/v2/domain-admins/nobody')->assertNotFound()->json();

        unset($administrator['requestId'], $unknown['requestId']);
        $this->assertSame($unknown, $administrator);
        $this->assertSame('about:blank', $unknown['type']);
    }

    public function test_second_factors_and_passkeys_are_reflected(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->secondFactorsFor('reseller1', totpActive: 0, webauthnActive: 0, passkeys: 2);
        DB::table('fido2')->where('username', 'reseller1')->limit(1)->update(['active' => 0]);
        DB::table('admin')->where('username', 'reseller1')->update(['modified' => '2026-05-01 12:00:00']);

        $this->getJson('/api/v2/domain-admins/reseller1')->assertOk()
            ->assertJsonPath('data.secondFactorStatus', 'suspended')
            ->assertJsonPath('data.activePasskeyCount', 1)
            ->assertJsonPath('data.modifiedAt', '2026-05-01T10:00:00Z');
    }

    public function test_a_missing_permission_record_reads_as_every_flag_off(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example'], withDaAcl: false);

        $permissions = $this->getJson('/api/v2/domain-admins/reseller1')->assertOk()->json('data.permissions');

        $this->assertCount(19, $permissions);
        $this->assertSame([false], array_values(array_unique($permissions)));
    }
}
