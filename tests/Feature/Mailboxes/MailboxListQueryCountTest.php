<?php

namespace Tests\Feature\Mailboxes;

use App\Clients\SharedStore\SharedStore;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T031 (FR-016, SC-002): a page of the mailbox list costs a constant number of database
 * statements and at most three shared-store calls, whatever its size: there is no lookup per
 * mailbox.
 */
class MailboxListQueryCountTest extends TestCase
{
    use MailboxTestHelpers;

    public function test_the_statement_count_does_not_grow_with_the_page(): void
    {
        foreach (['a.example', 'b.example', 'c.example'] as $domain) {
            $this->mailcow()->domain($domain, attributes: ['quota' => 1048576, 'maxquota' => 1048576]);
        }

        for ($i = 1; $i <= 120; $i++) {
            $address = sprintf('user%03d@%s', $i, ['a.example', 'b.example', 'c.example'][$i % 3]);
            $this->mailcow()->legacyMailbox($address, tags: ['t'.($i % 4)]);
            $this->mailcow()->saslLog($address, 'imap', '2026-10-01 10:00:00');
            $this->mailcow()->temporaryAlias('tmp'.$i.'@a.example', $address);
        }

        $calls = 0;
        $this->app->instance(SharedStore::class, new class($this->app->make(RedisFactory::class), $calls) extends SharedStore
        {
            public function __construct(RedisFactory $redis, private int &$calls)
            {
                parent::__construct($redis);
            }

            public function run(callable $operation): mixed
            {
                $this->calls++;

                return parent::run($operation);
            }
        });
        $this->asAdmin();

        $statements = 0;
        DB::listen(function () use (&$statements): void {
            $statements++;
        });

        $count = function (int $limit) use (&$calls, &$statements): array {
            $statements = 0;
            $calls = 0;
            $this->getJson('/api/v2/mailboxes?limit='.$limit.'&sort=lastMailLogin')->assertOk()->assertJsonCount($limit, 'data');

            return [$statements, $calls];
        };

        [$small, $smallRedis] = $count(10);
        [$large, $largeRedis] = $count(100);

        $this->assertSame($small, $large, "Statements: {$small} for a page of 10, {$large} for a page of 100.");
        $this->assertLessThanOrEqual(3, $smallRedis);
        $this->assertSame($smallRedis, $largeRedis);
    }
}
