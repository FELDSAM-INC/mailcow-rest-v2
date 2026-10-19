<?php

namespace Tests\Feature\Admins;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T065 (FR-006, FR-008 to FR-011): the administrator list with paging, search, filters
 * and sorts; domain administrators never appear; read-only administrator keys may read it.
 */
class ListAdminsTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->mailcow()->admin('admin', 'x', created: '2026-01-02 00:00:00');
        $this->mailcow()->admin('bob', 'x', active: 0, attributes: ['force_pw_update' => '1'], created: '2026-01-01 00:00:00');
        $this->mailcow()->admin('carol', 'x', attributes: ['force_tfa' => '1'], created: '2026-01-02 00:00:00');
        $this->mailcow()->tfaTotp('admin', 'S', 'Phone', 1);
        $this->mailcow()->tfaTotp('bob', 'S', 'Phone', 0);
        $this->asAdmin();
    }

    /**
     * @return list<string>
     */
    private function usernames(string $query = ''): array
    {
        return array_column($this->getJson('/api/v2/admins'.$query)->assertOk()->json('data'), 'username');
    }

    public function test_pages_carry_the_total_and_full_representations(): void
    {
        $page = $this->getJson('/api/v2/admins?limit=2')->assertOk();

        $page->assertJsonPath('meta', ['total' => 3, 'limit' => 2, 'offset' => 0])->assertJsonCount(2, 'data');
        $this->assertSame($this->getJson('/api/v2/admins/admin')->json('data'), $page->json('data.0'));
        $this->assertArrayNotHasKey('domains', $page->json('data.0'));
        $this->assertSame(['admin', 'bob', 'carol'], $this->usernames());
    }

    public function test_search_filters_and_sorts(): void
    {
        $this->assertSame(['carol'], $this->usernames('?q=AROL'));
        $this->assertSame(['bob'], $this->usernames('?active=false'));
        $this->assertSame(['admin'], $this->usernames('?secondFactorStatus=active'));
        $this->assertSame(['bob'], $this->usernames('?secondFactorStatus=suspended'));
        $this->assertSame(['carol'], $this->usernames('?secondFactorStatus=none'));
        $this->assertSame(['bob'], $this->usernames('?forcePasswordChange=true'));
        $this->assertSame(['carol'], $this->usernames('?forceSecondFactorSetup=true'));
        $this->assertSame(['admin'], $this->usernames('?forceSecondFactorSetup=false&active=true'));
        $this->assertSame(['bob', 'admin', 'carol'], $this->usernames('?sort=createdAt'));
        $this->assertSame(['admin', 'carol', 'bob'], $this->usernames('?sort=createdAt&order=desc'));
        $this->assertSame(['bob', 'admin', 'carol'], $this->usernames('?sort=active'));
        $this->assertSame(['carol', 'bob', 'admin'], $this->usernames('?order=desc'));
    }

    public function test_a_read_only_administrator_key_may_list_them(): void
    {
        $this->forgetCookies();

        $this->getJson('/api/v2/admins', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk()->assertJsonPath('meta.total', 3);
    }

    public function test_one_administrator_in_any_letter_case(): void
    {
        DB::table('admin')->where('username', 'carol')->update(['modified' => '2026-03-01 09:30:00']);

        $response = $this->getJson('/api/v2/admins/CAROL')->assertOk();

        $response->assertJsonPath('data.username', 'carol')
            ->assertJsonPath('data.forceSecondFactorSetup', true)
            ->assertJsonPath('data.modifiedAt', '2026-03-01T08:30:00Z');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->getJson('/api/v2/admins/reseller1')->assertNotFound();
        $this->getJson('/api/v2/admins/nobody')->assertNotFound();
    }
}
