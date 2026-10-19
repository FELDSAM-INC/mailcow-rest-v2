<?php

namespace Tests\Feature\Quarantine;

use App\Services\Quarantine\QuarantineItemLock;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T044 (FR-026, AS-2.5, SC-006): an item is released, learned or deleted by at most one
 * request of this API. While another request holds the item, actions answer `409
 * quarantine-item-busy`; once it is gone, `404`. Ten processes releasing the same 100 items in
 * shuffled order (1,000 attempts) deliver and learn each item exactly once. The rows are committed
 * (other processes cannot see a test transaction) and removed again in tearDown().
 */
#[Group('concurrency')]
class QuarantineConcurrencyTest extends TestCase
{
    use QuarantineTestHelpers;

    /** @var list<string> committed rows instead of one transaction per test */
    protected array $connectionsToTransact = [];

    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->directory = sys_get_temp_dir().'/restv2-race-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        DB::table('quarantine')->where('rcpt', 'like', '%@race.example')->delete();
        DB::table('mailbox')->where('domain', 'race.example')->delete();
        DB::table('user_acl')->where('username', 'like', '%@race.example')->delete();
        DB::table('domain')->where('domain', 'race.example')->delete();
        DB::table('admin')->where('username', 'race-admin')->delete();
        DB::table('restv2_audit_events')->where('actor_username', 'race-admin')->delete();
        DB::table('restv2_api_keys')->where('owner_username', 'race-admin')->delete();

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
        parent::tearDown();
    }

    /**
     * @return list<int>
     */
    private function seedItems(int $count): array
    {
        $this->quarantineMailbox('anna@race.example');
        $this->mailcow()->admin('race-admin', 'Passw0rd!', 'SSHA256');
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->item('anna@race.example', overrides: ['qid' => sprintf('RACE%06d', $i), 'msg' => "Subject: Race {$i}\r\n\r\nBody {$i}\r\n"]);
        }

        return $ids;
    }

    public function test_a_held_item_is_busy_and_a_removed_one_not_found(): void
    {
        [$id] = $this->seedItems(1);
        $key = $this->apiKey('race-admin');
        $lock = $this->app->make(QuarantineItemLock::class)->acquire($id);
        $this->assertNotNull($lock);

        foreach (["/api/v2/quarantine/{$id}/release", "/api/v2/quarantine/{$id}/spam-learning"] as $uri) {
            $this->send('POST', $uri, null, $this->keyHeader($key))
                ->assertStatus(409)
                ->assertHeader('Retry-After', '5')
                ->assertJsonPath('type', '/api/v2/problems/quarantine-item-busy');
        }

        $this->send('DELETE', "/api/v2/quarantine/{$id}", null, $this->keyHeader($key))->assertStatus(409);
        $this->mailer->assertNothingDelivered();
        $this->spamFilter->assertNoCalls();

        DB::table('quarantine')->where('id', $id)->delete();
        $this->send('POST', "/api/v2/quarantine/{$id}/release", null, $this->keyHeader($key))->assertNotFound();
        QuarantineItemLock::release($lock);
    }

    public function test_a_lock_left_by_a_killed_process_expires(): void
    {
        config(['restv2.quarantine.lock_ttl' => 1]);
        [$id] = $this->seedItems(1);
        $this->assertNotNull($this->app->make(QuarantineItemLock::class)->acquire($id));

        $this->send('POST', "/api/v2/quarantine/{$id}/release", null, $this->keyHeader($this->apiKey('race-admin')))->assertStatus(409);
        usleep(1_200_000);
        $this->send('POST', "/api/v2/quarantine/{$id}/release", null, $this->keyHeader($this->apiKey('race-admin')))->assertOk();
    }

    public function test_a_thousand_concurrent_attempts_deliver_and_learn_each_item_once(): void
    {
        $ids = $this->seedItems(100);
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $processes = [];

        for ($p = 0; $p < 10; $p++) {
            $order = $ids;
            shuffle($order);
            $process = new Process([$php, 'artisan', 'restv2:test-release', '--delay=20', '--mailer-log='.$this->directory.'/mailer.log', '--rspamd-log='.$this->directory.'/rspamd.log', '--actor=race-admin', ...array_map('strval', $order)], base_path());
            $process->setTimeout(300);
            $process->start();
            $processes[] = $process;
        }

        $outcomes = ['done' => 0, 'busy' => 0, 'notFound' => 0];

        foreach ($processes as $process) {
            $process->wait();
            $counts = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($counts, $process->getErrorOutput().$process->getOutput());

            foreach ($counts as $outcome => $count) {
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + $count;
            }
        }

        $this->assertSame(1000, array_sum($outcomes), json_encode($outcomes));
        $this->assertSame(100, $outcomes['done'], json_encode($outcomes));
        $this->assertSame(900, $outcomes['busy'] + $outcomes['notFound'], json_encode($outcomes));

        $deliveries = array_map(fn (string $line): array => (array) json_decode($line, true), file($this->directory.'/mailer.log', FILE_IGNORE_NEW_LINES) ?: []);
        $this->assertCount(100, $deliveries);
        $this->assertCount(100, array_unique(array_column($deliveries, 'sha256')));
        $learning = array_map(fn (string $line): array => (array) json_decode($line, true), file($this->directory.'/rspamd.log', FILE_IGNORE_NEW_LINES) ?: []);
        $this->assertCount(100, array_filter($learning, fn (array $r): bool => $r['endpoint'] === '/learnham'));
        $this->assertCount(100, array_filter($learning, fn (array $r): bool => $r['endpoint'] === '/fuzzyadd'));
        $this->assertSame(0, DB::table('quarantine')->where('rcpt', 'anna@race.example')->count());
    }
}
