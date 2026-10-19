<?php

namespace Tests\Feature\DomainAdmins;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T035 (FR-032): a domain administrator left without a domain by feature 002's domain
 * deletion gets the full deletion cascade, audited as its own deletion in the same request.
 */
class DomainDeletionCascadeTest extends TestCase
{
    use AccountTestHelpers;

    public function test_a_domain_administrator_left_without_a_domain_is_deleted_with_the_full_cascade(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('b.example');
        $this->mailcow()->domainAdmin('only-a', ['a.example']);
        $this->mailcow()->domainAdmin('both', ['a.example', 'b.example']);
        $this->secondFactorsFor('only-a');
        $this->mailcow()->daSso('only-a', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $this->preferencesFor('only-a');
        $key = $this->apiKey('only-a');
        $this->asAdmin();

        $response = $this->deleteJson('/api/v2/domains/a.example', [], $this->csrf())->assertNoContent();

        $this->assertNothingLeftFor('only-a');
        $this->assertSame('ownerDeleted', DB::table('restv2_api_keys')->where('id', $key['id'])->value('revocation_reason'));
        $record = $this->assertAudit('domainAdminDeletion', 'success', 'domainAdmin', 'only-a', [
            'trigger' => 'domainDeletion',
            'removedSecondFactors' => 2,
            'removedPasskeys' => 2,
            'removedSsoTokens' => 1,
            'revokedApiKeyIds' => [$key['id']],
            'domains' => ['a.example'],
        ]);
        $domainDeletion = DB::table('restv2_audit_events')->where('event_type', 'domainDeletion')->sole();
        $this->assertSame($domainDeletion->request_id, $record->request_id);
        $this->assertSame(['only-a'], json_decode((string) $domainDeletion->details, true)['removedDomainAdmins']);

        $this->assertAdminRow('both', 0, 1);
        $this->assertAssignments('both', [['b.example', 1]]);
        unset($response);
    }
}
