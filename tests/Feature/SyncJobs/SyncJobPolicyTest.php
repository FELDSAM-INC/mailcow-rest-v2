<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\ApiKeyReference;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Policies\SyncJobPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Feature 008 T011 (FR-001, research R2): the sync-jobs capability is gated by the effective
 * `syncjobs` flag of 001's IdentityService (legacy acl('to_session')), evaluated from mailcow's
 * data on every call. A refusal is `permission-flag-required` with `requiredFlags: [syncjobs]`.
 */
class SyncJobPolicyTest extends TestCase
{
    private static function principal(string $username, Role $role, ?ApiKeyReference $key = null): Principal
    {
        return new Principal($username, $role, $role->accountKind(), $key === null ? AuthMethod::Password : AuthMethod::ApiKey, apiKey: $key);
    }

    private function allows(Principal $principal): bool
    {
        return Gate::forUser($principal)->allows('syncJobs.use');
    }

    public function test_an_administrator_always_holds_the_flag(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');

        $this->assertTrue($this->allows(self::principal('admin', Role::Admin)));
    }

    public function test_a_domain_administrator_holds_it_with_da_acl_syncjobs(): void
    {
        $this->mailcow()->domainAdmin('with', ['example.org'], ['syncjobs' => 1], scheme: 'SSHA256');
        $this->mailcow()->domainAdmin('without', ['example.org'], ['syncjobs' => 0], scheme: 'SSHA256');

        $this->assertTrue($this->allows(self::principal('with', Role::DomainAdmin)));
        $this->assertFalse($this->allows(self::principal('without', Role::DomainAdmin)));
    }

    public function test_a_domain_administrator_without_a_da_acl_row_gets_the_schema_default(): void
    {
        // 001 FR-028: the row legacy inserts for a new domain administrator; da_acl.syncjobs
        // defaults to 1 (legacy acl('to_session') keeps the user_acl flag syncjobs on as well).
        $this->mailcow()->domainAdmin('norow', ['example.org'], scheme: 'SSHA256');
        DB::table('da_acl')->where('username', 'norow')->delete();

        $this->assertTrue($this->allows(self::principal('norow', Role::DomainAdmin)));
    }

    public function test_a_mailbox_user_holds_it_only_with_user_acl_syncjobs(): void
    {
        $this->mailcow()->mailboxWithDomain('with@example.org', userAcl: ['syncjobs' => 1]);
        $this->mailcow()->mailboxWithDomain('without@example.org', userAcl: ['syncjobs' => 0]);
        $this->mailcow()->mailboxWithDomain('norow@example.org');
        DB::table('user_acl')->where('username', 'norow@example.org')->delete();

        $this->assertTrue($this->allows(self::principal('with@example.org', Role::MailboxUser)));
        $this->assertFalse($this->allows(self::principal('without@example.org', Role::MailboxUser)));
        $this->assertFalse($this->allows(self::principal('norow@example.org', Role::MailboxUser)));
    }

    public function test_a_key_is_evaluated_through_its_owner(): void
    {
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['syncjobs' => 0], scheme: 'SSHA256');
        $key = new ApiKeyReference('01JABCDEFGHJKMNPQRSTVWXYZ0', 'billing', 'mcv2_abcdefg', 'readOnly');

        $this->assertFalse($this->allows(self::principal('reseller', Role::DomainAdmin, $key)));
        DB::table('da_acl')->where('username', 'reseller')->update(['syncjobs' => 1]);
        $this->assertTrue($this->allows(self::principal('reseller', Role::DomainAdmin, $key)));
    }

    public function test_a_flag_change_takes_effect_on_the_next_check(): void
    {
        $this->mailcow()->mailboxWithDomain('user@example.org', userAcl: ['syncjobs' => 1]);
        $user = self::principal('user@example.org', Role::MailboxUser);

        $this->assertTrue($this->allows($user));
        DB::table('user_acl')->where('username', 'user@example.org')->update(['syncjobs' => 0]);
        $this->assertFalse($this->allows($user));
    }

    public function test_a_refusal_names_the_syncjobs_flag(): void
    {
        $this->mailcow()->mailboxWithDomain('user@example.org');

        try {
            $this->app->make(SyncJobPolicy::class)->authorize(self::principal('user@example.org', Role::MailboxUser));
            $this->fail('The gate let a caller without the flag through.');
        } catch (PermissionFlagRequiredException $e) {
            $this->assertSame(['syncjobs'], $e->extensions['requiredFlags']);
            $this->assertSame('/api/v2/problems/permission-flag-required', $e->type->uri());
        }
    }
}
