<?php

namespace Tests\Feature\SelfService;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Services\SelfService\MailboxAccess;
use App\Services\SelfService\PermissionGate;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * T012 (feature 005 FR-001–FR-007): the mailbox of a `/mailboxes/{mailbox}/…` self-service
 * operation in the caller's scope, and the permission flags that gate changes.
 */
class MailboxAccessTest extends TestCase
{
    private function principal(string $username, Role $role): Principal
    {
        return new Principal($username, $role, $role === Role::MailboxUser ? AccountKind::Mailbox : AccountKind::Admin, AuthMethod::Password);
    }

    private function access(): MailboxAccess
    {
        return $this->app->make(MailboxAccess::class);
    }

    private function gate(): PermissionGate
    {
        return $this->app->make(PermissionGate::class);
    }

    private function assertNotFound(Principal $principal, string $raw): void
    {
        try {
            $this->access()->resolve($principal, $raw);
            $this->fail("{$raw} should not be found for {$principal->username}.");
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('carl@xn--mnchen-3ya.de');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->mailcow()->resource('room@example.org', 'Room');
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->domainAdmin('reseller', ['example.org', 'xn--mnchen-3ya.de']);
    }

    public function test_an_administrator_reaches_every_mailbox_normalised(): void
    {
        $admin = $this->principal('admin', Role::Admin);

        $this->assertSame('anna@example.org', $this->access()->resolve($admin, ' ANNA@Example.ORG '));
        $this->assertSame('carl@xn--mnchen-3ya.de', $this->access()->resolve($admin, 'carl@MÜNCHEN.de'));
        $this->assertSame('dora@example.net', $this->access()->resolve($admin, 'dora@example.net'));
    }

    public function test_unknown_addresses_and_resources_are_not_found(): void
    {
        $admin = $this->principal('admin', Role::Admin);

        $this->assertNotFound($admin, 'nobody@example.org');
        $this->assertNotFound($admin, 'room@example.org');
        $this->assertNotFound($admin, 'not-an-address');
    }

    public function test_a_domain_administrator_reaches_only_the_mailboxes_of_active_assignments(): void
    {
        $reseller = $this->principal('reseller', Role::DomainAdmin);

        $this->assertSame('anna@example.org', $this->access()->resolve($reseller, 'anna@example.org'));
        $this->assertSame('carl@xn--mnchen-3ya.de', $this->access()->resolve($reseller, 'carl@münchen.de'));
        $this->assertNotFound($reseller, 'dora@example.net');

        DB::table('domain_admins')->where('username', 'reseller')->where('domain', 'example.org')->update(['active' => 0]);
        $this->assertNotFound($reseller, 'anna@example.org');
    }

    public function test_a_mailbox_user_reaches_only_their_own_mailbox(): void
    {
        $anna = $this->principal('anna@example.org', Role::MailboxUser);

        $this->assertSame('anna@example.org', $this->access()->resolve($anna, 'Anna@EXAMPLE.org'));
        $this->assertNotFound($anna, 'carl@münchen.de');
        $this->assertNotFound($anna, 'room@example.org');
    }

    public function test_a_missing_flag_refuses_a_change_naming_the_flag(): void
    {
        DB::table('user_acl')->where('username', 'anna@example.org')->update(['app_passwds' => 0]);
        $anna = $this->principal('anna@example.org', Role::MailboxUser);

        try {
            $this->gate()->requireChange($anna, PermissionFlag::AppPasswds);
            $this->fail('The change should be refused.');
        } catch (PermissionFlagRequiredException $e) {
            $this->assertSame(['requiredFlags' => ['appPasswds'], 'requiredFlagsMatch' => 'all'], $e->extensions);
        }
    }

    public function test_a_value_equal_to_the_current_one_needs_no_flag(): void
    {
        DB::table('user_acl')->where('username', 'anna@example.org')->update(['tls_policy' => 0]);
        $anna = $this->principal('anna@example.org', Role::MailboxUser);

        $this->gate()->requireChange($anna, PermissionFlag::TlsPolicy, changes: false);
        $this->assertFalse($this->gate()->has($anna, PermissionFlag::TlsPolicy));
    }

    /**
     * @return array<string, array{PermissionFlag, array<string, int>, bool}>
     */
    public static function domainAdministratorFlags(): array
    {
        return [
            'temporary aliases from the user flags' => [PermissionFlag::SpamAlias, [], true],
            'TLS from the user flags' => [PermissionFlag::TlsPolicy, [], true],
            'tagging from the user flags' => [PermissionFlag::DelimiterAction, [], true],
            'ActiveSync reset from the user flags' => [PermissionFlag::EasReset, [], true],
            'profile reset from the user flags' => [PermissionFlag::SogoProfileReset, [], true],
            'app passwords from the own flag (granted)' => [PermissionFlag::AppPasswds, ['app_passwds' => 1], true],
            'app passwords from the own flag (missing)' => [PermissionFlag::AppPasswds, ['app_passwds' => 0], false],
            'push notifications from the own flag (missing)' => [PermissionFlag::Pushover, ['pushover' => 0], false],
            'filters from the own flag (granted)' => [PermissionFlag::Filters, ['filters' => 1], true],
            'filters from the own flag (missing)' => [PermissionFlag::Filters, ['filters' => 0], false],
        ];
    }

    /**
     * @param  array<string, int>  $daAcl
     */
    #[DataProvider('domainAdministratorFlags')]
    public function test_a_domain_administrator_acts_with_the_mapped_flags(PermissionFlag $flag, array $daAcl, bool $granted): void
    {
        $this->mailcow()->domainAdmin('da2', ['example.org'], $daAcl);

        $this->assertSame($granted, $this->gate()->has($this->principal('da2', Role::DomainAdmin), $flag));
    }

    public function test_an_administrator_holds_every_flag(): void
    {
        $admin = $this->principal('admin', Role::Admin);

        foreach (PermissionFlag::cases() as $flag) {
            $this->assertTrue($this->gate()->has($admin, $flag), $flag->value);
        }
    }
}
