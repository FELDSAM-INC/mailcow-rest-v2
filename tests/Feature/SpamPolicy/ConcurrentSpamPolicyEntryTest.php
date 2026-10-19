<?php

namespace Tests\Feature\SpamPolicy;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 006 T063 (FR-047, research R11): two processes adding the same pattern to the same owner
 * at the same time, on separate database connections, end with one `201`, one `409` and one row:
 * the check and the insert run under `GET_LOCK()`, as no index of the upstream table guards it. The
 * rows are committed (the processes cannot see a test transaction) and removed in tearDown().
 */
#[Group('concurrency')]
class ConcurrentSpamPolicyEntryTest extends TestCase
{
    use ApiKeys;

    /** @var list<string> committed rows instead of one transaction per test */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        DB::table('filterconf')->where('object', 'race@race.example')->delete();
        DB::table('mailbox')->where('username', 'race@race.example')->delete();
        DB::table('user_acl')->where('username', 'race@race.example')->delete();
        DB::table('domain')->where('domain', 'race.example')->delete();
        DB::table('admin')->where('username', 'race-admin')->delete();
        DB::table('restv2_audit_events')->where('actor_username', 'race-admin')->delete();
        DB::table('restv2_api_keys')->where('owner_username', 'race-admin')->delete();
        parent::tearDown();
    }

    public function test_two_simultaneous_identical_additions_store_one_entry(): void
    {
        $this->mailcow()->mailboxWithDomain('race@race.example');
        $this->mailcow()->admin('race-admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('race-admin');
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $processes = [];

        foreach (['block', 'allow'] as $list) {
            $process = new Process([$php, base_path('tests/bin/send-request-once.php'), 'POST', '/api/v2/mailboxes/race@race.example/spam-policy-entries', $key['secret'], (string) json_encode(['list' => $list, 'pattern' => '*@race-spam.example'])], base_path());
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
        $this->assertSame(1, DB::table('filterconf')->where('object', 'race@race.example')->count());
    }
}
