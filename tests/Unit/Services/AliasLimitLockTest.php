<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProblemException;
use App\Services\Aliases\AliasAddressAvailability;
use App\Services\Aliases\AliasLimit;
use App\Services\Mailboxes\AddressAvailability;
use App\Services\Mailboxes\AddressLock;
use App\Support\Database\LockNotAcquired;
use App\Support\Http\ProblemType;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T033 (FR-022, research R6): the primary domain row lock serialises competing alias
 * limit checks, 003's address lock serialises one address, and the unique key on `alias.address`
 * stays the last guard. Rows that two sessions must see are committed by a second session and
 * removed again at the end.
 */
class AliasLimitLockTest extends TestCase
{
    use AliasTestHelpers;

    private const DOMAIN = 'limitlock.example';

    protected function tearDown(): void
    {
        $cleanup = $this->connectionNamed('lockcleanup');
        $cleanup->table('alias')->where('domain', self::DOMAIN)->delete();
        $cleanup->table('domain')->where('domain', self::DOMAIN)->delete();
        DB::purge('lockholder');
        DB::purge('lockwaiter');
        DB::purge('lockcleanup');

        parent::tearDown();
    }

    private function connectionNamed(string $name): Connection
    {
        config(["database.connections.{$name}" => config('database.connections.mysql')]);

        return DB::connection($name);
    }

    public function test_competing_limit_checks_wait_for_the_primary_domain_row(): void
    {
        $holder = $this->connectionNamed('lockholder');
        $holder->table('domain')->insert([
            'domain' => self::DOMAIN, 'description' => self::DOMAIN, 'aliases' => 1, 'mailboxes' => 10, 'defquota' => 3072, 'maxquota' => 10240,
            'quota' => 10240, 'backupmx' => 0, 'gal' => 1, 'active' => 1, 'relay_unknown_only' => 0, 'relay_all_recipients' => 0,
        ]);
        $waiter = $this->connectionNamed('lockwaiter');
        $waiter->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $limit = $this->app->make(AliasLimit::class);
        $default = DB::getDefaultConnection();

        $holder->beginTransaction();
        $holder->table('domain')->where('domain', self::DOMAIN)->lockForUpdate()->first();

        DB::setDefaultConnection('lockwaiter');

        try {
            $timedOut = false;

            try {
                $waiter->transaction(fn () => $limit->lockPrimaryDomain(self::DOMAIN));
            } catch (QueryException $e) {
                $timedOut = str_contains($e->getMessage(), 'Lock wait timeout');
            }

            $this->assertTrue($timedOut, 'The second check waits for the first.');

            // The first request inserts the last allowed alias and commits; the second then refuses.
            $holder->table('alias')->insert(['address' => 'last@'.self::DOMAIN, 'goto' => 'anna@example.org', 'domain' => self::DOMAIN]);
            $holder->commit();

            $refused = null;

            try {
                $waiter->transaction(function () use ($limit): void {
                    $limit->lockPrimaryDomain(self::DOMAIN);
                    $limit->assertRoom(self::DOMAIN, 1);
                });
            } catch (ProblemException $e) {
                $refused = $e;
            }

            $this->assertSame(ProblemType::DomainAliasLimitReached, $refused?->type);
            $this->assertSame(['domain' => self::DOMAIN, 'count' => 1, 'limit' => 1, 'requested' => 1], $refused?->extensions);
        } finally {
            DB::setDefaultConnection($default);

            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
        }
    }

    public function test_one_address_is_held_by_one_request_at_a_time(): void
    {
        $holder = $this->connectionNamed('lockholder');
        $holder->select('SELECT GET_LOCK(?, 0)', [AddressLock::name('sales@example.org')]);

        try {
            $this->expectException(LockNotAcquired::class);
            $this->app->make(AddressLock::class)->run(['Sales@example.org'], fn () => 'never', 1);
        } finally {
            $holder->select('SELECT RELEASE_LOCK(?)', [AddressLock::name('sales@example.org')]);
        }
    }

    public function test_a_duplicate_key_on_the_address_is_an_address_in_use_conflict(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailbox('anna@example.org');
        $this->mailcow()->alias('sales@example.org', 'anna@example.org');
        $this->app->instance(AliasAddressAvailability::class, new class($this->app->make(AddressAvailability::class)) extends AliasAddressAvailability
        {
            public function holderOf(string $address, ?string $ignore = null): ?string
            {
                return null;
            }
        });
        $this->asAdmin();

        $this->createAlias(['address' => 'Sales@example.org', 'destinations' => ['anna@example.org']])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/address-in-use')
            ->assertJsonPath('holder', 'alias');
    }
}
