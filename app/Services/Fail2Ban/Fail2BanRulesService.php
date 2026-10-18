<?php

namespace App\Services\Fail2Ban;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\Fail2Ban\Fail2BanStore;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\IrreversibleOperationAudit;
use App\Services\Audit\RefusalAuditor;
use App\Services\Monitoring\ContainerRegistry;
use App\Support\Clock\Deadline;
use App\Support\Clock\Sleeper;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use App\Support\Regex\PythonRegexValidator;
use Redis;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The ban rules (feature 011 research R20, R21, FR-049 to FR-051, legacy fail2ban('edit')
 * `edit-regex` and `reset-regex`): read in numeric key order; replaced as a whole after every rule
 * compiled in Python `re` with a capture group, stored as legacy stores them
 * (`{"1": rule, …}`, unescaped slashes); reset by removing them, restarting the ban service and
 * waiting up to 10 seconds for its defaults. The reset is irreversible: it probes the audit log
 * first and records its outcome with retries.
 */
class Fail2BanRulesService
{
    private const POLLS = 10;

    public function __construct(
        private readonly Fail2BanStore $store,
        private readonly ContainerRegistry $containers,
        private readonly PythonRegexValidator $validator,
        private readonly AuditLogger $logger,
        private readonly IrreversibleOperationAudit $audit,
        private readonly DockerApiClient $docker,
        private readonly Sleeper $sleeper,
    ) {}

    /**
     * @return array{data: array<string, mixed>, etag: string}
     */
    public function read(): array
    {
        return $this->present($this->store->rules());
    }

    /**
     * @param  list<string>  $patterns
     * @return array{data: array<string, mixed>, etag: string, auditRecorded: bool}
     */
    public function replace(Principal $actor, array $patterns, ?string $ifMatch): array
    {
        $this->validate($patterns);

        [$old, $committed] = $this->store->transaction([Fail2BanStore::RULES], function (Redis $redis) use ($patterns, $ifMatch): array {
            $old = $this->store->rules($redis);
            $this->checkVersion($old ?? [], $ifMatch);
            $numbered = [];

            foreach ($patterns as $index => $pattern) {
                $numbered[(string) ($index + 1)] = $pattern;
            }

            return [fn (Redis $multi) => $multi->set(Fail2BanStore::RULES, (string) json_encode((object) $numbered, JSON_UNESCAPED_SLASHES)), $old];
        }, fn (?array $old) => $this->logger->record(AuditEventType::Fail2banRulesReplacement, true, $actor, self::target(), null, null, ['oldRules' => $old ?? [], 'newRules' => $patterns]));

        return $this->present($patterns) + ['auditRecorded' => $committed];
    }

    /**
     * @return array{data: array<string, mixed>, etag: string, auditRecorded: bool}
     */
    public function reset(Principal $actor, ?string $ifMatch): array
    {
        $deadline = $this->audit->deadline();
        $old = $this->store->rules();
        $this->checkVersion($old ?? [], $ifMatch);
        $this->audit->probe();

        $service = (string) config('restv2.monitoring.ban_service_container', 'netfilter-mailcow');

        if ($this->containers->find($service, fresh: true) === null) {
            throw new ProblemException(ProblemType::ContainerActionFailed, null, ['container' => $service, 'serviceMessage' => "The ban service container {$service} does not exist."]);
        }

        $this->store->deleteRules();
        $result = $this->docker->restart($service);
        $this->containers->forget($service);

        if (! $result->isSuccess()) {
            $unknown = $result->isUnknown() || $result->isUnreachable();
            $this->fail($actor, $old, $unknown ? 'outcomeUnknown' : 'serviceFailure', $deadline, $unknown
                ? new ProblemException(ProblemType::OutcomeUnknown, "mailcow's container service did not answer the restart of the ban service in time; read the rules again.", ['step' => 'rulesReset'])
                : new ProblemException(ProblemType::ContainerActionFailed, null, ['container' => $service, 'serviceMessage' => (string) $result->message]), $unknown ? ['step' => 'rulesReset'] : ['serviceMessage' => (string) $result->message]);
        }

        $rules = $this->store->rules();

        for ($poll = 0; $rules === null && $poll < self::POLLS; $poll++) {
            $this->sleeper->sleepSeconds(1.0);
            $rules = $this->store->rules();
        }

        if ($rules === null) {
            $this->fail($actor, $old, 'rulesNotRestored', $deadline, new ProblemException(ProblemType::BanRulesNotRestored), []);
        }

        $recorded = $this->audit->recordWithRetry(AuditEventType::Fail2banRulesReset, true, $actor, self::target(), null, ['oldRules' => $old ?? [], 'newRules' => $rules], $deadline);

        return $this->present($rules) + ['auditRecorded' => $recorded];
    }

    /**
     * @param  list<string>|null  $rules
     * @return array{data: array<string, mixed>, etag: string}
     */
    public function present(?array $rules): array
    {
        $etag = Fail2BanVersionTag::rules($rules ?? []);

        return ['data' => [
            'rules' => array_map(fn (string $pattern, int $index): array => ['number' => $index + 1, 'pattern' => $pattern], $rules ?? [], array_keys($rules ?? [])),
            'stored' => $rules !== null,
            'banServiceRunning' => $this->containers->banServiceRunning(),
            'etag' => $etag,
        ], 'etag' => $etag];
    }

    /**
     * @param  list<string>  $patterns
     */
    private function validate(array $patterns): void
    {
        $violations = new FieldViolations;

        foreach ($this->validator->validate($patterns) as $index => $result) {
            if (! $result['ok']) {
                $violations->add("rules.{$index}", 'invalidRegex', ['error' => (string) $result['error']], 'The rule does not compile in Python re: '.$result['error']);
            } elseif ($result['groups'] < 1) {
                $violations->add("rules.{$index}", 'missingCaptureGroup', [], 'The rule needs a capture group for the client address.');
            }
        }

        $violations->throwIfAny();
    }

    /**
     * @param  list<string>  $current
     */
    private function checkVersion(array $current, ?string $ifMatch): void
    {
        if (ETag::preconditionFails($ifMatch, Fail2BanVersionTag::rules($current))) {
            throw new PreconditionFailedHttpException('The ban rules were changed since they were read.');
        }
    }

    /**
     * Records a reset that removed the rules but did not end as expected, and throws its problem.
     *
     * @param  list<string>|null  $old
     * @param  array<string, mixed>  $details
     */
    private function fail(Principal $actor, ?array $old, string $reason, Deadline $deadline, ProblemException $problem, array $details): never
    {
        $recorded = $this->audit->recordWithRetry(AuditEventType::Fail2banRulesReset, false, $actor, self::target(), $reason, ['oldRules' => $old ?? [], 'newRules' => null] + $details, $deadline);
        RefusalAuditor::markRecorded(app('request'));

        throw new ProblemException($problem->type, $problem->detail, $problem->extensions, $recorded ? [] : ['Audit-Recorded' => 'false']);
    }

    /**
     * @return array{type: string, id: null, name: null}
     */
    private static function target(): array
    {
        return ['type' => 'fail2banRules', 'id' => null, 'name' => null];
    }
}
