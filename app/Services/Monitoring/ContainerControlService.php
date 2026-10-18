<?php

namespace App\Services\Monitoring;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\ContainerSnapshot;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiUnreachable;
use App\Clients\DockerApi\DockerCallResult;
use App\Exceptions\ProblemException;
use App\Services\Audit\IrreversibleOperationAudit;
use App\Services\Audit\RefusalAuditor;
use App\Support\Clock\Deadline;
use App\Support\Clock\Sleeper;
use App\Support\Http\ProblemType;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Starting, stopping and restarting containers (feature 011 research R4, FR-013 to FR-016, legacy
 * inc/ajax/container_ctrl.php):
 *
 *  1. The container is resolved from a fresh container list (unknown, foreign or hidden: `404`).
 *  2. Stopping a protected container, or stopping or restarting this API's own one, is refused with
 *     `409 container-protected` (recorded by the refusal auditing, with the state).
 *  3. The audit log is probed (`503` when it cannot be written; nothing is done).
 *  4. start and stop decide by the running flag (already in state: no call) and retry up to four
 *     attempts 1.5 seconds apart, each followed by a fresh read; restart is one call whatever the
 *     state. dockerapi's answer alone never decides `done`: the fresh read does.
 *  5. The whole action is bounded by DOCKER_TIMEOUT: a timeout, or a failure to reach dockerapi
 *     after an attempt was sent, is `504 outcome-unknown` (step = the action); an unreachable
 *     dockerapi before anything was sent is `503 temporary-failure`; dockerapi's failure, or a
 *     container that never reaches the state, is `502 container-action-failed`.
 *  6. The outcome, and every failure after an attempt was sent, is recorded with retries until the
 *     deadline; the shared container states and the container's resource usage are invalidated.
 */
class ContainerControlService
{
    private const ATTEMPTS = 4;

    private const RETRY_DELAY = 1.5;

    private const EVENTS = [
        'start' => AuditEventType::ContainerStart,
        'stop' => AuditEventType::ContainerStop,
        'restart' => AuditEventType::ContainerRestart,
    ];

    public function __construct(
        private readonly DockerApiClient $docker,
        private readonly ContainerRegistry $registry,
        private readonly IrreversibleOperationAudit $audit,
        private readonly Sleeper $sleeper,
    ) {}

    /**
     * @param  'start'|'stop'|'restart'  $action
     *
     * @throws ProblemException|NotFoundHttpException|DockerApiUnreachable
     */
    public function perform(Principal $actor, string $action, string $service): ContainerActionOutcome
    {
        $deadline = $this->audit->deadline();
        $before = $this->registry->find($service, fresh: true) ?? throw new NotFoundHttpException('The container does not exist.');

        if (($action === 'stop' && $this->registry->isProtected($before)) || ($action !== 'start' && $this->registry->isOwnContainer($before))) {
            throw new ProblemException(
                ProblemType::ContainerProtected,
                $action === 'stop' ? "The container {$service} is protected and cannot be stopped." : "This API's own container {$service} cannot be restarted through this API.",
                ['container' => $service],
                [],
                ['stateBefore' => $before->status, 'stateAfter' => $before->status],
            );
        }

        $this->audit->probe();

        if (($action === 'start' && $before->running) || ($action === 'stop' && ! $before->running)) {
            return $this->succeed($actor, $action, $before, $before, 'alreadyInState', $deadline);
        }

        $after = $action === 'restart'
            ? $this->restart($actor, $before, $deadline)
            : $this->untilState($actor, $action, $before, $action === 'start', $deadline);

        return $this->succeed($actor, $action, $before, $after, 'done', $deadline);
    }

    private function restart(Principal $actor, ContainerSnapshot $before, Deadline $deadline): ContainerSnapshot
    {
        $result = $this->docker->restart($before->service);
        $this->registry->forget($before->service);

        if (! $result->isSuccess()) {
            $this->fail($actor, 'restart', $before, $before, $result, true, $deadline);
        }

        return $this->reread($before) ?? $before;
    }

    /**
     * @param  'start'|'stop'  $action
     */
    private function untilState(Principal $actor, string $action, ContainerSnapshot $before, bool $running, Deadline $deadline): ContainerSnapshot
    {
        $message = null;
        $current = $before;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $result = $action === 'start' ? $this->docker->start($before->service) : $this->docker->stop($before->service);
            $this->registry->forget($before->service);

            if ($result->isUnknown() || $result->isUnreachable()) {
                $this->fail($actor, $action, $before, $current, $result, $attempt === 1, $deadline);
            }

            if (! $result->isSuccess()) {
                $message = $result->message;
            }

            try {
                $current = $this->registry->find($before->service, fresh: true) ?? $current;
            } catch (DockerApiUnreachable) {
                $this->fail($actor, $action, $before, $current, DockerCallResult::unknown('The container could not be read after the '.$action.'.'), false, $deadline);
            }

            if ($current->running === $running) {
                return $current;
            }

            if ($attempt < self::ATTEMPTS) {
                if ($deadline->remaining() < self::RETRY_DELAY) {
                    $this->fail($actor, $action, $before, $current, DockerCallResult::unknown('The container service timeout passed.'), false, $deadline);
                }

                $this->sleeper->sleepSeconds(self::RETRY_DELAY);
            }
        }

        $this->fail($actor, $action, $before, $current, DockerCallResult::failed($message ?? ($running ? 'The container did not start.' : 'The container did not stop.')), false, $deadline);
    }

    /**
     * Records the failure of an action that was (or may have been) sent and throws its problem. An
     * unreachable dockerapi before the first attempt changed nothing: `503`, recorded by the refusal
     * auditing.
     *
     * @throws ProblemException always
     */
    private function fail(Principal $actor, string $action, ContainerSnapshot $before, ContainerSnapshot $after, DockerCallResult $result, bool $firstAttempt, Deadline $deadline): never
    {
        $service = $before->service;

        if ($result->isUnreachable() && $firstAttempt) {
            throw new ProblemException(ProblemType::TemporaryFailure, "mailcow's container service is unavailable. Nothing was changed; retry later.", ['dependency' => 'containerService'], ['Retry-After' => '5']);
        }

        $unknown = $result->isUnknown() || $result->isUnreachable();
        $details = ['stateBefore' => $before->status, 'stateAfter' => $after->status] + ($unknown ? ['step' => $action] : ['serviceMessage' => (string) $result->message]);
        $recorded = $this->audit->recordWithRetry(self::EVENTS[$action], false, $actor, self::target($service), $unknown ? 'outcomeUnknown' : 'serviceFailure', $details, $deadline);
        RefusalAuditor::markRecorded(app('request'));
        $headers = $recorded ? [] : ['Audit-Recorded' => 'false'];

        if ($unknown) {
            throw new ProblemException(ProblemType::OutcomeUnknown, "mailcow's container service did not answer the {$action} of {$service} in time; read the container again.", ['step' => $action], $headers);
        }

        throw new ProblemException(ProblemType::ContainerActionFailed, "The {$action} of {$service} failed.", ['container' => $service, 'serviceMessage' => (string) $result->message], $headers);
    }

    private function succeed(Principal $actor, string $action, ContainerSnapshot $before, ContainerSnapshot $after, string $outcome, Deadline $deadline): ContainerActionOutcome
    {
        $recorded = $this->audit->recordWithRetry(self::EVENTS[$action], true, $actor, self::target($before->service), null, [
            'outcome' => $outcome,
            'stateBefore' => $before->status,
            'stateAfter' => $after->status,
        ], $deadline);

        return new ContainerActionOutcome($action, $outcome, $after, CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'), $recorded);
    }

    private function reread(ContainerSnapshot $before): ?ContainerSnapshot
    {
        try {
            return $this->registry->find($before->service, fresh: true);
        } catch (DockerApiUnreachable) {
            return null;
        }
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    private static function target(string $service): array
    {
        return ['type' => 'container', 'id' => $service, 'name' => $service];
    }
}
