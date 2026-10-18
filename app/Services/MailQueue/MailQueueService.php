<?php

namespace App\Services\MailQueue;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiUnreachable;
use App\Clients\DockerApi\DockerCallResult;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\IrreversibleOperationAudit;
use App\Services\Audit\RefusalAuditor;
use App\Services\Monitoring\ContainerRegistry;
use App\Support\Bulk\BulkOutcome;
use App\Support\Clock\Deadline;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Postfix's mail queue through dockerapi's `mailq` tasks in the Postfix container (feature 011
 * research R17, FR-037 to FR-043, legacy functions.mailq.inc.php):
 *
 *  - every operation needs a running Postfix container (`409 mail-service-not-running`, decided by
 *    the container snapshot; when dockerapi answers as for a stopped container, a fresh read decides
 *    between `409` and `502 queue-action-failed`);
 *  - the listing parses `postqueue -j`; a view returns `postcat -q` and is audited before the
 *    content is returned (no record, no content);
 *  - an action on selected messages checks a fresh listing first, sends the present IDs in one
 *    command and writes one record listing every ID with its result (the spec's documented
 *    exception to one record per object); flush and delete-all need no listing.
 *
 * Actions probe the audit log first and write their records with retries until DOCKER_TIMEOUT. A
 * timeout is `504 outcome-unknown` with `step` = the action; an unreachable dockerapi before the
 * command was sent changed nothing (`503`).
 */
class MailQueueService
{
    public const TASKS = ['deliver' => 'deliver', 'hold' => 'hold', 'release' => 'unhold', 'delete' => 'delete'];

    private const EVENTS = [
        'deliver' => AuditEventType::MailQueueDelivery,
        'hold' => AuditEventType::MailQueueHold,
        'release' => AuditEventType::MailQueueRelease,
        'delete' => AuditEventType::MailQueueDeletion,
        'flush' => AuditEventType::MailQueueFlush,
        'purge' => AuditEventType::MailQueuePurge,
    ];

    public function __construct(
        private readonly DockerApiClient $docker,
        private readonly ContainerRegistry $containers,
        private readonly PostqueueJsonParser $parser,
        private readonly IrreversibleOperationAudit $audit,
        private readonly AuditLogger $logger,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(MailQueueQuery $query): array
    {
        $this->requireRunning();
        $listing = $this->listing();
        $search = $query->search === null ? null : mb_strtolower($query->search);
        $from = $query->from?->format('Y-m-d\TH:i:s\Z');
        $to = $query->to?->format('Y-m-d\TH:i:s\Z');

        $messages = array_values(array_filter($listing->messages, function (array $m) use ($query, $search, $from, $to): bool {
            $addresses = array_column($m['recipients'], 'address');

            return ($query->queues === [] || in_array($m['queue'], $query->queues, true))
                && ($from === null || $m['arrivalTime'] >= $from)
                && ($to === null || $m['arrivalTime'] <= $to)
                && ($query->senders === [] || self::anyContains([$m['sender']], $query->senders))
                && ($query->recipients === [] || self::anyContains($addresses, $query->recipients))
                && ($search === null || self::anyContains([$m['id'], $m['sender'], ...$addresses, ...array_filter(array_column($m['recipients'], 'deferralReason'))], [$search]));
        }));

        $key = fn (array $m): string|int => match ($query->sort) {
            'size' => $m['sizeBytes'],
            'sender' => mb_strtolower($m['sender']),
            'queue' => $m['queue'],
            'id' => $m['id'],
            default => $m['arrivalTime'],
        };
        usort($messages, function (array $a, array $b) use ($key, $query): int {
            $order = ($key($a) <=> $key($b)) ?: strcmp($a['id'], $b['id']);

            return $query->order === 'desc' ? -$order : $order;
        });

        return [
            'data' => array_slice($messages, $query->offset, $query->limit),
            'meta' => [
                'total' => count($messages),
                'limit' => $query->limit,
                'offset' => $query->offset,
                'truncated' => $listing->truncated,
                'queueCounts' => $listing->counts,
            ],
        ];
    }

    /**
     * The content of a queued message (`postcat -q`); the view is recorded before it is returned.
     *
     * @return array{id: string, content: string}
     */
    public function content(Principal $actor, string $id): array
    {
        $this->requireRunning();
        $result = $this->docker->execText($this->postfix(), ['cmd' => 'mailq', 'task' => 'cat', 'items' => [$id]]);
        $output = $this->text($result);

        if (! str_starts_with(ltrim($output), '*** ENVELOPE RECORDS')) {
            if (str_contains($output, 'No such file or directory')) {
                throw new NotFoundHttpException('The message is not in the queue.');
            }

            throw self::queueFailure(mb_substr(trim((string) strtok($output, "\n")), 0, 500) ?: 'postcat answered unexpectedly.');
        }

        try {
            $this->logger->record(AuditEventType::MailQueueMessageView, true, $actor, ['type' => 'mailQueueMessage', 'id' => $id, 'name' => $id], null, null, []);
        } catch (Throwable) {
            throw new ProblemException(ProblemType::TemporaryFailure, 'The view could not be audited, so the content is not shown. Retry later.', ['dependency' => 'database'], ['Retry-After' => '5']);
        }

        return ['id' => $id, 'content' => $output];
    }

    /**
     * Applies deliver, hold, release or delete to the distinct IDs (request order).
     *
     * @param  'deliver'|'hold'|'release'|'delete'  $action
     * @param  list<string>  $ids
     */
    public function apply(Principal $actor, string $action, array $ids): MailQueueBulkOutcome
    {
        $deadline = $this->audit->deadline();
        $this->requireRunning();
        $this->audit->probe();

        $ids = array_values(array_unique($ids));
        $listing = $this->listing();
        $present = array_values(array_filter($ids, fn (string $id): bool => $listing->contains($id)));

        if ($present !== []) {
            $result = $this->docker->exec($this->postfix(), ['cmd' => 'mailq', 'task' => self::TASKS[$action], 'items' => $present]);

            if (! $result->isSuccess()) {
                $this->fail($actor, $action, $result, $deadline, ['results' => array_map(fn (string $id): array => ['id' => $id, 'outcome' => in_array($id, $present, true) ? BulkOutcome::Failed->value : BulkOutcome::NotFound->value], $ids)]);
            }
        }

        $results = array_map(fn (string $id): array => ['id' => $id, 'outcome' => in_array($id, $present, true) ? BulkOutcome::Done->value : BulkOutcome::NotFound->value], $ids);
        $recorded = $this->audit->recordWithRetry(self::EVENTS[$action], true, $actor, self::target(), null, ['results' => $results], $deadline);

        $summary = ['total' => count($results), 'done' => count($present), 'notFound' => count($results) - count($present), 'refused' => 0, 'outdated' => 0, 'failed' => 0];

        return new MailQueueBulkOutcome(['bulkRequestId' => RequestContext::requestId(app('request')), 'results' => $results, 'summary' => $summary], $recorded);
    }

    /**
     * Flush (`postqueue -f`) or delete every queued message (`postsuper -d ALL`).
     *
     * @param  'flush'|'purge'  $action
     * @return array{action: string, completedAt: string, auditRecorded: bool}
     */
    public function whole(Principal $actor, string $action): array
    {
        $deadline = $this->audit->deadline();
        $this->requireRunning();
        $this->audit->probe();

        $result = $this->docker->exec($this->postfix(), ['cmd' => 'mailq', 'task' => $action === 'flush' ? 'flush' : 'super_delete']);

        if (! $result->isSuccess()) {
            $this->fail($actor, $action, $result, $deadline, []);
        }

        $recorded = $this->audit->recordWithRetry(self::EVENTS[$action], true, $actor, self::target(), null, [], $deadline);

        return ['action' => $action, 'completedAt' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), 'auditRecorded' => $recorded];
    }

    private function listing(): MailQueueListing
    {
        return $this->parser->parse($this->text($this->docker->execText($this->postfix(), ['cmd' => 'mailq', 'task' => 'list'])), (int) config('restv2.monitoring.queue_listing_max', 10000));
    }

    /**
     * The text of a read (listing or view): an unreachable or slow dockerapi is a temporary
     * failure; dockerapi's `null` or `danger` answer is decided by a fresh read.
     */
    private function text(DockerCallResult $result): string
    {
        if ($result->isSuccess()) {
            return is_string($result->data) ? $result->data : '';
        }

        if ($result->isUnreachable() || $result->isUnknown()) {
            $this->requireRunning(fresh: true);

            throw new DockerApiUnreachable('The mail queue could not be read: '.$result->message);
        }

        $this->requireRunning(fresh: true);

        throw self::queueFailure((string) $result->message);
    }

    /**
     * Records the failure of a sent command and throws its problem; failures before the command
     * reached Postfix are refusals (recorded by the refusal auditing).
     *
     * @param  array<string, mixed>  $details
     */
    private function fail(Principal $actor, string $action, DockerCallResult $result, Deadline $deadline, array $details): never
    {
        if ($result->isUnreachable()) {
            throw new ProblemException(ProblemType::TemporaryFailure, "mailcow's container service is unavailable. Nothing was changed; retry later.", ['dependency' => 'containerService'], ['Retry-After' => '5']);
        }

        if (! $result->isUnknown()) {
            // dockerapi answers for a container that stopped as for a failed command.
            $this->requireRunning(fresh: true);
        }

        $unknown = $result->isUnknown();
        $details += $unknown ? ['step' => $action] : ['serviceMessage' => (string) $result->message];
        $recorded = $this->audit->recordWithRetry(self::EVENTS[$action], false, $actor, self::target(), $unknown ? 'outcomeUnknown' : 'serviceFailure', $details, $deadline);
        RefusalAuditor::markRecorded(app('request'));
        $headers = $recorded ? [] : ['Audit-Recorded' => 'false'];

        if ($unknown) {
            throw new ProblemException(ProblemType::OutcomeUnknown, "mailcow's container service did not answer the {$action} in time; list the queue again.", ['step' => $action], $headers);
        }

        throw new ProblemException(ProblemType::QueueActionFailed, null, ['serviceMessage' => (string) $result->message], $headers);
    }

    private function requireRunning(bool $fresh = false): void
    {
        $service = $this->postfix();

        if ($fresh) {
            $this->containers->forget($service);
        }

        if (! ($this->containers->find($service, $fresh)->running ?? false)) {
            throw new ProblemException(ProblemType::MailServiceNotRunning, "The Postfix container {$service} is not running. Nothing was changed.", ['container' => $service]);
        }
    }

    private static function queueFailure(string $message): ProblemException
    {
        return new ProblemException(ProblemType::QueueActionFailed, null, ['serviceMessage' => $message === '' ? 'Postfix reported a failure.' : $message]);
    }

    /**
     * @param  list<string>  $haystacks
     * @param  list<string>  $needles
     */
    private static function anyContains(array $haystacks, array $needles): bool
    {
        foreach ($haystacks as $haystack) {
            foreach ($needles as $needle) {
                if (str_contains(mb_strtolower((string) $haystack), mb_strtolower($needle))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function postfix(): string
    {
        return (string) config('restv2.monitoring.postfix_container', 'postfix-mailcow');
    }

    /**
     * @return array{type: string, id: null, name: null}
     */
    private static function target(): array
    {
        return ['type' => 'mailQueue', 'id' => null, 'name' => null];
    }
}
