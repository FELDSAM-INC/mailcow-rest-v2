<?php

namespace Tests\Feature\ApiKeys;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * T085 (FR-057, AS-10, R19): the active administrators and domain administrators that can own a
 * key, for the key form. Administrators only, keys of administrators included.
 */
class ApiKeyOwnerCandidatesTest extends TestCase
{
    use ApiKeys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->admin('res-admin', 'x');
        $this->mailcow()->admin('retired', 'x', active: 0);
        $this->mailcow()->domainAdmin('reseller', []);
        $this->mailcow()->domainAdmin('res_old', [], active: 0);
        $this->mailcow()->domainAdmin('zeta', []);
        // A mailbox user is never a candidate, also when its address contains the search term.
        $this->mailcow()->mailboxWithDomain('resident@example.org');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function candidates(string $query = '', array $headers = []): TestResponse
    {
        return $this->getJson('/api/v2/api-keys/owner-candidates'.($query === '' ? '' : '?'.$query), $headers);
    }

    public function test_an_admin_lists_the_active_admins_and_domain_admins(): void
    {
        $this->signInAs('admin', Role::Admin);

        $response = $this->candidates()->assertOk();

        $response->assertJsonPath('data', [
            ['username' => 'admin', 'role' => 'admin'],
            ['username' => 'res-admin', 'role' => 'admin'],
            ['username' => 'reseller', 'role' => 'domainAdmin'],
            ['username' => 'zeta', 'role' => 'domainAdmin'],
        ]);
        $response->assertJsonPath('meta', ['total' => 4, 'limit' => 25, 'offset' => 0]);
        $this->assertSame(0, DB::table('restv2_audit_events')->count(), 'reads are not audited');
    }

    public function test_search_role_filter_and_paging(): void
    {
        $this->signInAs('admin', Role::Admin);
        $names = fn (TestResponse $r): array => array_column($r->assertOk()->json('data'), 'username');

        $this->assertSame(['res-admin', 'reseller'], $names($this->candidates('q=RES')));
        $this->assertSame([], $names($this->candidates('q='.urlencode('res%'))));
        $this->assertSame([], $names($this->candidates('q='.urlencode('res_'))));
        $this->assertSame(['reseller', 'zeta'], $names($this->candidates('role=domainAdmin')));
        $this->assertSame(['admin', 'res-admin'], $names($this->candidates('role=admin')));
        $this->assertSame(['zeta', 'reseller'], $names($this->candidates('role=domainAdmin&order=desc')));

        $page = $this->candidates('limit=2&offset=1');
        $this->assertSame(['res-admin', 'reseller'], $names($page));
        $page->assertJsonPath('meta', ['total' => 4, 'limit' => 2, 'offset' => 1]);
    }

    public function test_invalid_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->signInAs('admin', Role::Admin);

        $this->candidates('role=mailboxUser')->assertUnprocessable()->assertJsonPath('errors.role.0.code', 'notInList');
        $this->candidates('sort=role')->assertUnprocessable()->assertJsonPath('errors.sort.0.code', 'notInList');
        $this->candidates('limit=0')->assertUnprocessable()->assertJsonPath('errors.limit.0.code', 'outOfRange');
    }

    public function test_admin_keys_may_list_them(): void
    {
        $this->candidates('', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk()->assertJsonPath('meta.total', 4);
        $this->candidates('', $this->keyHeader($this->apiKey('admin', 'readWrite')))->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_other_callers_are_forbidden(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->candidates()->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->signInAs('resident@example.org', Role::MailboxUser);
        $this->candidates()->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->candidates('', $this->keyHeader($this->apiKey('reseller')))->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->candidates()->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
    }

    public function test_the_path_is_not_taken_for_a_key_id(): void
    {
        $this->signInAs('admin', Role::Admin);

        // A key ID is a ULID; "owner-candidates" never reaches getApiKey.
        $this->candidates()->assertOk()->assertJsonStructure(['data', 'meta']);
    }
}
