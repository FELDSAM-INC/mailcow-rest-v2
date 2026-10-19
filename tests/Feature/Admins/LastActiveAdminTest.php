<?php

namespace Tests\Feature\Admins;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Accounts\AdminAccountService;
use App\Support\Http\ProblemType;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T071 (AS US4-3, FR-028, research R8, edge cases): no change and no deletion leaves the
 * server without an active administrator; changes that do not reduce their number are never
 * refused.
 */
class LastActiveAdminTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->admin('bob', 'x', active: 0);
        $this->signInAs('alice', Role::Admin);
    }

    public function test_the_last_active_administrator_cannot_deactivate_itself(): void
    {
        $this->patchAdmin('alice', ['active' => false])->assertConflict()->assertJsonPath('type', '/api/v2/problems/last-active-admin');

        $this->assertAdminRow('alice', 1, 1);
    }

    public function test_the_last_active_administrator_cannot_be_deleted(): void
    {
        $principal = new Principal('bob', Role::Admin, AccountKind::Admin, AuthMethod::Password);

        try {
            $this->app->make(AdminAccountService::class)->delete($principal, 'alice', null);
            $this->fail('The deletion of the last active administrator was not refused.');
        } catch (ProblemException $e) {
            $this->assertSame(ProblemType::LastActiveAdmin, $e->type);
        }

        $this->assertAdminRow('alice', 1, 1);
    }

    public function test_changes_that_do_not_reduce_the_active_administrators_are_never_refused(): void
    {
        $this->patchAdmin('bob', ['active' => false])->assertOk();
        $this->patchAdmin('bob', ['forcePasswordChange' => true])->assertOk();
        $this->patchAdmin('alice', ['forceSecondFactorSetup' => false])->assertOk();
    }

    public function test_self_deactivation_with_another_active_administrator_ends_the_own_session(): void
    {
        $this->patchAdmin('bob', ['active' => true])->assertOk();

        $this->patchAdmin('alice', ['active' => false])->assertOk()->assertJsonPath('data.active', false);

        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_a_rename_with_the_deactivation_of_the_last_active_administrator_changes_nothing(): void
    {
        $this->patchAdmin('alice', ['username' => 'alicia', 'active' => false])->assertConflict()->assertJsonPath('type', '/api/v2/problems/last-active-admin');

        $this->assertAdminRow('alice', 1, 1);
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'alice');
    }
}
