<?php

namespace App\Services\AliasDomains;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\SharedStore\SharedStore;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Dkim\DkimCreationStep;
use App\Services\Dkim\DkimDnsOutcome;
use App\Services\Dkim\DkimKeyService;
use App\Services\Domains\DomainRules;
use App\Services\Groupware\SogoDirectory;
use App\Services\Identity\IdentityService;
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
 * Alias domain creation, legacy mailbox('add', 'alias_domain') for one name (feature 004
 * FR-040–FR-043, FR-062, research R10), following 002's domain creation flow:
 *
 *  1. check the name and the target, collecting every violation (`422`); a domain administrator
 *     needs `ratelimit` for an initial rate limit (`403 attribute-not-permitted`; `aliasDomains`
 *     was checked by the request before the body);
 *  2. refuse with `503 temporary-failure` when mailcow's shared store is down;
 *  3. in one transaction: lock the target row and the name's keys in `alias_domain` and `domain`
 *     (`409 alias-domain-already-exists`), remove the external sender addresses that lie in the new
 *     alias domain (legacy meant to and used an undefined variable), insert the row, set
 *     `DOMAIN_MAP` (removed again if anything later fails), write the audit record with the
 *     creation report "pending";
 *  4. after the commit, the follow-up steps, each reported and none undoing the creation: rate
 *     limit, DKIM key (with the FeldHost DNS publication), groupware directory rebuild (legacy's
 *     closing full update_sogo_static_view());
 *  5. complete the audit record with the report.
 */
class AliasDomainCreator
{
    public function __construct(
        private readonly DomainRules $domainRules,
        private readonly AliasDomainRules $rules,
        private readonly IdentityService $identity,
        private readonly SharedStore $store,
        private readonly RateLimitStore $rateLimits,
        private readonly DkimKeyService $dkim,
        private readonly SogoDirectory $sogo,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the sent members of `AliasDomainCreateRequest`
     */
    public function create(Principal $principal, array $input): AliasDomainCreation
    {
        $violations = new FieldViolations;
        $name = $this->domainRules->name($input['name'] ?? null, $violations);
        $target = $this->rules->target($principal, $input['targetDomain'] ?? null, $violations);

        if ($name !== null && $target !== null && $name === $target) {
            $violations->add('name', 'conflictsWith', ['field' => 'targetDomain', 'value' => $target], 'The alias domain cannot be its own target.');
        }

        $violations->throwIfAny();
        assert($name !== null && $target !== null);

        $rateLimit = is_array($input['rateLimit'] ?? null) ? $input['rateLimit'] : null;

        if ($rateLimit !== null && $principal->role !== Role::Admin && ! ($this->identity->permissions($principal)[PermissionFlag::Ratelimit->value] ?? false)) {
            throw new ProblemException(ProblemType::AttributeNotPermitted, 'An initial rate limit needs the ratelimit permission.', [
                'attributes' => ['rateLimit'],
                'requiredFlags' => ['rateLimit' => PermissionFlag::Ratelimit->value],
            ]);
        }

        $values = [
            'targetDomain' => $target,
            'active' => (bool) ($input['active'] ?? true),
            'rateLimit' => $rateLimit === null ? null : ['value' => (int) $rateLimit['value'], 'frame' => (string) $rateLimit['frame']],
            'dkimSelector' => array_key_exists('dkimSelector', $input) ? (string) $input['dkimSelector'] : (string) config('restv2.dkim.default_selector'),
            'dkimKeySize' => (int) ($input['dkimKeySize'] ?? config('restv2.dkim.default_key_size', 2048)),
        ];

        $this->store->ping();

        $eventId = $this->store($principal, $name, $values);
        $report = $this->followUps($principal, $name, $values);

        try {
            $this->audit->completeFollowUps($eventId, 'creationReport', $report->toArray());
        } catch (Throwable $e) {
            Log::error("The creation report of the alias domain {$name} could not be added to its audit record: ".$e->getMessage());
        }

        return new AliasDomainCreation($name, $report);
    }

    /**
     * The atomic part: the row and its domain map entry, with the pending audit record.
     *
     * @param  array<string, mixed>  $values
     */
    private function store(Principal $principal, string $name, array $values): string
    {
        $undo = null;

        try {
            return DB::transaction(function () use ($principal, $name, $values, &$undo): string {
                $undo = null;
                DB::table('domain')->where('domain', $values['targetDomain'])->lockForUpdate()->first(['domain']);

                if (DB::table('alias_domain')->where('alias_domain', $name)->lockForUpdate()->exists()) {
                    throw self::exists($name, 'aliasDomain');
                }

                if (DB::table('domain')->where('domain', $name)->lockForUpdate()->exists()) {
                    throw self::exists($name, 'domain');
                }

                $removed = DB::table('sender_acl')->where('external', 1)->where('send_as', 'like', DomainName::addressPattern($name))->delete();

                try {
                    DB::table('alias_domain')->insert(['alias_domain' => $name, 'target_domain' => $values['targetDomain'], 'active' => (int) $values['active']]);
                } catch (QueryException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        throw self::exists($name, 'aliasDomain');
                    }

                    throw $e;
                }

                $undo = $this->store->setFields('DOMAIN_MAP', [$name => '1']);

                return $this->audit->record(
                    AuditEventType::AliasDomainCreation,
                    true,
                    $principal,
                    ['type' => 'aliasDomain', 'id' => $name, 'name' => DomainName::toUnicode($name)],
                    null,
                    null,
                    ['values' => $values + ['removedExternalSenderAddresses' => $removed], 'creationReport' => 'pending'],
                )->id;
            }, 3);
        } catch (Throwable $e) {
            $this->undo($undo, $name);

            throw $e;
        }
    }

    private static function exists(string $name, string $kind): ProblemException
    {
        return new ProblemException(ProblemType::AliasDomainAlreadyExists, "{$name} already exists as ".($kind === 'domain' ? 'a domain.' : 'an alias domain.'), ['name' => $name, 'existingKind' => $kind]);
    }

    private function undo(?Closure $undo, string $name): void
    {
        if ($undo === null) {
            return;
        }

        try {
            $undo();
        } catch (Throwable $e) {
            Log::error("The domain map entry of the alias domain {$name} could not be removed after the failed creation: ".$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function followUps(Principal $principal, string $name, array $values): AliasDomainCreationReport
    {
        $report = new AliasDomainCreationReport;

        if (is_array($values['rateLimit'])) {
            try {
                $this->rateLimits->set($name, $values['rateLimit']['value'], $values['rateLimit']['frame']);
                $report->rateLimit = 'applied';
            } catch (Throwable $e) {
                Log::error("The rate limit of the alias domain {$name} was not stored: ".$e->getMessage());
                $report->fail('rateLimit', 'The rate limit could not be stored: mailcow\'s shared store is unavailable.');
            }
        }

        if ($values['dkimSelector'] !== '') {
            $step = $this->dkim->generateForNewName($name, $values['dkimSelector'], $values['dkimKeySize'], new AuditContext($principal, 'aliasDomainCreation'));
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

        $outcome = $this->sogo->rebuild();
        $report->groupwareDirectoryUpdate = $outcome->value;

        if ($outcome->value === 'failed') {
            $report->message('groupwareDirectoryUpdate', 'The groupware directory could not be rebuilt.');
        }

        return $report;
    }
}
