<?php

namespace Tests\Feature\Accounts;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T094 (SC-004, FR-036): after an administrator or a domain administrator is deleted or
 * renamed through the API, a new account with the old username inherits no session, key, passkey,
 * second factor, preference, permission, impersonation or token.
 */
class AccountReuseTest extends TestCase
{
    use AccountTestHelpers;

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'administrator deleted' => ['admins', 'delete'],
            'administrator renamed' => ['admins', 'rename'],
            'domain administrator deleted' => ['domain-admins', 'delete'],
            'domain administrator renamed' => ['domain-admins', 'rename'],
        ];
    }

    #[DataProvider('cases')]
    public function test_a_new_account_with_the_old_name_inherits_nothing(string $collection, string $change): void
    {
        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->admin('alice', 'x');
        $domainAdmin = $collection === 'domain-admins';
        $domainAdmin ? $this->mailcow()->domainAdmin('old', ['a.example'], ['login_as' => 0, 'unlimited_quota' => 1]) : $this->mailcow()->admin('old', 'x');
        $this->secondFactorsFor('old');
        $this->preferencesFor('old');
        $this->mailcow()->daSso('old', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $key = $this->apiKey('old');
        DB::table('restv2_api_keys')->update(['owner_created_at' => DB::table('admin')->where('username', 'old')->value('created')]);
        $oldSession = $this->signInAs('old', $domainAdmin ? Role::DomainAdmin : Role::Admin);
        $impersonating = null;

        if ($domainAdmin) {
            $impersonating = $this->signInAs('alice', Role::Admin);
            $this->impersonate('domainAdmin', 'old')->assertCreated();
            $impersonating = $this->sessionCookie();
        }

        $this->signInAs('alice', Role::Admin);

        if ($change === 'delete') {
            $this->deleteJson("/api/v2/{$collection}/old", [], $this->csrf())->assertNoContent();
        } else {
            $this->patchMerge("/api/v2/{$collection}/old", ['username' => 'renamed'], $this->csrf())->assertOk();
        }

        $body = ['username' => 'old', 'password' => 'Passw0rd!x'] + ($domainAdmin ? ['domains' => ['a.example']] : []);
        $this->postJson("/api/v2/{$collection}", $body, $this->csrf())->assertCreated()
            ->assertJsonPath('data.secondFactorStatus', 'none')
            ->assertJsonPath('data.activePasskeyCount', 0);

        if ($domainAdmin) {
            $this->getJson('/api/v2/domain-admins/old')->assertJsonPath('data.permissions.loginAs', true)->assertJsonPath('data.permissions.unlimitedQuota', false);
        }

        $this->assertFalse(DB::table('restv2_preferences')->where('username', 'old')->exists());
        $this->assertFalse(DB::table('da_sso')->where('username', 'old')->exists());
        $this->assertFalse(DB::table('restv2_api_keys')->where('owner_username', 'old')->whereNull('revoked_at')->exists());
        $this->forgetCookies();

        if ($change === 'delete') {
            $this->getJson('/api/v2/me', $this->keyHeader($key))->assertUnauthorized();
        } else {
            // The key moved with the renamed account; it never acts for the new one.
            $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk()->assertJsonPath('data.username', 'renamed');
        }

        $this->useSessionCookie($oldSession);
        $this->getJson('/api/v2/me')->assertUnauthorized();

        if ($impersonating !== null) {
            $this->useSessionCookie($impersonating);
            $this->getJson('/api/v2/me')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/impersonation-ended');
        }
    }
}
