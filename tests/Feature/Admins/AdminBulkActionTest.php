<?php

namespace Tests\Feature\Admins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T073 (AS US4-9, US4-10, FR-075, FR-076): bulk actions on administrators, in a signed-in
 * session only, processed in order with the last-administrator and own-account rules per item.
 */
class AdminBulkActionTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['alice', 'bob', 'carol'] as $username) {
            $this->mailcow()->admin($username, 'x');
        }

        $this->signInAs('alice', Role::Admin);
    }

    public function test_the_item_that_would_leave_no_active_administrator_is_refused(): void
    {
        $response = $this->bulkAccounts('admins', 'deactivate', [['id' => 'carol'], ['id' => 'bob'], ['id' => 'alice']])->assertOk();

        $results = $response->json('data.results');
        $this->assertSame(['done', 'done', 'refused'], array_column($results, 'outcome'));
        $this->assertSame('/api/v2/problems/last-active-admin', $results[2]['problem']['type']);
        $this->assertSame(1, DB::table('admin')->where('active', 1)->count());
        $this->assertAdminRow('alice', 1, 1);
    }

    public function test_the_own_account_is_refused_in_a_bulk_deletion(): void
    {
        $response = $this->bulkAccounts('admins', 'delete', [['id' => 'alice'], ['id' => 'bob'], ['id' => 'nobody']])->assertOk();

        $this->assertSame([['alice', 'refused'], ['bob', 'done'], ['nobody', 'notFound']], array_map(fn (array $r): array => [$r['id'], $r['outcome']], $response->json('data.results')));
        $this->assertSame('/api/v2/problems/cannot-delete-own-account', $response->json('data.results.0.problem.type'));
        $this->assertNothingLeftFor('bob');
        $this->assertAdminRow('alice', 1, 1);
    }

    public function test_keys_are_refused_as_a_whole(): void
    {
        $body = ['action' => 'activate', 'targets' => [['id' => 'bob']]];

        $this->postJson('/api/v2/admins/bulk-actions', $body, $this->keyHeader($this->apiKey('alice')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->postJson('/api/v2/admins/bulk-actions', $body, $this->keyHeader($this->apiKey('alice', 'readOnly')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }

    public function test_outdated_and_foreign_items(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $tag = (string) $this->getJson('/api/v2/admins/bob')->json('data.etag');
        DB::table('admin')->where('username', 'bob')->update(['attributes' => '{"force_tfa": "1", "force_pw_update": "0"}']);

        $response = $this->bulkAccounts('admins', 'suspendSecondFactors', [['id' => 'bob', 'version' => $tag], ['id' => 'reseller1'], ['id' => 'carol']])->assertOk();

        $this->assertSame(['outdated', 'notFound', 'done'], array_column($response->json('data.results'), 'outcome'));
    }

    public function test_an_administrator_named_bulk_actions_stays_readable(): void
    {
        $this->mailcow()->admin('bulk-actions', 'x');

        $this->getJson('/api/v2/admins/bulk-actions')->assertOk()->assertJsonPath('data.username', 'bulk-actions');
    }
}
