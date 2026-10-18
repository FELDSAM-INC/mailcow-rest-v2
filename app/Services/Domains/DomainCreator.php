<?php

namespace App\Services\Domains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\SharedStore\SharedStore;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Dkim\DkimCreationStep;
use App\Services\Dkim\DkimDnsOutcome;
use App\Services\Dkim\DkimKeyService;
use App\Services\DomainTemplates\DomainTemplateAttributes;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Domain creation, legacy mailbox('add', 'domain') (FR-017–FR-029, research D8):
 *
 *  1. resolve the template (the named one, else Default, else the built-in values) and merge the
 *     request over it; check every rule;
 *  2. refuse with `503 temporary-failure` when mailcow's shared store is down;
 *  3. in one transaction: lock the name (domain and alias domain), remove external sender
 *     addresses in the new domain, insert the row and its tags, set `DOMAIN_MAP` (removed again if
 *     anything later fails), write the audit record with the creation report "pending";
 *  4. after the commit, the follow-up steps, each reported and none undoing the creation: rate
 *     limit, DKIM key (with the FeldHost DNS publication), groupware restart;
 *  5. complete the audit record with the report.
 *
 * Like legacy, which returns before the closing update_sogo_static_view(), a creation does not
 * rebuild the groupware directory or flush memcached: the new domain has no mailboxes yet.
 */
class DomainCreator
{
    public function __construct(
        private readonly DomainRules $rules,
        private readonly DomainTemplateAttributes $templates,
        private readonly SharedStore $store,
        private readonly RateLimitStore $rateLimits,
        private readonly DkimKeyService $dkim,
        private readonly DockerApiClient $docker,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the sent members of `DomainCreateRequest`
     */
    public function create(Principal $principal, array $input): DomainCreation
    {
        $violations = new FieldViolations;
        $name = $this->rules->name($input['name'] ?? null, $violations);
        [$templateId, $base] = $this->template($input, $violations);
        $values = $this->merge($base, $input, $name ?? '');
        $values = $this->rules->check($values, $violations);
        $violations->throwIfAny();
        assert($name !== null);

        $this->store->ping();

        $eventId = $this->store($principal, $name, $values, $templateId);
        $report = $this->followUps($principal, $name, $values, (bool) ($input['restartGroupware'] ?? false));

        try {
            $this->audit->completeFollowUps($eventId, 'creationReport', $report->toArray());
        } catch (Throwable $e) {
            Log::error("The creation report of {$name} could not be added to its audit record: ".$e->getMessage());
        }

        return new DomainCreation($name, $report);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{int|null, array<string, mixed>}
     */
    private function template(array $input, FieldViolations $violations): array
    {
        $query = DB::table('templates')->where('type', 'domain');

        if (array_key_exists('templateId', $input)) {
            $row = is_int($input['templateId']) ? (clone $query)->where('id', $input['templateId'])->first() : null;

            if ($row === null) {
                $violations->add('templateId', 'notFound', [], 'The domain template does not exist.');

                return [null, $this->templates->defaults()];
            }
        } else {
            $row = (clone $query)->whereRaw('LOWER(`template`) = ?', ['default'])->orderBy('id')->first();
        }

        return $row === null
            ? [null, $this->templates->defaults()]
            : [(int) $row->id, $this->templates->decode($row->attributes)];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function merge(array $base, array $input, string $name): array
    {
        $values = $base;

        foreach (['maxMailboxes', 'maxAliases', 'defaultMailboxQuota', 'maxMailboxQuota', 'domainQuota'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }

        foreach (['active', 'backupMx', 'relayAllRecipients', 'relayUnknownOnly', 'globalAddressList'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = (bool) $input[$field];
            }
        }

        foreach (['tags', 'rateLimit', 'dkimSelector'] as $field) {
            if (array_key_exists($field, $input)) {
                $values[$field] = $input[$field];
            }
        }

        $allowed = array_map('intval', (array) config('restv2.dkim.allowed_key_sizes'));
        $bits = array_key_exists('dkimKeySize', $input) ? $input['dkimKeySize'] : $values['dkimKeySize'];
        $values['dkimKeySize'] = in_array($bits, $allowed, true) ? $bits : (int) config('restv2.dkim.default_key_size', 2048);

        $description = $input['description'] ?? '';
        $values['description'] = is_string($description) && $description !== '' ? $description : $name;

        return $values;
    }

    /**
     * The atomic part: row, tags and domain map entry, with the pending audit record.
     *
     * @param  array<string, mixed>  $values
     */
    private function store(Principal $principal, string $name, array $values, ?int $templateId): string
    {
        $undo = null;

        try {
            return DB::transaction(function () use ($principal, $name, $values, $templateId, &$undo): string {
                $undo = null;

                if (DB::table('alias_domain')->where('alias_domain', $name)->lockForUpdate()->exists()
                    || DB::table('domain')->where('domain', $name)->lockForUpdate()->exists()) {
                    throw new ProblemException(ProblemType::DomainExists, "{$name} already exists as a domain or alias domain.");
                }

                DB::table('sender_acl')->where('external', 1)->where('send_as', 'like', DomainName::addressPattern($name))->delete();

                try {
                    DB::table('domain')->insert([
                        'domain' => $name,
                        'description' => $values['description'],
                        'aliases' => $values['maxAliases'],
                        'mailboxes' => $values['maxMailboxes'],
                        'defquota' => intdiv($values['defaultMailboxQuota'], 1048576),
                        'maxquota' => intdiv($values['maxMailboxQuota'], 1048576),
                        'quota' => intdiv($values['domainQuota'], 1048576),
                        'backupmx' => (int) $values['backupMx'],
                        'gal' => (int) $values['globalAddressList'],
                        'active' => (int) $values['active'],
                        'relay_unknown_only' => (int) $values['relayUnknownOnly'],
                        'relay_all_recipients' => (int) $values['relayAllRecipients'],
                    ]);
                } catch (QueryException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        throw new ProblemException(ProblemType::DomainExists, "{$name} already exists as a domain or alias domain.");
                    }

                    throw $e;
                }

                foreach ($values['tags'] as $tag) {
                    DB::table('tags_domain')->insert(['domain' => $name, 'tag_name' => $tag]);
                }

                $undo = $this->store->setFields('DOMAIN_MAP', [$name => '1']);

                return $this->audit->record(
                    AuditEventType::DomainCreation,
                    true,
                    $principal,
                    ['type' => 'domain', 'id' => $name, 'name' => DomainName::toUnicode($name)],
                    null,
                    null,
                    ['values' => self::auditValues($values), 'templateId' => $templateId, 'creationReport' => 'pending'],
                )->id;
            }, 3);
        } catch (Throwable $e) {
            $this->undo($undo, $name);

            throw $e;
        }
    }

    private function undo(?Closure $undo, string $name): void
    {
        if ($undo === null) {
            return;
        }

        try {
            $undo();
        } catch (Throwable $e) {
            Log::error("The domain map entry of {$name} could not be removed after the failed creation: ".$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function followUps(Principal $principal, string $name, array $values, bool $restart): DomainCreationReport
    {
        $report = new DomainCreationReport;

        if (is_array($values['rateLimit'])) {
            try {
                $this->rateLimits->set($name, (int) $values['rateLimit']['value'], (string) $values['rateLimit']['frame']);
                $report->rateLimit = 'applied';
            } catch (Throwable $e) {
                Log::error("The rate limit of {$name} was not stored: ".$e->getMessage());
                $report->fail('rateLimit', 'The rate limit could not be stored: mailcow\'s shared store is unavailable.');
            }
        }

        if ($values['dkimSelector'] !== '') {
            $step = $this->dkim->generateForNewName($name, (string) $values['dkimSelector'], (int) $values['dkimKeySize'], new AuditContext($principal, 'domainCreation'));
            $report->dkimKey = $step->outcome;

            if ($step->outcome === DkimCreationStep::FAILED) {
                $report->message('dkimKey', $step->message ?? 'The DKIM key could not be created.');
            }

            if ($step->publication !== null) {
                $report->dkimDnsPublication = $step->publication->outcome->value;

                if ($step->publication->outcome === DkimDnsOutcome::Failed) {
                    $report->message('dkimDnsPublication', 'The DKIM record was not published ('.$step->publication->reasonCode.')'.($step->publication->detail === null ? '.' : ': '.$step->publication->detail));
                }
            }
        }

        if ($restart) {
            if (config('restv2.skip_sogo')) {
                $report->groupwareRestart = GroupwareOutcome::GroupwareDisabled->value;
            } else {
                $result = $this->docker->restart('sogo-mailcow');

                if ($result->isSuccess()) {
                    $report->groupwareRestart = GroupwareOutcome::Done->value;
                } else {
                    $report->fail('groupwareRestart', $result->isUnknown()
                        ? 'The container service did not answer in time; the groupware may or may not have restarted.'
                        : 'The groupware could not be restarted: '.$result->message);
                }
            }
        }

        return $report;
    }

    /**
     * The stored attributes of a creation, in contract names, for the audit record.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditValues(array $values): array
    {
        return array_intersect_key($values, array_flip([
            'description', 'active', 'maxMailboxes', 'maxAliases', 'defaultMailboxQuota', 'maxMailboxQuota',
            'domainQuota', 'backupMx', 'relayAllRecipients', 'relayUnknownOnly', 'globalAddressList', 'tags',
            'rateLimit', 'dkimSelector', 'dkimKeySize',
        ]));
    }
}
