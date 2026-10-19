<?php

namespace Tests\Feature\DomainAdmins;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T033 (AS US1-11, US1-12, FR-073, FR-075, FR-076, SC-010, research R15): one action on
 * up to 100 domain administrators, each processed, version-checked and audited on its own.
 */
class DomainAdminBulkActionTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');

        foreach (['r1', 'r2', 'r3'] as $username) {
            $this->mailcow()->domainAdmin($username, ['a.example']);
        }

        $this->asAdmin();
    }

    public function test_outdated_items_are_left_unchanged_while_the_others_proceed(): void
    {
        $tags = collect($this->getJson('/api/v2/domain-admins')->json('data'))->pluck('etag', 'username');
        // Another administrator changed r2 after the list was loaded.
        DB::table('admin')->where('username', 'r2')->update(['attributes' => '{"force_tfa": "1", "force_pw_update": "0"}']);

        $response = $this->bulkAccounts('domain-admins', 'deactivate', [
            ['id' => 'r1', 'version' => $tags['r1']],
            ['id' => 'r2', 'version' => $tags['r2']],
            ['id' => 'r3', 'version' => trim((string) $tags['r3'], '"')],
        ])->assertOk();

        $results = collect($response->json('data.results'))->keyBy('id');
        $this->assertSame('done', $results['r1']['outcome']);
        $this->assertSame('outdated', $results['r2']['outcome']);
        $this->assertSame(412, $results['r2']['problem']['status']);
        $this->assertSame('done', $results['r3']['outcome']);
        $this->assertSame($this->getJson('/api/v2/domain-admins/r1')->json('data.etag'), $results['r1']['version']);
        $this->assertSame(['total' => 3, 'done' => 2, 'notFound' => 0, 'refused' => 0, 'outdated' => 1, 'failed' => 0], $response->json('data.summary'));
        $this->assertAdminRow('r1', 0, 0);
        $this->assertAdminRow('r2', 0, 1, false, true);
        $this->assertAssignments('r3', [['a.example', 0]]);

        $bulkRequestId = $response->json('data.bulkRequestId');
        $records = DB::table('restv2_audit_events')->where('event_type', 'domainAdminUpdate')->orderBy('target_id')->get();
        $this->assertSame(['r1', 'r2', 'r3'], $records->pluck('target_id')->all());
        $this->assertSame(['success', 'failure', 'success'], $records->pluck('outcome')->all());

        foreach ($records as $record) {
            $this->assertSame($bulkRequestId, json_decode((string) $record->details, true)['bulkRequestId']);
        }
    }

    public function test_more_than_the_limit_is_refused_when_invalid(): void
    {
        $this->withoutRequestValidation();
        $targets = array_map(fn (int $i): array => ['id' => 'r'.$i], range(1, 101));

        $this->bulkAccounts('domain-admins', 'deactivate', $targets)->assertUnprocessable()
            ->assertViolation('targets', 'tooMany')
            ->assertViolationParams('targets', ['max' => 100]);
        $this->assertAdminRow('r1', 0, 1);
    }

    public function test_other_actions_are_refused_when_invalid(): void
    {
        $this->withoutRequestValidation();

        foreach (['rename', 'setPassword'] as $action) {
            $this->bulkAccounts('domain-admins', $action, [['id' => 'r1']])->assertUnprocessable()->assertViolation('action', 'notInList');
        }
    }

    public function test_repeated_and_foreign_targets(): void
    {
        $response = $this->bulkAccounts('domain-admins', 'activate', [['id' => 'r1'], ['id' => 'R1'], ['id' => 'admin'], ['id' => 'nobody']])->assertOk();

        $this->assertSame(
            [['r1', 'done'], ['admin', 'notFound'], ['nobody', 'notFound']],
            array_map(fn (array $r): array => [$r['id'], $r['outcome']], $response->json('data.results')),
        );
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'domainAdminUpdate')->where('outcome', 'success')->count());
    }

    public function test_an_account_named_bulk_actions_stays_readable(): void
    {
        $this->mailcow()->domainAdmin('bulk-actions', ['a.example']);

        $this->getJson('/api/v2/domain-admins/bulk-actions')->assertOk()->assertJsonPath('data.username', 'bulk-actions');
    }

    public function test_delete_applies_every_effect_of_a_deletion(): void
    {
        $key = $this->apiKey('r1');
        $this->secondFactorsFor('r1');

        $this->bulkAccounts('domain-admins', 'delete', [['id' => 'r1'], ['id' => 'r2']])->assertOk()->assertJsonPath('data.summary.done', 2);

        $this->assertNothingLeftFor('r1');
        $this->assertNothingLeftFor('r2');
        $this->assertSame('ownerDeleted', DB::table('restv2_api_keys')->where('id', $key['id'])->value('revocation_reason'));
        $this->assertSame(2, DB::table('restv2_audit_events')->where('event_type', 'domainAdminDeletion')->where('outcome', 'success')->count());
    }

    public function test_suspend_second_factors(): void
    {
        $this->secondFactorsFor('r1');

        $response = $this->bulkAccounts('domain-admins', 'suspendSecondFactors', [['id' => 'r1'], ['id' => 'r2']])->assertOk();

        $this->assertSame(['done', 'done'], array_column($response->json('data.results'), 'outcome'));
        $this->assertSame(0, DB::table('tfa')->where('username', 'r1')->where('active', 1)->count());
        $this->assertSame(2, DB::table('restv2_audit_events')->where('event_type', 'domainAdminSecondFactorSuspension')->count());
    }
}
