<?php

namespace Tests\Feature\Clients;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Feature 002 T012 (research D8, D17): mailcow's shared store (Redis, connection `mailcow`, no
 * prefix). Hash writes run in one MULTI/EXEC and return an undo action; an unreachable store raises
 * SharedStoreUnavailable from every method.
 */
class SharedStoreTest extends TestCase
{
    private function store(): SharedStore
    {
        return $this->app->make(SharedStore::class);
    }

    private function raw(): \Redis
    {
        $client = Redis::connection('mailcow')->client();
        $this->assertInstanceOf(\Redis::class, $client);

        return $client;
    }

    public function test_ping_succeeds_on_a_reachable_store(): void
    {
        $this->store()->ping();

        $this->addToAssertionCount(1);
    }

    public function test_set_fields_writes_without_prefix_and_undo_removes_new_fields(): void
    {
        $undo = $this->store()->setFields('DOMAIN_MAP', ['example.org' => '1', 'example.net' => '1']);

        $this->assertSame('1', $this->raw()->hGet('DOMAIN_MAP', 'example.org'));
        $this->assertSame('1', $this->raw()->hGet('DOMAIN_MAP', 'example.net'));
        $this->assertSame(0, $this->raw()->exists('restv2:DOMAIN_MAP'));

        $undo();

        $this->assertFalse($this->raw()->hGet('DOMAIN_MAP', 'example.org'));
        $this->assertFalse($this->raw()->hGet('DOMAIN_MAP', 'example.net'));
    }

    public function test_undo_restores_previous_values(): void
    {
        $this->raw()->hSet('RL_VALUE', 'example.org', '5 / 1s');

        $undo = $this->store()->setFields('RL_VALUE', ['example.org' => '10 / 1h']);
        $this->assertSame('10 / 1h', $this->raw()->hGet('RL_VALUE', 'example.org'));

        $undo();
        $this->assertSame('5 / 1s', $this->raw()->hGet('RL_VALUE', 'example.org'));
    }

    public function test_delete_fields_removes_across_hashes_and_undo_puts_them_back(): void
    {
        $this->raw()->hSet('DOMAIN_MAP', 'a.example', '1');
        $this->raw()->hSet('DOMAIN_MAP', 'b.example', '1');
        $this->raw()->hSet('RL_VALUE', 'a.example', '10 / 1h');

        $undo = $this->store()->deleteFields(['DOMAIN_MAP' => ['a.example', 'b.example', 'absent.example'], 'RL_VALUE' => ['a.example']]);

        $this->assertSame([], $this->raw()->hGetAll('DOMAIN_MAP'));
        $this->assertSame([], $this->raw()->hGetAll('RL_VALUE'));

        $undo();

        $this->assertSame(['a.example' => '1', 'b.example' => '1'], $this->raw()->hGetAll('DOMAIN_MAP'));
        $this->assertSame(['a.example' => '10 / 1h'], $this->raw()->hGetAll('RL_VALUE'));
    }

    public function test_reads(): void
    {
        $this->raw()->hSet('DKIM_SELECTORS', 'example.org', 'dkim');

        $this->assertSame('dkim', $this->store()->getField('DKIM_SELECTORS', 'example.org'));
        $this->assertNull($this->store()->getField('DKIM_SELECTORS', 'example.net'));
        $this->assertTrue($this->store()->hasField('DKIM_SELECTORS', 'example.org'));
        $this->assertFalse($this->store()->hasField('DKIM_SELECTORS', 'example.net'));
        $this->assertSame(['example.org' => 'dkim', 'example.net' => null], $this->store()->getFields('DKIM_SELECTORS', ['example.org', 'example.net']));
        $this->assertSame([], $this->store()->getFields('DKIM_SELECTORS', []));
    }

    public function test_every_method_raises_unavailable_on_a_closed_port(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
        $store = $this->store();

        $calls = [
            'ping' => fn () => $store->ping(),
            'setFields' => fn () => $store->setFields('DOMAIN_MAP', ['x.example' => '1']),
            'deleteFields' => fn () => $store->deleteFields(['DOMAIN_MAP' => ['x.example']]),
            'getField' => fn () => $store->getField('DOMAIN_MAP', 'x.example'),
            'getFields' => fn () => $store->getFields('DOMAIN_MAP', ['x.example']),
            'hasField' => fn () => $store->hasField('DOMAIN_MAP', 'x.example'),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} did not raise SharedStoreUnavailable");
            } catch (SharedStoreUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
