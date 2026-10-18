<?php

namespace Tests\Feature\TemporaryAliases;

use App\Services\Mailboxes\AddressLock;
use App\Services\TemporaryAliases\TemporaryAliasAddressGenerator;
use Illuminate\Support\Facades\DB;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T083 (feature 005 FR-044, docs/conventions.md "Shared locks and helpers"): a temporary alias
 * takes 003's address lock before it checks and inserts its address, so a concurrent alias, mailbox
 * or resource creation of the same address (which takes the same lock) can never also succeed.
 */
class TemporaryAliasConcurrencyTest extends TestCase
{
    use SelfServiceTestHelpers;

    public function test_the_creation_waits_for_the_shared_address_lock(): void
    {
        $this->asSelfServiceUser();
        $this->app->instance(TemporaryAliasAddressGenerator::class, new TemporaryAliasAddressGenerator(fn (int $min): int => $min));
        config(['database.connections.lockcheck' => config('database.connections.mysql')]);
        $other = DB::connection('lockcheck');
        $name = AddressLock::name('bababa.bababa@example.org');
        $other->select('SELECT GET_LOCK(?, 0)', [$name]);

        try {
            // A 004 alias creation of the same address would hold this lock; the temporary alias
            // waits for it and, never getting it here, stores nothing.
            $this->postJson('/api/v2/mailboxes/anna@example.org/temporary-aliases', ['domain' => 'example.org'], $this->csrf())->assertStatus(500);
        } finally {
            $other->select('SELECT RELEASE_LOCK(?)', [$name]);
            DB::purge('lockcheck');
        }

        $this->assertSame(0, DB::table('spamalias')->count());
    }
}
