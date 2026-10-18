<?php

namespace Tests\Feature\SyncJobs;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 008 T038 (spec edge case "Concurrent creation of the same job", research R9): two
 * processes creating the same job at the same time end with exactly one `201`, one `409` and one
 * row. The rows are committed (the two processes cannot see a test transaction) and removed again in
 * tearDown().
 */
#[Group('concurrency')]
class ConcurrentCreateSyncJobTest extends TestCase
{
    use ApiKeys;

    /** @var list<string> committed rows instead of one transaction per test */
    protected array $connectionsToTransact = [];

    private ?string $keyId = null;

    protected function tearDown(): void
    {
        DB::table('imapsync')->where('user2', 'race@race.example')->delete();
        DB::table('mailbox')->where('username', 'race@race.example')->delete();
        DB::table('user_acl')->where('username', 'race@race.example')->delete();
        DB::table('domain')->where('domain', 'race.example')->delete();
        DB::table('admin')->where('username', 'race-admin')->delete();
        DB::table('restv2_audit_events')->where('target_name', 'race@race.example')->delete();

        if ($this->keyId !== null) {
            DB::table('restv2_api_keys')->where('id', $this->keyId)->delete();
        }

        parent::tearDown();
    }

    public function test_two_simultaneous_identical_creations_store_one_job(): void
    {
        $this->mailcow()->mailboxWithDomain('race@race.example');
        $this->mailcow()->admin('race-admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('race-admin', 'readWrite');
        $this->keyId = $key['id'];
        $body = (string) json_encode(['mailbox' => 'race@race.example', 'remoteHost' => 'imap.race.example', 'remotePort' => 993, 'remoteUser' => 'race', 'remotePassword' => 'x']);

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $processes = [];

        for ($i = 0; $i < 2; $i++) {
            $process = new Process([$php, base_path('tests/bin/create-sync-job-once.php'), $key['secret'], $body], base_path());
            $process->setTimeout(60);
            $process->start();
            $processes[] = $process;
        }

        $statuses = [];

        foreach ($processes as $process) {
            $process->wait();
            $statuses[] = trim($process->getOutput());
        }

        sort($statuses);
        $this->assertSame(['201', '409'], $statuses, implode("\n", array_map(fn (Process $p): string => $p->getErrorOutput(), $processes)));
        $this->assertSame(1, DB::table('imapsync')->where('user2', 'race@race.example')->count());
    }
}
