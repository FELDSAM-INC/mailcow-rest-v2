<?php

namespace Tests\Feature\Domains;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Domains\DomainAccessLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DomainAccessLockTest extends TestCase
{
    public function test_current_reads_and_locks_cover_grant_revocation_and_alias_transfers(): void
    {
        $default = DB::getDefaultConnection();
        config([
            'database.connections.scope_writer' => config('database.connections.'.$default),
            'database.connections.scope_observer' => config('database.connections.'.$default),
        ]);
        $writer = DB::connection('scope_writer');
        $observer = DB::connection('scope_observer');
        $name = 'scope-lock-'.bin2hex(random_bytes(4));
        $domain = $name.'.example';
        $alias = 'alias.'.$domain;
        $principal = new Principal($name, Role::DomainAdmin, AccountKind::Admin, AuthMethod::Password);
        $scope = app(DomainAccessLock::class);

        try {
            $observer->table('domain_admins')->insert(['username' => $name, 'domain' => $domain, 'active' => 1]);
            $observer->table('alias_domain')->insert(['alias_domain' => $alias, 'target_domain' => $domain, 'active' => 1]);
            $writer->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $observer->statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::setDefaultConnection('scope_writer');

            $writer->beginTransaction();
            $this->assertSame(1, (int) $writer->table('domain_admins')->where('username', $name)->value('active'));
            $observer->table('domain_admins')->where('username', $name)->update(['active' => 0]);
            $this->assertFalse($scope->primary($principal, $domain), 'Authorization must not reuse the earlier snapshot.');
            $writer->commit();

            $observer->table('domain_admins')->where('username', $name)->update(['active' => 1]);
            $writer->beginTransaction();
            $this->assertTrue($scope->includingAliases($principal, $alias));
            foreach (['domain_admins', 'alias_domain'] as $table) {
                try {
                    $observer->table($table)->where($table === 'domain_admins' ? 'username' : 'alias_domain', $table === 'domain_admins' ? $name : $alias)
                        ->update($table === 'domain_admins' ? ['active' => 0] : ['target_domain' => 'foreign.example']);
                    $this->fail('Ownership must remain locked until the mutation commits.');
                } catch (QueryException $e) {
                    $this->assertSame(1205, $e->errorInfo[1]);
                }
            }
            $writer->commit();
            $this->assertSame(1, $observer->table('domain_admins')->where('username', $name)->update(['active' => 0]));
            $this->assertSame(1, $observer->table('alias_domain')->where('alias_domain', $alias)->update(['target_domain' => 'foreign.example']));
        } finally {
            while ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            DB::setDefaultConnection($default);
            $observer->table('alias_domain')->where('alias_domain', $alias)->delete();
            $observer->table('domain_admins')->where('username', $name)->delete();
            DB::purge('scope_writer');
            DB::purge('scope_observer');
        }
    }
}
