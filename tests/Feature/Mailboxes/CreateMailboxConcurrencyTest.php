<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Feature 003 T037 (FR-023, SC-006, research R6): concurrent creations never exceed the domain's
 * mailbox limit or its quota, and of two concurrent creations of one address exactly one succeeds.
 * The attempts run in separate PHP processes with their own database sessions, on rows committed
 * outside the test's transaction.
 */
#[Group('concurrency')]
class CreateMailboxConcurrencyTest extends TestCase
{
    private const GIB = 1073741824;

    private string $domain;

    private Connection $observer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.observer' => config('database.connections.mysql')]);
        $this->observer = DB::connection('observer');
        $this->domain = 'race-'.bin2hex(random_bytes(4)).'.example';
        $this->observer->table('domain')->insert([
            'domain' => $this->domain, 'description' => '', 'aliases' => 400, 'mailboxes' => 10, 'defquota' => 1024,
            'maxquota' => 10240, 'quota' => 10240, 'backupmx' => 0, 'gal' => 1, 'active' => 1, 'relay_unknown_only' => 0, 'relay_all_recipients' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $pattern = '%@'.$this->domain;

        foreach (['tags_mailbox' => 'username', 'user_acl' => 'username', 'quota2' => 'username', 'quota2replica' => 'username', 'alias' => 'address', '_sogo_static_view' => 'c_uid', 'restv2_audit_events' => 'target_id'] as $table => $column) {
            $this->observer->table($table)->where($column, 'like', $pattern)->delete();
        }

        $this->observer->table('mailbox')->where('domain', $this->domain)->delete();
        $this->observer->table('domain')->where('domain', $this->domain)->delete();
        DB::purge('observer');

        parent::tearDown();
    }

    /**
     * Starts the workers at the same moment; each prints one RESULT line per attempt.
     *
     * @param  list<list<string>>  $localParts  per worker
     * @return list<string>
     */
    private function race(array $localParts): array
    {
        $start = microtime(true) + 5.0;
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $processes = [];

        foreach ($localParts as $i => $parts) {
            $code = sprintf(
                '$p = new App\Auth\Principal("admin", App\Auth\Role::Admin, App\Auth\AccountKind::Admin, App\Auth\AuthMethod::Password);'
                .'$c = app(App\Services\Mailboxes\MailboxCreator::class);'
                .'while (microtime(true) < %F) { usleep(200); }'
                .'foreach (json_decode(%s, true) as $l) {'
                .' try { $c->create($p, ["localPart" => $l, "domain" => "%s", "password" => "{PLAIN}x", "quota" => %d]); echo "RESULT:created\n"; }'
                .' catch (App\Exceptions\ProblemException $e) { echo "RESULT:".$e->type->value."\n"; }'
                .' catch (App\Exceptions\ValidationProblemException $e) { echo "RESULT:validation-failed\n"; }'
                .' catch (Throwable $e) { echo "RESULT:error ".get_class($e).": ".$e->getMessage()."\n"; } }',
                $start,
                var_export(json_encode($parts), true),
                $this->domain,
                self::GIB,
            );
            $processes[$i] = new Process([$php, 'artisan', 'tinker', '--env=testing', '--execute='.$code], base_path(), ['APP_ENV' => 'testing']);
            $processes[$i]->setTimeout(240);
            $processes[$i]->start();
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            preg_match_all('/RESULT:([^\n]+)/', $process->getOutput(), $m);
            $results = [...$results, ...$m[1]];

            if ($m[1] === []) {
                $results[] = 'error: '.$process->getErrorOutput().$process->getOutput();
            }
        }

        return $results;
    }

    public function test_a_thousand_concurrent_attempts_create_exactly_the_limit(): void
    {
        $workers = [];

        for ($w = 0; $w < 20; $w++) {
            $workers[] = array_map(fn (int $i): string => sprintf('w%02dm%02d', $w, $i), range(1, 50));
        }

        $results = $this->race($workers);
        $counts = array_count_values($results);

        $this->assertCount(1000, $results);
        $this->assertSame(10, $counts['created'] ?? 0, json_encode($counts) ?: '');
        // The others are refused: by the mailbox limit, or by the domain quota left (checked first, research R8).
        $this->assertSame(990, ($counts['mailbox-limit-reached'] ?? 0) + ($counts['validation-failed'] ?? 0), json_encode($counts) ?: '');
        $this->assertSame(10, $this->observer->table('mailbox')->where('domain', $this->domain)->count());
        $this->assertLessThanOrEqual(10 * self::GIB, (int) $this->observer->table('mailbox')->where('domain', $this->domain)->sum('quota'));
    }

    public function test_of_two_concurrent_creations_of_one_address_exactly_one_succeeds(): void
    {
        $results = $this->race([['same'], ['same']]);
        sort($results);

        $this->assertSame(['address-in-use', 'created'], $results);
        $this->assertSame(1, $this->observer->table('mailbox')->where('domain', $this->domain)->count());
    }
}
