<?php

namespace App\Services\MassMail;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Models\MassMailJob;
use App\Models\MassMailRecipient;
use App\Services\Audit\AuditLogger;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Mass mail jobs (feature 013 FR-074 to FR-080, research R17). A job is created in one database
 * transaction with its creation audit record: the selection is evaluated again, its recipient
 * count must equal the confirmed `expectedRecipients` (`409 mass-mail-recipient-count-changed`
 * otherwise), only one job may run (`409 mass-mail-already-running`; the unique `running_slot`
 * catches a race), and every recipient row is inserted as `pending`, which fixes the selection.
 * The background runner (MassMailRunner) delivers. Cancelling locks the job row, so no delivery can
 * be claimed after it; pending recipients become `skipped`. Counts are computed per outcome, with
 * `delivering` counted as `pending`.
 */
class MassMailJobService
{
    /** Contract sort => column of the job list. */
    public const JOB_SORTS = ['createdAt' => 'created_at', 'status' => 'status'];

    /** Contract sort => column of the recipient list. */
    public const RECIPIENT_SORTS = ['address' => 'address', 'outcome' => 'outcome'];

    private const OUTCOMES = ['pending', 'delivered', 'failed', 'skipped'];

    public function __construct(
        private readonly SelectionResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Creates a job from validated values (the selection already validated).
     *
     * @param  array{sender: string, subject: string, textBody: string, htmlBody: string|null, expectedRecipients: int}  $values
     * @return array<string, mixed>
     */
    public function create(array $values, Selection $selection, Principal $actor, Request $request): array
    {
        $id = DB::transaction(function () use ($values, $selection, $actor, $request): string {
            $this->refuseWhileRunning();
            $addresses = $this->resolver->addresses($selection);

            if ($addresses === []) {
                $violations = new FieldViolations;
                $violations->add('selection', 'tooFew', ['min' => 1], 'The selection has no recipients.');
                $violations->throwIfAny();
            }

            if (count($addresses) !== $values['expectedRecipients']) {
                throw new ProblemException(ProblemType::MassMailRecipientCountChanged, null, ['actualRecipients' => count($addresses)]);
            }

            $job = new MassMailJob;

            try {
                $job->forceFill([
                    'status' => MassMailJob::RUNNING,
                    'running_slot' => 1,
                    'sender' => $values['sender'],
                    'subject' => $values['subject'],
                    'text_body' => $values['textBody'],
                    'html_body' => $values['htmlBody'],
                    'selection' => $selection->toArray(),
                    'recipient_count' => count($addresses),
                    'created_by' => $actor->actingAccount()->username,
                    'created_request_id' => RequestContext::requestId($request),
                    'created_client_address' => (string) $request->ip(),
                    'created_at' => CarbonImmutable::now('UTC'),
                ])->save();
            } catch (QueryException $e) {
                // Another job started between the check and the insert (unique running_slot).
                $this->refuseWhileRunning();

                throw $e;
            }

            foreach (array_chunk($addresses, 1000) as $chunk) {
                DB::table('restv2_mass_mail_recipients')->insert(array_map(fn (string $address): array => ['job_id' => $job->id, 'address' => $address], $chunk));
            }

            $this->audit->record(AuditEventType::MassMailJobCreation, true, $actor, self::target($job), null, null, [
                'sender' => $job->sender,
                'subject' => $job->subject,
                'selection' => $selection->summary(),
                'recipientCount' => $job->recipient_count,
            ]);

            return $job->id;
        });

        return (array) $this->find($id);
    }

    /**
     * Cancels a running job; a cancelled job stays as it is, a completed job is a conflict.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id, Principal $actor): array
    {
        DB::transaction(function () use ($id, $actor): void {
            $job = MassMailJob::query()->lockForUpdate()->find($id);

            if (! $job instanceof MassMailJob) {
                throw new NotFoundHttpException('The mass mail job does not exist.');
            }

            if ($job->status === MassMailJob::COMPLETED) {
                throw new ConflictHttpException('The mass mail job is already completed.');
            }

            if ($job->status === MassMailJob::CANCELLED) {
                return;
            }

            $job->forceFill(['status' => MassMailJob::CANCELLED, 'running_slot' => null, 'finished_at' => CarbonImmutable::now('UTC'), 'cancelled_by' => $actor->actingAccount()->username])->save();
            DB::table('restv2_mass_mail_recipients')->where('job_id', $id)->where('outcome', MassMailRecipient::PENDING)->update(['outcome' => MassMailRecipient::SKIPPED, 'next_attempt_at' => null]);

            $this->audit->record(AuditEventType::MassMailJobCancellation, true, $actor, self::target($job), null, null, ['counts' => $this->counts([$id])[$id]]);
        });

        return (array) $this->find($id);
    }

    /**
     * Contract `MassMailJob`, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $job = MassMailJob::query()->find($id);

        return $job instanceof MassMailJob ? self::present($job, $this->counts([$id])[$id], true) : null;
    }

    /**
     * Job summaries (gap G17: without their bodies).
     *
     * @return array{data: list<mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(ListQuery $query, ?string $status): array
    {
        $builder = MassMailJob::query();

        if ($status !== null) {
            $builder->where('status', $status);
        }

        $query->applySearch($builder, ['subject']);
        $page = $query->paginate($builder, null, 'id');
        $jobs = $page['data'];
        $counts = $this->counts(array_map(fn (MassMailJob $job): string => $job->id, $jobs));
        $page['data'] = array_map(fn (MassMailJob $job): array => self::present($job, $counts[$job->id], false), $jobs);

        return $page;
    }

    /**
     * A job's recipients (`MassMailRecipient`); `pending` also matches `delivering`.
     *
     * @return array{data: list<mixed>, meta: array{total: int, limit: int, offset: int}}
     */
    public function recipients(string $id, ListQuery $query, ?string $outcome): array
    {
        if (! MassMailJob::query()->whereKey($id)->exists()) {
            throw new NotFoundHttpException('The mass mail job does not exist.');
        }

        $builder = MassMailRecipient::query()->where('job_id', $id);

        if ($outcome !== null) {
            $builder->whereIn('outcome', $outcome === MassMailRecipient::PENDING ? [MassMailRecipient::PENDING, MassMailRecipient::DELIVERING] : [$outcome]);
        }

        $query->applySearch($builder, ['address']);

        return $query->paginate($builder, fn (MassMailRecipient $r): array => [
            'address' => $r->address,
            'outcome' => $r->outcome === MassMailRecipient::DELIVERING ? MassMailRecipient::PENDING : $r->outcome,
            'failureCode' => $r->outcome === MassMailRecipient::FAILED ? $r->failure_code : null,
            'failureReason' => $r->outcome === MassMailRecipient::FAILED ? $r->failure_reason : null,
            'attempts' => $r->attempts,
            'lastAttemptAt' => $r->last_attempt_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ], 'address');
    }

    /**
     * Counts per outcome of the given jobs, `delivering` counted as `pending`.
     *
     * @param  list<string>  $ids
     * @return array<string, array{pending: int, delivered: int, failed: int, skipped: int}>
     */
    public function counts(array $ids): array
    {
        $counts = array_fill_keys($ids, array_fill_keys(self::OUTCOMES, 0));

        if ($ids === []) {
            return $counts;
        }

        /** @var Collection<int, object{job_id: string, outcome: string, total: int}> $rows */
        $rows = DB::table('restv2_mass_mail_recipients')->whereIn('job_id', $ids)->groupBy('job_id', 'outcome')->get(['job_id', 'outcome', DB::raw('COUNT(*) AS total')]);

        foreach ($rows as $row) {
            $outcome = $row->outcome === MassMailRecipient::DELIVERING ? MassMailRecipient::PENDING : (string) $row->outcome;

            if (isset($counts[$row->job_id][$outcome])) {
                $counts[$row->job_id][$outcome] += (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    public static function target(MassMailJob $job): array
    {
        return ['type' => 'massMailJob', 'id' => $job->id, 'name' => mb_substr($job->subject, 0, 255)];
    }

    private function refuseWhileRunning(): void
    {
        $running = MassMailJob::query()->where('status', MassMailJob::RUNNING)->value('id');

        if (is_string($running)) {
            throw new ProblemException(ProblemType::MassMailAlreadyRunning, null, ['runningJobId' => $running]);
        }
    }

    /**
     * Contract `MassMailJob`, or `MassMailJobSummary` without the bodies.
     *
     * @param  array{pending: int, delivered: int, failed: int, skipped: int}  $counts
     * @return array<string, mixed>
     */
    private static function present(MassMailJob $job, array $counts, bool $withBodies): array
    {
        $time = fn (?CarbonImmutable $t): ?string => $t?->utc()->format('Y-m-d\TH:i:s\Z');
        $selection = $job->selection;

        return [
            'id' => $job->id,
            'status' => $job->status,
            'sender' => $job->sender,
            'subject' => $job->subject,
        ] + ($withBodies ? ['textBody' => $job->text_body, 'htmlBody' => $job->html_body] : []) + [
            'selection' => ['domains' => $selection['domains'] ?? [], 'include' => $selection['include'] ?? [], 'exclude' => $selection['exclude'] ?? []],
            'recipientCount' => $job->recipient_count,
            'counts' => $counts,
            'createdBy' => ['username' => $job->created_by],
            'createdAt' => (string) $time($job->created_at),
            'startedAt' => $time($job->started_at),
            'finishedAt' => $time($job->finished_at),
            'cancelledBy' => $job->cancelled_by === null ? null : ['username' => $job->cancelled_by],
        ];
    }
}
