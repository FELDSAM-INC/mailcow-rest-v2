<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Mailboxes\MailboxScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 003 T009 (FR-001–FR-003): administrators reach every mailbox, domain administrators the
 * mailboxes of their actively assigned domains, nobody a resource; everything else is "not found".
 */
class MailboxScopeTest extends TestCase
{
    private static function principal(Role $role, string $username): Principal
    {
        return new Principal($username, $role, $role === Role::MailboxUser ? AccountKind::Mailbox : AccountKind::Admin, AuthMethod::Password);
    }

    private function scope(): MailboxScope
    {
        return $this->app->make(MailboxScope::class);
    }

    public function test_an_administrator_resolves_every_mailbox(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@a.example');
        $this->mailcow()->mailboxWithDomain('bob@b.example', domainActive: 0);

        $admin = self::principal(Role::Admin, 'admin');

        $this->assertSame('anna@a.example', $this->scope()->resolve($admin, ' Anna@A.Example '));
        $this->assertSame('bob@b.example', $this->scope()->resolve($admin, 'bob@b.example'));
    }

    public function test_a_domain_administrator_resolves_mailboxes_of_active_assignments(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@a.example');
        $this->mailcow()->mailboxWithDomain('bob@b.example');
        $this->mailcow()->mailboxWithDomain('carl@c.example', domainActive: 0);
        $this->mailcow()->domainAdmin('reseller', ['a.example', 'c.example']);
        $this->mailcow()->domainAdmin('inactive', ['b.example'], assignmentActive: 0);

        $reseller = self::principal(Role::DomainAdmin, 'reseller');

        $this->assertSame('anna@a.example', $this->scope()->resolve($reseller, 'anna@a.example'));
        $this->assertSame('carl@c.example', $this->scope()->resolve($reseller, 'carl@c.example'), 'An inactive domain stays in scope.');
        $this->assertNull($this->scope()->resolve($reseller, 'bob@b.example'));
        $this->assertNull($this->scope()->resolve(self::principal(Role::DomainAdmin, 'inactive'), 'bob@b.example'));
    }

    public function test_resources_unknown_addresses_and_alias_domain_addresses_resolve_to_nothing(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@a.example');
        $this->mailcow()->mailbox('room@a.example', kind: 'location');
        $this->mailcow()->aliasDomain('alias.example', 'a.example');

        $admin = self::principal(Role::Admin, 'admin');

        $this->assertNull($this->scope()->resolve($admin, 'room@a.example'));
        $this->assertNull($this->scope()->resolve($admin, 'nobody@a.example'));
        $this->assertNull($this->scope()->resolve($admin, 'anna@alias.example'));
        $this->assertNull($this->scope()->resolve($admin, 'not an address'));
    }

    public function test_mailbox_users_resolve_nothing(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@a.example');

        $this->assertNull($this->scope()->resolve(self::principal(Role::MailboxUser, 'anna@a.example'), 'anna@a.example'));
    }

    public function test_assignment_changes_apply_to_the_next_call(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@a.example');
        $this->mailcow()->domainAdmin('reseller', ['a.example']);
        $reseller = self::principal(Role::DomainAdmin, 'reseller');

        $this->assertNotNull($this->scope()->resolve($reseller, 'anna@a.example'));

        DB::table('domain_admins')->where('username', 'reseller')->update(['active' => 0]);

        $this->assertNull($this->scope()->resolve($reseller, 'anna@a.example'));
    }
}
