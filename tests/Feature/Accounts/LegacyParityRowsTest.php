<?php

namespace Tests\Feature\Accounts;

use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T091 (SC-001): for each create, change, rename, flag change and delete case, the rows
 * of `admin`, `domain_admins`, `da_acl`, `tfa`, `fido2` and `da_sso` equal those legacy writes for
 * the same input (expected rows below, with the legacy statements they follow, from
 * data/web/inc/functions.admin.inc.php, functions.domain_admin.inc.php and functions.acl.inc.php at
 * 2026-09a), apart from timestamps, password hashes and the deviations of the spec's parity table,
 * each named where it applies.
 */
class LegacyParityRowsTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('b.example');
        $this->asAdmin();
    }

    /**
     * The compared columns of a table's rows of a username, ordered.
     *
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $username, array $columns, string $order = 'username'): array
    {
        return DB::table($table)->where('username', $username)->orderBy($order)->get($columns)
            ->map(fn (object $row): array => array_map(fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, (array) $row))->all();
    }

    public function test_domain_administrator_creation(): void
    {
        $this->createDomainAdmin(['username' => 'reseller1', 'password' => 'Passw0rd!x', 'domains' => ['b.example', 'a.example']])->assertCreated();

        // domain_admin('add'): INSERT INTO admin (username, password, superadmin '0', active).
        // Deviation (research R6): attributes are written in the normalised form that legacy's
        // start-up migration writes over legacy's NULL.
        $this->assertSame([['username' => 'reseller1', 'superadmin' => 0, 'active' => 1, 'attributes' => '{"force_tfa":"0","force_pw_update":"0"}']], $this->rows('admin', 'reseller1', ['username', 'superadmin', 'active', 'attributes']));
        // INSERT INTO domain_admins (username, domain, created, active) per valid domain.
        $this->assertSame([
            ['username' => 'reseller1', 'domain' => 'a.example', 'active' => 1],
            ['username' => 'reseller1', 'domain' => 'b.example', 'active' => 1],
        ], $this->rows('domain_admins', 'reseller1', ['username', 'domain', 'active'], 'domain'));
        // INSERT INTO da_acl (username): every column default.
        $defaults = array_map(fn (bool $v): int => (int) $v, $this->flagDefaults());
        $this->assertSame([['username' => 'reseller1'] + array_combine(array_values(self::FLAG_COLUMNS), array_values($defaults))], $this->rows('da_acl', 'reseller1', ['username', ...array_values(self::FLAG_COLUMNS)]));
    }

    public function test_administrator_creation(): void
    {
        $this->createAdmin(['username' => 'bob', 'password' => 'Passw0rd!x'])->assertCreated();

        // admin('add'): INSERT INTO admin (username, password, superadmin '1', active).
        $this->assertSame([['username' => 'bob', 'superadmin' => 1, 'active' => 1, 'attributes' => '{"force_tfa":"0","force_pw_update":"0"}']], $this->rows('admin', 'bob', ['username', 'superadmin', 'active', 'attributes']));
        $this->assertSame([], $this->rows('domain_admins', 'bob', ['username']));
        $this->assertSame([], $this->rows('da_acl', 'bob', ['username']));
    }

    public function test_domain_administrator_change_as_the_legacy_edit_form_sends_it(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example', 'b.example']);

        // The legacy form sends the status, both forced flags and the domains.
        $this->patchDomainAdmin('reseller1', ['active' => false, 'forcePasswordChange' => true, 'forceSecondFactorSetup' => true, 'domains' => ['a.example', 'b.example']])->assertOk();

        // UPDATE admin SET active, attributes = JSON_SET(COALESCE(attributes, '{}'), '$.force_tfa', '1', '$.force_pw_update', '1').
        $this->assertAdminRow('reseller1', 0, 0, true, true);
        // DELETE FROM domain_admins + INSERT with the account's status. Deviation (FR-023): the rows
        // are kept, with their creation times; their status follows the account.
        $this->assertSame([
            ['domain' => 'a.example', 'active' => 0],
            ['domain' => 'b.example', 'active' => 0],
        ], $this->rows('domain_admins', 'reseller1', ['domain', 'active'], 'domain'));
    }

    public function test_domain_administrator_rename(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example'], ['login_as' => 0]);
        $this->mailcow()->tfaTotp('reseller1', 'S');
        $this->mailcow()->fido2('reseller1', random_bytes(16), 'PEM', 'mail.example.org');

        $this->patchDomainAdmin('reseller1', ['username' => 'reseller-one'])->assertOk();

        // UPDATE admin SET username; UPDATE da_acl SET username; DELETE + INSERT domain_admins with
        // the new name; UPDATE tfa SET username (without disable_tfa).
        $this->assertSame(1, DB::table('admin')->where('username', 'reseller-one')->count());
        $this->assertDaAcl('reseller-one', ['login_as' => 0]);
        $this->assertSame([['domain' => 'a.example']], $this->rows('domain_admins', 'reseller-one', ['domain']));
        $this->assertSame(1, DB::table('tfa')->where('username', 'reseller-one')->count());
        // Deviation (FR-025): passkeys follow the rename (legacy leaves fido2 rows behind).
        $this->assertSame(1, DB::table('fido2')->where('username', 'reseller-one')->count());
    }

    public function test_permission_change_with_every_flag_sent(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $sent = array_combine(array_keys(self::FLAG_COLUMNS), array_map(fn (int $i): bool => $i % 2 === 0, range(0, 18)));

        $this->patchDomainAdmin('reseller1', ['permissions' => $sent])->assertOk();

        // acl('edit', 'domainadmin'): UPDATE da_acl SET <flag> = 1 for every sent flag, 0 otherwise.
        // Deviation (FR-038): unsent flags keep their values; with every flag sent the row is the same.
        $expected = ['username' => 'reseller1'];

        foreach (self::FLAG_COLUMNS as $name => $column) {
            $expected[$column] = $sent[$name] ? 1 : 0;
        }

        $this->assertSame([$expected], $this->rows('da_acl', 'reseller1', ['username', ...array_values(self::FLAG_COLUMNS)]));
    }

    public function test_domain_administrator_deletion(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->mailcow()->tfaTotp('reseller1', 'S');
        $this->mailcow()->fido2('reseller1', random_bytes(16), 'PEM', 'mail.example.org');
        $this->mailcow()->daSso('reseller1', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');

        $this->deleteDomainAdmin('reseller1')->assertNoContent();

        // domain_admin('delete'): DELETE FROM domain_admins, admin, da_acl, tfa, fido2.
        // Deviation (FR-031): the unused tokens (da_sso) are removed too.
        foreach (['domain_admins', 'admin', 'da_acl', 'tfa', 'fido2', 'da_sso'] as $table) {
            $this->assertSame([], $this->rows($table, 'reseller1', ['username']), $table);
        }
    }

    public function test_administrator_deletion(): void
    {
        $this->mailcow()->admin('bob', 'x');
        $this->mailcow()->tfaTotp('bob', 'S');
        $this->mailcow()->fido2('bob', random_bytes(16), 'PEM', 'mail.example.org');

        $this->deleteAdmin('bob')->assertNoContent();

        // admin('delete'): DELETE FROM admin, domain_admins, tfa, fido2 (FR-030 adds da_acl).
        foreach (['admin', 'domain_admins', 'tfa', 'fido2', 'da_acl'] as $table) {
            $this->assertSame([], $this->rows($table, 'bob', ['username']), $table);
        }
    }
}
