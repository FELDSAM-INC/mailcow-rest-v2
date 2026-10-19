<?php

namespace Tests\Feature\Admins;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T048 (AS US2-2, FR-077, research R12): the account capabilities report the server's
 * permission flag defaults of a new domain administrator and the limits clients need before they
 * submit; administrators only, keys (read-only too) included.
 */
class AccountCapabilitiesTest extends TestCase
{
    use AccountTestHelpers;

    public function test_the_defaults_and_limits_of_this_server(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/capabilities/accounts')->assertOk();

        $this->assertSame($this->flagDefaults(), $response->json('data.domainAdminPermissionDefaults'));
        $response->assertJsonPath('data.newAccountDefaults', ['active' => true, 'forcePasswordChange' => false, 'forceSecondFactorSetup' => false])
            ->assertJsonPath('data.bulkLimit', 100)
            ->assertJsonPath('data.pageSize', ['default' => 25, 'maximum' => 1000])
            ->assertJsonPath('data.usernameMaxLength', 255)
            ->assertJsonPath('data.ssoTokenLifetime', 30);
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
        $this->assertSame(0, DB::table('da_acl')->count());
    }

    public function test_a_read_only_administrator_key_may_read_them(): void
    {
        $this->mailcow()->admin('admin', 'x');

        $this->getJson('/api/v2/capabilities/accounts', $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
    }

    public function test_domain_administrators_and_mailbox_users_are_forbidden(): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->mailcow()->mailboxWithDomain('bob@a.example');

        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->getJson('/api/v2/capabilities/accounts')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->getJson('/api/v2/capabilities/accounts', $this->keyHeader($this->apiKey('reseller1')))->assertForbidden();
        $this->signInAs('bob@a.example', Role::MailboxUser);
        $this->getJson('/api/v2/capabilities/accounts')->assertForbidden();
        $this->forgetCookies();
        $this->getJson('/api/v2/capabilities/accounts')->assertUnauthorized();
    }
}
