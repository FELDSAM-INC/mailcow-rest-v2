<?php

namespace Tests\Feature\MassMail;

use App\Clients\Lmtp\LmtpResult;
use App\Services\MassMail\MassMailRunner;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T068 (FR-076, FR-079, FR-087, SC-008, SC-009): the background runner delivers one
 * message per recipient over LMTP at the configured rate, retries temporary refusals after 30, 60
 * and 120 seconds, never delivers twice (an interrupted delivery is recorded as failed), and
 * completes the job with one audit record.
 */
class MassMailRunnerTest extends TestCase
{
    use MassMailTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sleeper()->advancing($this->clock());
    }

    private function runner(): MassMailRunner
    {
        return $this->app->make(MassMailRunner::class);
    }

    public function test_every_recipient_gets_one_message_addressed_only_to_itself_and_the_job_completes(): void
    {
        $job = $this->runningJob(['a@example.org', 'b@example.org', 'c@example.net']);

        $this->runner()->run(290);

        $deliveries = $this->lmtp()->deliveries();
        $this->assertSame(['a@example.org', 'b@example.org', 'c@example.net'], array_column($deliveries, 'to'));
        $this->assertSame(['noreply@mail.example.org'], array_values(array_unique(array_column($deliveries, 'from'))));
        $this->assertMatchesRegularExpression('/^To: b@example\.org\r$/m', $deliveries[1]['message']);
        $this->assertMatchesRegularExpression('/^X-Mailer: MooMassMail\r$/m', $deliveries[1]['message']);
        $this->assertSame(['a@example.org' => 'delivered', 'b@example.org' => 'delivered', 'c@example.net' => 'delivered'], $this->outcomes($job));
        $row = DB::table('restv2_mass_mail_jobs')->where('id', $job)->first();
        $this->assertNotNull($row);
        $this->assertSame(['completed', null], [$row->status, $row->running_slot]);
        $this->assertNotNull($row->started_at);
        $this->assertNotNull($row->finished_at);

        $records = $this->auditOf('massMailJobCompletion');
        $this->assertCount(1, $records);
        $this->assertSame([null, null, 'massMailJob', $job, 'Maintenance'], [$records[0]->actor_username, $records[0]->actor_role, $records[0]->target_type, $records[0]->target_id, $records[0]->target_name]);
        $this->assertSame([str_repeat('a', 32), '192.0.2.10'], [$records[0]->request_id, $records[0]->client_address]);
        $this->assertSame(['counts' => ['pending' => 0, 'delivered' => 3, 'failed' => 0, 'skipped' => 0]], $records[0]->details);
        $this->assertSame('success', $records[0]->outcome);
    }

    public function test_deliveries_start_at_most_rate_times_per_second(): void
    {
        $this->runningJob(['a@example.org', 'b@example.org', 'c@example.org', 'd@example.org']);
        /** @var \ArrayObject<int, int> $starts */
        $starts = new \ArrayObject;
        $this->lmtp()->onDeliver(function () use ($starts): void {
            $starts->append($this->clock()->now()->getTimestampMs());
        });

        $this->runner()->run(290);

        $this->assertCount(4, $starts);

        for ($i = 1; $i < count($starts); $i++) {
            $this->assertGreaterThanOrEqual(100, $starts[$i] - $starts[$i - 1]);
        }

        config(['restv2.mass_mail.rate' => 50]);
        $this->runningJob(['e@example.org', 'f@example.org'], ['running_slot' => 1]);
        $starts->exchangeArray([]);
        $this->runner()->run(290);
        $this->assertCount(2, $starts);
        $this->assertGreaterThanOrEqual(20, $starts[1] - $starts[0]);
        $this->assertLessThan(100, $starts[1] - $starts[0]);
    }

    public function test_a_temporary_refusal_is_retried_three_times_then_failed(): void
    {
        $job = $this->runningJob(['full@example.org']);
        $this->lmtp()->replyTo('full@example.org', LmtpResult::temporaryFailure(452, '4.2.2 Mailbox is full'));
        $attempts = [];
        $this->lmtp()->onDeliver(function () use (&$attempts): void {
            $attempts[] = $this->clock()->now()->getTimestamp();
        });

        $this->runner()->run(600);

        $this->assertCount(4, $attempts);
        $this->assertEqualsWithDelta(30, $attempts[1] - $attempts[0], 1);
        $this->assertEqualsWithDelta(60, $attempts[2] - $attempts[1], 1);
        $this->assertEqualsWithDelta(120, $attempts[3] - $attempts[2], 1);
        $row = DB::table('restv2_mass_mail_recipients')->where('job_id', $job)->first();
        $this->assertNotNull($row);
        $this->assertSame(['failed', 4, '452 4.2.2 Mailbox is full', 'overQuota'], [$row->outcome, (int) $row->attempts, $row->failure_reason, $row->failure_code]);
    }

    public function test_a_permanent_refusal_fails_at_once_with_dovecot_s_reply(): void
    {
        $job = $this->runningJob(['gone@example.org', 'ok@example.org', 'odd@example.org']);
        $this->lmtp()->replyTo('gone@example.org', LmtpResult::permanentFailure(550, '5.1.1 <gone@example.org> User doesn\'t exist'));
        $this->lmtp()->replyTo('odd@example.org', LmtpResult::permanentFailure(554, '5.6.0 Message rejected'));

        $this->runner()->run(290);

        $rows = DB::table('restv2_mass_mail_recipients')->where('job_id', $job)->orderBy('address')->get()->keyBy('address');
        $this->assertSame(['failed', 1, 'mailboxUnavailable'], [$rows['gone@example.org']->outcome, (int) $rows['gone@example.org']->attempts, $rows['gone@example.org']->failure_code]);
        $this->assertSame("550 5.1.1 <gone@example.org> User doesn't exist", $rows['gone@example.org']->failure_reason);
        $this->assertSame(['failed', 'rejected'], [$rows['odd@example.org']->outcome, $rows['odd@example.org']->failure_code]);
        $this->assertSame('delivered', $rows['ok@example.org']->outcome);
    }

    public function test_connection_failures_are_retried_and_then_failed_as_connection_failures(): void
    {
        $job = $this->runningJob(['a@example.org']);
        $this->lmtp()->replyTo('a@example.org', LmtpResult::connectionFailure('connection refused'));

        $this->runner()->run(600);

        $row = DB::table('restv2_mass_mail_recipients')->where('job_id', $job)->first();
        $this->assertNotNull($row);
        $this->assertSame(['failed', 'connectionFailed', 'connection refused'], [$row->outcome, $row->failure_code, $row->failure_reason]);
    }

    public function test_an_interrupted_delivery_is_failed_and_never_delivered_again(): void
    {
        $job = $this->runningJob(['a@example.org', 'b@example.org']);
        DB::table('restv2_mass_mail_recipients')->where('address', 'a@example.org')->update(['outcome' => 'delivering', 'attempts' => 1]);

        $this->runner()->run(290);

        $this->assertSame(['b@example.org'], array_column($this->lmtp()->deliveries(), 'to'));
        $row = DB::table('restv2_mass_mail_recipients')->where('address', 'a@example.org')->first();
        $this->assertNotNull($row);
        $this->assertSame(['failed', 'interrupted', 'interrupted'], [$row->outcome, $row->failure_reason, $row->failure_code]);
        $this->assertSame(['pending' => 0, 'delivered' => 1, 'failed' => 1, 'skipped' => 0], $this->auditOf('massMailJobCompletion')[0]->details['counts']);
        $this->assertSame('completed', DB::table('restv2_mass_mail_jobs')->where('id', $job)->value('status'));
    }

    public function test_the_job_completes_once_and_a_second_run_does_nothing(): void
    {
        $this->runningJob(['a@example.org']);

        $this->runner()->run(290);
        $this->runner()->run(290);

        $this->assertCount(1, $this->lmtp()->deliveries());
        $this->assertCount(1, $this->auditOf('massMailJobCompletion'));
    }

    public function test_a_second_runner_exits_while_the_lock_is_held(): void
    {
        $this->runningJob(['a@example.org']);
        $store = Cache::store('restv2')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $lock = $store->lock(MassMailRunner::LOCK, 330);
        $this->assertTrue($lock->get());

        try {
            $this->runner()->run(290);
        } finally {
            $lock->release();
        }

        $this->lmtp()->assertNothingDelivered();
    }

    public function test_without_a_running_job_the_runner_exits_at_once(): void
    {
        $this->runner()->run(290);

        $this->lmtp()->assertNothingDelivered();
        $this->assertSame([], $this->sleeper()->slept);
    }

    public function test_the_run_stops_at_its_time_budget_and_the_next_run_continues(): void
    {
        $job = $this->runningJob(array_map(fn (int $i): string => sprintf('u%02d@example.org', $i), range(1, 30)));

        $this->runner()->run(1);
        $first = count($this->lmtp()->deliveries());
        $this->runner()->run(290);

        $this->assertLessThan(30, $first);
        $this->assertCount(30, $this->lmtp()->deliveries());
        $this->assertSame(30, count(array_filter($this->outcomes($job), fn (string $o): bool => $o === 'delivered')));
    }

    /**
     * SC-009 in simulated time: the rate decides the duration, so 1,000 recipients take about
     * 100 seconds at 10 per second (10,000 about 1,000 seconds, see the performance group).
     */
    public function test_recipients_complete_within_the_rate_budget(): void
    {
        $this->runningJob(array_map(fn (int $i): string => sprintf('u%05d@example.org', $i), range(1, 1000)));
        $start = $this->clock()->now()->getTimestamp();

        while (DB::table('restv2_mass_mail_jobs')->where('status', 'running')->exists()) {
            $this->runner()->run(290);
        }

        $this->assertCount(1000, $this->lmtp()->deliveries());
        $this->assertLessThanOrEqual(105, $this->clock()->now()->getTimestamp() - $start);
    }

    #[Group('performance')]
    public function test_ten_thousand_recipients_complete_within_twenty_minutes_of_simulated_time(): void
    {
        $this->runningJob(array_map(fn (int $i): string => sprintf('u%05d@example.org', $i), range(1, 10000)));
        $start = $this->clock()->now()->getTimestamp();

        while (DB::table('restv2_mass_mail_jobs')->where('status', 'running')->exists()) {
            $this->runner()->run(290);
        }

        $this->assertCount(10000, $this->lmtp()->deliveries());
        $this->assertLessThanOrEqual(1050, $this->clock()->now()->getTimestamp() - $start);
    }

    public function test_the_command_runs_the_runner(): void
    {
        $this->runningJob(['a@example.org']);

        $this->artisan('restv2:mass-mail-run', ['--max-time' => 290])->assertSuccessful();

        $this->assertCount(1, $this->lmtp()->deliveries());
    }
}
