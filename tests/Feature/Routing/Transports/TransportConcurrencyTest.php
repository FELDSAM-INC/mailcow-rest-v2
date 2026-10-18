<?php

namespace Tests\Feature\Routing\Transports;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T049 (FR-084, research R9): transport writes run under the `restv2:routing:transports`
 * named lock; a lock held too long answers `503 temporary-failure`, and the uniqueness check runs
 * under the lock.
 */
class TransportConcurrencyTest extends TestCase
{
    use RoutingTestHelpers;

    private ?PDO $other = null;

    protected function tearDown(): void
    {
        // The test's own transaction is rolled back first, so that its row locks no longer block
        // the cleanup of the row the other session committed.
        parent::tearDown();

        $this->other?->query("SELECT RELEASE_LOCK('restv2:routing:transports')");
        $this->other?->exec("DELETE FROM `transports` WHERE `destination` = 'example.com' AND `nexthop` = 'x.example'");
        $this->other = null;
    }

    public function test_a_held_lock_answers_temporary_failure_and_the_check_runs_under_it(): void
    {
        $this->asAdmin();
        config(['restv2.routing.lock_timeout' => 1]);
        $config = config('database.connections.mysql');
        $this->other = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$config['database']}", (string) $config['username'], (string) $config['password']);
        $statement = $this->other->query("SELECT GET_LOCK('restv2:routing:transports', 0)");
        $this->assertSame(1, (int) ($statement === false ? 0 : $statement->fetchColumn()));

        $this->send('POST', '/api/v2/transports', ['destination' => 'example.com', 'nextHop' => 'hop.example'])->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');
        $this->assertSame(0, DB::table('transports')->count());

        // The other session inserts the same destination (committed: autocommit) and releases the lock.
        $this->other->exec("INSERT INTO `transports` (`nexthop`, `destination`, `is_mx_based`, `username`, `password`, `active`) VALUES ('x.example', 'example.com', 0, '', '', 0)");
        $this->other->query("SELECT RELEASE_LOCK('restv2:routing:transports')");

        $this->send('POST', '/api/v2/transports', ['destination' => 'example.com', 'nextHop' => 'hop.example'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/routing-entry-exists');
    }
}
