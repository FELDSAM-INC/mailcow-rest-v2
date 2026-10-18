<?php

namespace Tests\Feature\Database;

use App\Services\Mailboxes\AddressLock;
use App\Support\Database\LockNotAcquired;
use App\Support\Database\NamedLock;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature 003 T015 (research R7, docs/conventions.md "Shared locks and helpers"): address uniqueness
 * across features is serialised with the MariaDB named lock
 * `restv2_address_<sha1 of the lower-cased address>`, released even when the guarded code throws.
 */
class AddressLockTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge('lockcheck');

        parent::tearDown();
    }

    /** A second database session, as another request would hold. */
    private function otherSession(): Connection
    {
        config(['database.connections.lockcheck' => config('database.connections.mysql')]);

        return DB::connection('lockcheck');
    }

    private static function isFree(Connection $connection, string $name): bool
    {
        return (int) ($connection->selectOne('SELECT IS_FREE_LOCK(?) AS `free`', [$name])->free ?? 0) === 1;
    }

    public function test_the_lock_name_is_the_shared_convention(): void
    {
        $this->assertSame('restv2_address_'.sha1('anna@example.org'), AddressLock::name(' Anna@Example.org '));
        $this->assertSame(55, strlen(AddressLock::name('anna@example.org')));
    }

    public function test_the_lock_is_held_while_the_code_runs_and_released_afterwards(): void
    {
        $other = $this->otherSession();
        $name = AddressLock::name('anna@example.org');

        $result = $this->app->make(AddressLock::class)->run(['anna@example.org'], function () use ($other, $name): string {
            $this->assertFalse(self::isFree($other, $name));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertTrue(self::isFree($other, $name));
    }

    public function test_the_lock_is_released_when_the_code_throws(): void
    {
        $other = $this->otherSession();

        $message = null;

        try {
            $this->app->make(AddressLock::class)->run(['anna@example.org'], fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('boom', $message);

        $this->assertTrue(self::isFree($other, AddressLock::name('anna@example.org')));
    }

    public function test_a_lock_held_by_another_session_times_out(): void
    {
        $other = $this->otherSession();
        $name = AddressLock::name('anna@example.org');
        $other->select('SELECT GET_LOCK(?, 0)', [$name]);

        try {
            $this->expectException(LockNotAcquired::class);
            $this->app->make(NamedLock::class)->run([$name], fn () => 'never', 1);
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function test_a_timeout_answers_500(): void
    {
        $other = $this->otherSession();
        $name = AddressLock::name('anna@example.org');
        $other->select('SELECT GET_LOCK(?, 0)', [$name]);
        Route::middleware('api.v2')->get('/api/v2/__testing/address-lock', fn () => $this->app->make(NamedLock::class)->run([$name], fn () => response()->noContent(), 1));

        try {
            $this->getJson('/api/v2/__testing/address-lock')->assertStatus(500)->assertJsonPath('type', 'about:blank');
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function test_different_addresses_do_not_block_each_other(): void
    {
        $other = $this->otherSession();
        $other->select('SELECT GET_LOCK(?, 0)', [AddressLock::name('bob@example.org')]);

        try {
            $this->assertSame('free', $this->app->make(AddressLock::class)->run(['anna@example.org'], fn () => 'free'));
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', [AddressLock::name('bob@example.org')]);
        }
    }

    public function test_several_locks_are_held_together(): void
    {
        $other = $this->otherSession();

        $this->app->make(AddressLock::class)->run(['new@example.org', 'old@example.org'], function () use ($other): void {
            $this->assertFalse(self::isFree($other, AddressLock::name('old@example.org')));
            $this->assertFalse(self::isFree($other, AddressLock::name('new@example.org')));
        });

        $this->assertTrue(self::isFree($other, AddressLock::name('old@example.org')));
        $this->assertTrue(self::isFree($other, AddressLock::name('new@example.org')));
    }
}
