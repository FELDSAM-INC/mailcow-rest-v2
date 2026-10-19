<?php

namespace App\Services\MassMail;

use App\Audit\AuditEventType;
use App\Clients\Lmtp\LmtpClient;
use App\Clients\Lmtp\LmtpResult;
use App\Http\RequestContext;
use App\Models\MassMailJob;
use App\Models\MassMailRecipient;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsLimits;
use App\Support\Clock\Clock;
use App\Support\Clock\Sleeper;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Delivers the running mass mail job in the background (feature 013 FR-076, FR-079, FR-087,
 * research R17), started by mailcow's ofelia scheduler every 10 seconds
 * (`restv2:mass-mail-run --max-time=290`, no overlap) and guarded by a Redis lock, so that one
 * runner works at a time:
 *
 *  1. Recovery: a recipient left `delivering` by an interrupted run is `failed` (`interrupted`):
 *     its message may have been accepted, and at-most-once delivery forbids a retry.
 *  2. Per recipient, at most `RESTV2_MASS_MAIL_RATE` delivery starts per second: claim the next due
 *     recipient (sorted by address) in a transaction that locks the job row and requires it to be
 *     running, so that no claim follows a confirmed cancellation; deliver over LMTP within the
 *     delivery deadline; record `delivered`, `failed` (a permanent refusal, with Dovecot's reply and
 *     a stable code) or, for a temporary refusal, a retry after 30, 60 and 120 seconds and then
 *     `failed`; a temporary refusal of a job cancelled meanwhile is `skipped`.
 *  3. Completion: when no recipient is pending or being delivered, the job is completed once, with
 *     an audit record (no actor; the creating request's ID and client address) of the counts.
 *
 * The run stops claiming once its time budget is used; the next run continues.
 */
class MassMailRunner
{
    public const LOCK = 'mass-mail-runner';

    private const LOCK_SECONDS = 330;

    public function __construct(
        private readonly LmtpClient $lmtp,
        private readonly MassMailMessageFactory $messages,
        private readonly MassMailJobService $jobs,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
    ) {}

    public function run(int $maxSeconds): void
    {
        $store = Cache::store('restv2')->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('The restv2 cache store cannot hold locks.');
        }

        $lock = $store->lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->recover();
            $job = MassMailJob::query()->where('status', MassMailJob::RUNNING)->first();

            if ($job instanceof MassMailJob) {
                $this->deliver($job, $this->clock->now()->addSeconds($maxSeconds));
            }
        } finally {
            $this->lmtp->close();
            $lock->release();
        }
    }

    private function recover(): void
    {
        DB::table('restv2_mass_mail_recipients')->where('outcome', MassMailRecipient::DELIVERING)->update([
            'outcome' => MassMailRecipient::FAILED,
            'failure_code' => 'interrupted',
            'failure_reason' => 'interrupted',
            'next_attempt_at' => null,
        ]);
    }

    private function deliver(MassMailJob $job, CarbonImmutable $deadline): void
    {
        $interval = 1 / max(1, (int) config('restv2.mass_mail.rate', 10));
        $lastStart = null;

        while ($this->clock->now()->lessThan($deadline)) {
            if ($lastStart !== null) {
                $gap = $interval - ($this->clock->now()->getTimestampMs() - $lastStart) / 1000;

                if ($gap > 0) {
                    $this->sleeper->sleepSeconds($gap);
                }
            }

            [$state, $recipient] = $this->claim($job->id);

            if ($state === 'stopped') {
                return;
            }

            if ($recipient === null) {
                if (! $this->waitForRetry($job, $deadline)) {
                    return;
                }

                continue;
            }

            $lastStart = $this->clock->now()->getTimestampMs();
            $message = $this->messages->build($job->id, $recipient->id, $job->sender, $recipient->address, $job->subject, $job->text_body, $job->html_body, $this->clock->now());
            $this->record($recipient, $this->lmtp->deliver($job->sender, $recipient->address, $message));
        }
    }

    /**
     * The next due recipient, claimed as `delivering`; `stopped` when the job no longer runs.
     *
     * @return array{string, MassMailRecipient|null}
     */
    private function claim(string $jobId): array
    {
        return DB::transaction(function () use ($jobId): array {
            $job = MassMailJob::query()->lockForUpdate()->find($jobId);

            if (! $job instanceof MassMailJob || $job->status !== MassMailJob::RUNNING) {
                return ['stopped', null];
            }

            $now = $this->clock->now()->utc();
            $recipient = MassMailRecipient::query()
                ->where('job_id', $jobId)
                ->where('outcome', MassMailRecipient::PENDING)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now->format('Y-m-d H:i:s.u')))
                ->orderBy('address')
                ->lockForUpdate()
                ->first();

            if (! $recipient instanceof MassMailRecipient) {
                return ['idle', null];
            }

            $recipient->forceFill(['outcome' => MassMailRecipient::DELIVERING, 'attempts' => $recipient->attempts + 1, 'last_attempt_at' => $now, 'next_attempt_at' => null])->save();

            if ($job->started_at === null) {
                $job->forceFill(['started_at' => $now])->save();
            }

            return ['claimed', $recipient];
        });
    }

    /**
     * Nothing is due now: completes the job when no recipient is left, otherwise waits for the
     * earliest retry within the budget. False when the run should end.
     */
    private function waitForRetry(MassMailJob $job, CarbonImmutable $deadline): bool
    {
        $next = MassMailRecipient::query()->where('job_id', $job->id)->whereIn('outcome', [MassMailRecipient::PENDING, MassMailRecipient::DELIVERING])->min('next_attempt_at');
        $left = MassMailRecipient::query()->where('job_id', $job->id)->whereIn('outcome', [MassMailRecipient::PENDING, MassMailRecipient::DELIVERING])->exists();

        if (! $left) {
            $this->complete($job->id);

            return false;
        }

        if ($next === null) {
            return false;
        }

        $due = CarbonImmutable::parse((string) $next, 'UTC');
        $wait = ($due->getTimestampMs() - $this->clock->now()->getTimestampMs()) / 1000;

        if ($this->clock->now()->addMilliseconds((int) ceil(max(0, $wait) * 1000))->greaterThan($deadline)) {
            return false;
        }

        if ($wait > 0) {
            $this->sleeper->sleepSeconds($wait);
        }

        return true;
    }

    private function record(MassMailRecipient $recipient, LmtpResult $result): void
    {
        DB::transaction(function () use ($recipient, $result): void {
            $job = MassMailJob::query()->lockForUpdate()->find($recipient->job_id);
            $running = $job instanceof MassMailJob && $job->status === MassMailJob::RUNNING;
            $reason = mb_substr($result->reply(), 0, 1024);

            $values = match (true) {
                $result->isDelivered() => ['outcome' => MassMailRecipient::DELIVERED],
                ! $result->isTemporary() => ['outcome' => MassMailRecipient::FAILED, 'failure_code' => self::permanentCode($result), 'failure_reason' => $reason],
                ! $running => ['outcome' => MassMailRecipient::SKIPPED],
                $recipient->attempts > SettingsLimits::MASS_MAIL_MAX_RETRIES => ['outcome' => MassMailRecipient::FAILED, 'failure_code' => self::temporaryCode($result), 'failure_reason' => $reason],
                default => [
                    'outcome' => MassMailRecipient::PENDING,
                    'next_attempt_at' => $this->clock->now()->utc()->addSeconds(SettingsLimits::MASS_MAIL_RETRY_DELAYS[$recipient->attempts - 1]),
                ],
            };

            DB::table('restv2_mass_mail_recipients')->where('id', $recipient->id)->update($values);
        });
    }

    private function complete(string $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            $job = MassMailJob::query()->lockForUpdate()->find($jobId);

            if (! $job instanceof MassMailJob || $job->status !== MassMailJob::RUNNING) {
                return;
            }

            $job->forceFill(['status' => MassMailJob::COMPLETED, 'running_slot' => null, 'finished_at' => $this->clock->now()->utc()])->save();
            $this->inCreationContext($job, fn () => $this->audit->record(AuditEventType::MassMailJobCompletion, true, null, MassMailJobService::target($job), null, null, ['counts' => $this->jobs->counts([$job->id])[$job->id]]));
        });
    }

    /**
     * Runs the audit call with the creating request's ID and client address, so that the completion
     * record is found next to the creation record.
     */
    private function inCreationContext(MassMailJob $job, callable $call): void
    {
        $previous = app()->bound('request') ? app('request') : null;
        $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $job->created_client_address]);
        RequestContext::setRequestId($request, $job->created_request_id);
        app()->instance('request', $request);

        try {
            $call();
        } finally {
            if ($previous !== null) {
                app()->instance('request', $previous);
            }
        }
    }

    /** The stable code of a permanent refusal (gap G16). */
    private static function permanentCode(LmtpResult $result): string
    {
        $enhanced = preg_match('/^\s*([245])\.(\d{1,3})\.(\d{1,3})/', $result->text, $m) === 1 ? [(int) $m[1], (int) $m[2], (int) $m[3]] : null;

        return match (true) {
            $enhanced !== null && $enhanced[1] === 2 && $enhanced[2] === 2 => 'overQuota',
            $enhanced !== null && $enhanced[1] === 1 => 'mailboxUnavailable',
            $enhanced === null && $result->code === 550 => 'mailboxUnavailable',
            default => 'rejected',
        };
    }

    /** The stable code of a temporary refusal that persisted after the retries (gap G16). */
    private static function temporaryCode(LmtpResult $result): string
    {
        return match (true) {
            $result->connectionFailed => 'connectionFailed',
            preg_match('/^\s*4\.2\.2/', $result->text) === 1 => 'overQuota',
            default => 'temporaryFailurePersisted',
        };
    }
}
