<?php

namespace Tests\Feature\Identity;

use App\Auth\PermissionFlag;
use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T077 (R20): the effective permission flags follow legacy `acl('to_session')` for every role.
 */
class PermissionFlagsTest extends TestCase
{
    /**
     * @return array<string, bool>
     */
    private function flags(): array
    {
        return $this->getJson('/api/v2/me')->assertOk()->json('data.permissions');
    }

    /**
     * @param  callable(PermissionFlag): bool  $expected
     * @param  array<string, bool>  $actual
     */
    private function assertFlags(callable $expected, array $actual): void
    {
        $this->assertCount(29, $actual);

        foreach (PermissionFlag::cases() as $flag) {
            $this->assertSame($expected($flag), $actual[$flag->value], $flag->value);
        }
    }

    /**
     * @return array<string, int>
     */
    private static function allZero(string $table): array
    {
        return array_fill_keys(array_values(array_filter(array_map(
            fn (PermissionFlag $flag): ?string => $table === 'da_acl' ? $flag->daAclColumn() : $flag->userAclColumn(),
            PermissionFlag::cases(),
        ))), 0);
    }

    public function test_an_admin_has_every_flag(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->assertFlags(fn (): bool => true, $this->flags());
    }

    public function test_a_domain_admin_with_every_da_acl_flag_off(): void
    {
        $this->mailcow()->domainAdmin('reseller', [], self::allZero('da_acl'));
        $this->signInAs('reseller', Role::DomainAdmin);

        // user_acl-only flags are granted; every da_acl flag, overlapping ones included, is off.
        $this->assertFlags(fn (PermissionFlag $flag): bool => $flag->daAclColumn() === null, $this->flags());
    }

    /**
     * Orchestrator decision (spec clarification, parity table): without a `da_acl` row, the
     * domain administrator has the flags of the row legacy would insert, `INSERT INTO da_acl
     * (username)`, which takes every column default of the upstream schema.
     */
    public function test_a_domain_admin_without_a_da_acl_row_has_the_schema_defaults(): void
    {
        $this->mailcow()->domainAdmin('reseller', []);
        $defaults = (array) DB::table('da_acl')->where('username', 'reseller')->first();
        DB::table('da_acl')->where('username', 'reseller')->delete();
        $this->signInAs('reseller', Role::DomainAdmin);

        $flags = $this->flags();

        $this->assertFlags(
            fn (PermissionFlag $flag): bool => $flag->daAclColumn() === null || (int) $defaults[$flag->daAclColumn()] === 1,
            $flags,
        );
        // Spot checks against the 2026-09a schema: login_as defaults to 1, pushover and
        // unlimited_quota to 0.
        $this->assertTrue($flags['loginAs']);
        $this->assertFalse($flags['pushover']);
        $this->assertFalse($flags['unlimitedQuota']);
        $this->assertDatabaseMissing('da_acl', ['username' => 'reseller']);
    }

    public function test_the_overlapping_flags_of_a_domain_admin_come_from_da_acl(): void
    {
        $this->mailcow()->domainAdmin('reseller', [], [
            'syncjobs' => 1,
            'quarantine' => 0,
            'app_passwds' => 1,
            'pushover' => 0,
            'spam_policy' => 0,
        ]);
        $this->signInAs('reseller', Role::DomainAdmin);

        $flags = $this->flags();

        $this->assertTrue($flags['syncjobs']);
        $this->assertFalse($flags['quarantine']);
        $this->assertTrue($flags['appPasswds']);
        $this->assertFalse($flags['pushover']);
        $this->assertFalse($flags['spamPolicy']);
    }

    public function test_a_domain_admin_with_the_column_defaults(): void
    {
        $this->mailcow()->domainAdmin('reseller', []);
        $this->signInAs('reseller', Role::DomainAdmin);
        $defaults = (array) DB::table('da_acl')->where('username', 'reseller')->first();

        $this->assertFlags(
            fn (PermissionFlag $flag): bool => $flag->daAclColumn() === null || (int) $defaults[$flag->daAclColumn()] === 1,
            $this->flags(),
        );
    }

    public function test_a_mailbox_user_with_every_user_acl_flag_off(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org', userAcl: self::allZero('user_acl'));
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $this->assertFlags(fn (): bool => false, $this->flags());
    }

    public function test_a_mailbox_user_without_a_user_acl_row(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        DB::table('user_acl')->where('username', 'alice@example.org')->delete();
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $this->assertFlags(fn (): bool => false, $this->flags());
    }

    public function test_a_mailbox_user_with_the_column_defaults(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $defaults = (array) DB::table('user_acl')->where('username', 'alice@example.org')->first();

        // da_acl-only flags are always off for mailbox users.
        $this->assertFlags(
            fn (PermissionFlag $flag): bool => $flag->userAclColumn() !== null && (int) $defaults[$flag->userAclColumn()] === 1,
            $this->flags(),
        );
    }
}
