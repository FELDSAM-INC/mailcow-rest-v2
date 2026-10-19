<?php

namespace App\Services\Quarantine;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Mail\QuarantineReleaseMailer;
use App\Clients\Rspamd\LearnOutcome;
use App\Clients\Rspamd\LearnResult;
use App\Clients\Rspamd\RspamdController;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Models\Mailcow\QuarantineItem;
use App\Policies\QuarantinePolicy;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use App\Support\Runtime\TimeLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Redis;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * Releases, learns as spam and deletes quarantine items (feature 006 research R3, R4, R19,
 * FR-019 to FR-028), each item at most once through this API:
 *
 *   policy (`403`) → scoped lookup (`404`) → `If-Match` (`412`) → item lock (busy: `404` when the
 *   item is gone meanwhile, else `409 quarantine-item-busy`) → re-read with the stored message →
 *   the steps.
 *
 *  - Release: deliver in the configured format (`Q_RELEASE_FORMAT`; a refusal keeps the item:
 *    `409 quarantine-delivery-refused`, an unreachable mail server `503`) → remove the item in its
 *    own transaction → learn as ham and store the fuzzy hashes as wanted (flag 13) unless turned off;
 *    a failed learning is reported and never undoes the release → audit. The delivery cannot be
 *    taken back, so a failed audit write answers `500` and logs the complete record.
 *  - Learn as spam: learn (a failure keeps the item: `503`) → fuzzy hashes as unwanted (flag 11) →
 *    remove the item and audit in one transaction. Legacy removes the item first (deviation).
 *  - Delete: remove the item and audit in one transaction.
 *
 * The item's notification state and `Q_LAST_NOTIFIED` are never touched (FR-028). Refusals are
 * audited by the shared refusal auditing (route default `restv2.audit`, bulk items by the bulk
 * runner), not here.
 */
class QuarantineActionService
{
    public const FLAG_WANTED = 13;

    public const FLAG_UNWANTED = 11;

    public function __construct(
        private readonly QuarantinePolicy $policy,
        private readonly QuarantineQuery $query,
        private readonly QuarantineItemLock $locks,
        private readonly QuarantineReleaseMailer $mailer,
        private readonly RspamdController $rspamd,
        private readonly ReleaseMessageFactory $messages,
        private readonly SharedStore $store,
        private readonly AuditLogger $audit,
    ) {}

    public function release(Principal $principal, int $id, bool $learnAsHam, ?string $ifMatch, ?string $bulkRequestId = null): ActionReport
    {
        return $this->locked($principal, $id, $ifMatch, function (QuarantineItem $item) use ($principal, $learnAsHam, $bulkRequestId): ActionReport {
            $stored = (string) $item->getRawOriginal('msg');
            $sender = ReleaseMessageFactory::envelopeSender((string) $item->getRawOriginal('sender'));
            $recipient = (string) $item->getRawOriginal('rcpt');
            $message = $this->releaseFormat() === 'attachment'
                ? $this->messages->attachment($stored, (string) $item->getRawOriginal('qid'), $sender, $recipient, $principal)
                : $this->messages->original($stored);

            $outcome = $this->mailer->deliver($message, $sender, $recipient);

            if ($outcome->isRefused()) {
                throw ProblemException::quarantineDeliveryRefused((int) $outcome->code, (string) $outcome->reply);
            }

            if (! $outcome->isAccepted()) {
                throw ProblemException::temporaryFailure('mailServer', 'delivery', 'The mail server could not be reached or answered temporarily ('.mb_substr((string) $outcome->reason, 0, 200).'). The item was kept.');
            }

            DB::transaction(fn () => DB::table('quarantine')->where('id', $item->getKey())->delete());

            $learning = 'notRequested';
            $fuzzy = 'notRequested';
            $learningError = null;
            $fuzzyError = null;

            if ($learnAsHam) {
                $learned = $this->rspamd->learnHam($stored);
                $learning = self::learningStep($learned);
                $learningError = $learned->result === LearnResult::Failed ? $learned->error : null;

                if ($learned->succeeded()) {
                    [$fuzzy, $fuzzyError] = self::fuzzyStep($this->rspamd->fuzzyAdd($stored, self::FLAG_WANTED));
                }
            }

            $report = new ActionReport((int) $item->getKey(), (string) $item->getRawOriginal('qid'), $learnAsHam ? 'release' : 'releaseWithoutLearning', 'done', $learning, $fuzzy, $learningError, $fuzzyError);
            $details = $this->details($item, $bulkRequestId) + ['action' => $report->action, 'steps' => $report->steps()];

            try {
                $this->audit->record(AuditEventType::QuarantineItemRelease, true, $principal, self::target($item), null, null, $details);
            } catch (Throwable $e) {
                Log::error('The audit record of a quarantine release could not be written; the message was delivered and the item removed.', [
                    'eventType' => AuditEventType::QuarantineItemRelease->value,
                    'actor' => $principal->logLabel(),
                    'target' => self::target($item),
                    'details' => $details,
                    'error' => $e->getMessage(),
                ]);

                throw new RuntimeException('The release was carried out, but its audit record could not be written.', 0, $e);
            }

            return $report;
        });
    }

    public function learnAsSpam(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): ActionReport
    {
        return $this->locked($principal, $id, $ifMatch, function (QuarantineItem $item) use ($principal, $bulkRequestId): ActionReport {
            $stored = (string) $item->getRawOriginal('msg');
            $learned = $this->rspamd->learnSpam($stored);

            if (! $learned->succeeded()) {
                throw ProblemException::temporaryFailure('spamFilter', 'learning', 'The spam filter could not learn the message ('.mb_substr((string) $learned->error, 0, 200).'). The item was kept.');
            }

            [$fuzzy, $fuzzyError] = self::fuzzyStep($this->rspamd->fuzzyAdd($stored, self::FLAG_UNWANTED));
            $report = new ActionReport((int) $item->getKey(), (string) $item->getRawOriginal('qid'), 'learnAsSpam', 'notApplicable', self::learningStep($learned), $fuzzy, null, $fuzzyError);

            DB::transaction(function () use ($principal, $item, $report, $bulkRequestId): void {
                DB::table('quarantine')->where('id', $item->getKey())->delete();
                $this->audit->record(AuditEventType::QuarantineItemSpamLearning, true, $principal, self::target($item), null, null, $this->details($item, $bulkRequestId) + ['action' => $report->action, 'steps' => $report->steps()]);
            });

            return $report;
        });
    }

    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        $this->locked($principal, $id, $ifMatch, function (QuarantineItem $item) use ($principal, $bulkRequestId): bool {
            DB::transaction(function () use ($principal, $item, $bulkRequestId): void {
                DB::table('quarantine')->where('id', $item->getKey())->delete();
                $this->audit->record(AuditEventType::QuarantineItemDeletion, true, $principal, self::target($item), null, null, $this->details($item, $bulkRequestId));
            });

            return true;
        }, withMessage: false);
    }

    /**
     * The shared checks and the item lock around an action.
     *
     * @template T
     *
     * @param  callable(QuarantineItem): T  $action
     * @return T
     */
    private function locked(Principal $principal, int $id, ?string $ifMatch, callable $action, bool $withMessage = true): mixed
    {
        $this->policy->authorize($principal, QuarantinePolicy::ACT);
        $item = $this->query->find($principal, $id) ?? throw new NotFoundHttpException('The quarantine item does not exist.');

        if (ETag::preconditionFails($ifMatch, $item->versionTag())) {
            throw new PreconditionFailedHttpException('The quarantine item does not match the given version.');
        }

        $lock = $this->locks->acquire($id);

        if ($lock === null) {
            throw $this->query->exists($principal, $id) ? ProblemException::quarantineItemBusy() : new NotFoundHttpException('The quarantine item does not exist.');
        }

        try {
            // A delivery and two spam filter calls of up to 30 s each, also in bulk.
            TimeLimit::raise(120);
            $current = $this->query->find($principal, $id, $withMessage) ?? throw new NotFoundHttpException('The quarantine item does not exist.');

            return $action($current);
        } finally {
            QuarantineItemLock::release($lock);
        }
    }

    /**
     * `Q_RELEASE_FORMAT`: `attachment`, or the original for `raw`, an empty or a missing value
     * (legacy removes the item without delivering it when none is stored; deviation).
     */
    private function releaseFormat(): string
    {
        try {
            $format = $this->store->run(fn (Redis $redis): mixed => $redis->get('Q_RELEASE_FORMAT'));
        } catch (SharedStoreUnavailable) {
            throw ProblemException::temporaryFailure('sharedStore', null, 'mailcow\'s shared store is unavailable, so the release format cannot be read. Nothing was changed; retry later.');
        }

        return $format === 'attachment' ? 'attachment' : 'original';
    }

    private static function learningStep(LearnOutcome $outcome): string
    {
        return match ($outcome->result) {
            LearnResult::Done => 'done',
            LearnResult::AlreadyLearned => 'alreadyLearned',
            default => 'failed',
        };
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private static function fuzzyStep(LearnOutcome $outcome): array
    {
        return match ($outcome->result) {
            LearnResult::Done, LearnResult::AlreadyLearned => ['done', null],
            LearnResult::NoFuzzyContent => ['skipped', null],
            LearnResult::Failed => ['failed', $outcome->error],
        };
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    public static function target(QuarantineItem $item): array
    {
        return ['type' => 'quarantineItem', 'id' => (string) $item->getKey(), 'name' => (string) $item->getRawOriginal('qid')];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(QuarantineItem $item, ?string $bulkRequestId): array
    {
        return array_filter([
            'queueId' => (string) $item->getRawOriginal('qid'),
            'recipient' => (string) $item->getRawOriginal('rcpt'),
            'sender' => (string) $item->getRawOriginal('sender'),
            'bulkRequestId' => $bulkRequestId,
        ], fn (mixed $v): bool => $v !== null);
    }
}
