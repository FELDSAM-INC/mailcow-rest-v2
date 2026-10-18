<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Memcached\MemcachedFlusher;
use App\Exceptions\ProblemException;
use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Config\MailcowConfig;
use App\Services\Domains\DomainUsageCalculator;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\SogoDirectory;
use App\Services\Passwords\HashedPassword;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use App\Support\Tags\TagSet;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

/**
 * Mailbox creation, legacy mailbox('add', 'mailbox') with the deviations of the spec (feature 003
 * FR-018–FR-028, research R5–R9):
 *
 *  1. the domain in scope, the address, the named and the Default template; then the values from
 *     the request, the templates and the built-in defaults, the quota included (owner decision
 *     2026-10-18: never the domain's default mailbox quota);
 *  2. the domain administrator's permission flags (`403 attribute-not-permitted`);
 *  3. every business rule, the password policy included (`422`, reported together; a plain
 *     password needs mailcow's shared store, `503` when it is down);
 *  4. under the address lock, in one transaction holding the domain row lock: the address must be
 *     free (`409 address-in-use`), the domain must have room (`409 mailbox-limit-reached`) and quota
 *     left (`422`); then the `mailbox` row with legacy's string attributes, `passwd_update`, the
 *     zeroed usage rows, the own alias, the `user_acl` row, the tags and the audit record with the
 *     creation report "pending";
 *  5. after the commit, the follow-up steps, each reported and none undoing the creation: the rate
 *     limit, the tagged-mail handling and the groupware entry with the cache flush.
 */
class MailboxCreator
{
    /** Request members that are values of the mailbox (the rest are identity, password, template). */
    private const VALUE_MEMBERS = [
        'quota', 'status', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect', 'protocolAccess',
        'userPermissions', 'tags', 'taggedMailHandling', 'quarantineNotification', 'quarantineCategory',
        'tlsEnforceInbound', 'tlsEnforceOutbound', 'rateLimit',
    ];

    public function __construct(
        private readonly MailboxValidator $validator,
        private readonly MailboxDefaults $defaults,
        private readonly MailboxPermissionGuard $guard,
        private readonly MailboxPasswordHasher $hasher,
        private readonly AddressLock $lock,
        private readonly AddressAvailability $availability,
        private readonly DomainUsageCalculator $usage,
        private readonly RateLimitStore $rateLimits,
        private readonly TaggedMailHandlingStore $tagged,
        private readonly SogoDirectory $sogo,
        private readonly MemcachedFlusher $flusher,
        private readonly MailcowConfig $config,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the sent members of `MailboxCreateRequest`
     */
    public function create(Principal $principal, array $input): MailboxCreation
    {
        $violations = new FieldViolations;
        $domain = $this->validator->creationDomain($principal, (string) $input['domain'], $violations);
        $address = MailboxAddress::fromParts((string) $input['localPart'], $domain->domain ?? (string) $input['domain'], $violations);
        [$templateId, $named] = $this->namedTemplate($input, $violations);
        $referencesValid = $violations->isEmpty() && $domain !== null && $address !== null;

        $request = array_intersect_key($input, array_flip(self::VALUE_MEMBERS));
        $resolved = $this->defaults->resolve($request, $named, $this->defaultTemplate());
        $values = $resolved->values;
        $baseline = $resolved->baseline;
        $domainUsage = $domain === null ? null : $this->usage->forDomain($domain->domain);
        $authSource = (string) ($input['authSource'] ?? 'mailcow');

        if (MailboxValues::isExternal($authSource)) {
            if (! array_key_exists('forcePasswordChange', $request)) {
                $values['forcePasswordChange'] = false;
            }

            if ($authSource === 'genericOidc' && ! array_key_exists('forceSecondFactorSetup', $request)) {
                $values['forceSecondFactorSetup'] = false;
            }
        }

        if ($referencesValid) {
            $this->guard->check($principal, $request, $baseline, true);
        }

        $this->validator->authSource($authSource, null, $violations);
        $password = $this->checkPassword($input, $authSource, $violations);
        $this->checkExternalFlags($authSource, $request, $violations);
        $values['tags'] = TagSet::normalise((array) $values['tags'], [], $this->config->taggingLimit(), $violations);

        if ($this->validator->wholeMebibytes($values['quota'], $violations) && $referencesValid) {
            $this->validator->creationQuota((int) $values['quota'], $domain, $domainUsage->quotaAllocated ?? 0, $violations);
        }

        $violations->throwIfAny();
        assert($domain !== null && $address !== null);

        $values['displayName'] = MailboxValidator::displayName(isset($input['displayName']) ? (string) $input['displayName'] : null, $address->localPart);
        $values['authSource'] = $authSource;
        $hashed = $password === null ? null : $this->hasher->hash($password);

        $eventId = $this->lock->run([$address->address], fn (): string => $this->store($principal, $address, $values, $hashed, $templateId));
        $report = $this->followUps($address->address, $values);

        try {
            $this->audit->completeFollowUps($eventId, 'creationReport', $report->toArray());
        } catch (Throwable $e) {
            Log::error("The creation report of {$address->address} could not be added to its audit record: ".$e->getMessage());
        }

        return new MailboxCreation($address->address, $report);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{int|null, array<string, mixed>|null}
     */
    private function namedTemplate(array $input, FieldViolations $violations): array
    {
        if (! array_key_exists('templateId', $input)) {
            return [null, null];
        }

        $row = is_int($input['templateId']) ? DB::table('templates')->where('type', 'mailbox')->where('id', $input['templateId'])->first() : null;

        if ($row === null) {
            $violations->add('templateId', 'notFound', [], 'The mailbox template does not exist.');

            return [null, null];
        }

        return [(int) $row->id, $this->defaults->decodeTemplate($row->attributes)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function defaultTemplate(): ?array
    {
        $row = DB::table('templates')->where('type', 'mailbox')->whereRaw('LOWER(`template`) = ?', ['default'])->orderBy('id')->first();

        return $row === null ? null : $this->defaults->decodeTemplate($row->attributes);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkPassword(array $input, string $authSource, FieldViolations $violations): ?string
    {
        $password = isset($input['password']) ? (string) $input['password'] : null;

        if (MailboxValues::isExternal($authSource)) {
            if ($password !== null) {
                $violations->add('password', 'conflictsWith', ['field' => 'authSource', 'value' => $authSource], 'An externally authenticated mailbox has no password here.');
            }

            return null;
        }

        if ($password === null || $password === '') {
            $violations->add('password', 'required');

            return null;
        }

        $this->validator->passwordPolicy($password, $violations);

        return $password;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function checkExternalFlags(string $authSource, array $request, FieldViolations $violations): void
    {
        if (MailboxValues::isExternal($authSource) && ($request['forcePasswordChange'] ?? false) === true) {
            $violations->add('forcePasswordChange', 'conflictsWith', ['field' => 'authSource', 'value' => $authSource], 'An externally authenticated mailbox cannot be forced to change its password here.');
        }

        if ($authSource === 'genericOidc' && ($request['forceSecondFactorSetup'] ?? false) === true) {
            $violations->add('forceSecondFactorSetup', 'conflictsWith', ['field' => 'authSource', 'value' => $authSource], 'A generic OIDC mailbox cannot be forced to set up a second factor here.');
        }
    }

    /**
     * The atomic part: limits under the domain row lock, every row legacy writes, the pending audit
     * record. Returns the audit record's ID.
     *
     * @param  array<string, mixed>  $values
     */
    private function store(Principal $principal, MailboxAddress $address, array $values, ?HashedPassword $password, ?int $templateId): string
    {
        return DB::transaction(function () use ($principal, $address, $values, $password, $templateId): string {
            $domain = DB::table('domain')->where('domain', $address->domain)->lockForUpdate()->first();

            if (! $domain instanceof stdClass) {
                throw new ValidationProblemException(['domain' => [ApiFormRequest::make('notFound', [], 'The domain does not exist.')]]);
            }

            $holder = $this->availability->holderOf($address->address);

            if ($holder !== null) {
                throw AddressAvailability::inUse($address->address, $holder);
            }

            $usage = DB::table('mailbox')->where('domain', $address->domain)->where('kind', '')->sharedLock()
                ->selectRaw('COUNT(*) AS `count`, COALESCE(SUM(`quota`), 0) AS `allocated`')->first();
            $count = (int) ($usage->count ?? 0);

            if ($count >= (int) $domain->mailboxes) {
                throw new ProblemException(ProblemType::MailboxLimitReached, "The domain {$address->domain} already has {$count} of {$domain->mailboxes} mailboxes.", ['domain' => $address->domain, 'count' => $count, 'limit' => (int) $domain->mailboxes]);
            }

            $violations = new FieldViolations;
            $this->validator->creationQuota((int) $values['quota'], $domain, (int) ($usage->allocated ?? 0), $violations);
            $violations->throwIfAny();

            try {
                $this->insertRows($address, $values, $password);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw AddressAvailability::inUse($address->address, $this->availability->holderOf($address->address) ?? 'mailbox');
                }

                throw $e;
            }

            $details = [
                'values' => self::auditValues($values),
                'templateId' => $templateId,
                'passwordSet' => $password !== null,
                'creationReport' => 'pending',
            ];

            if ($password !== null) {
                $details['preHashed'] = $password->preHashed;
            }

            return $this->audit->record(AuditEventType::MailboxCreation, true, $principal, ['type' => 'mailbox', 'id' => $address->address, 'name' => $values['displayName']], null, null, $details)->id;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertRows(MailboxAddress $address, array $values, ?HashedPassword $password): void
    {
        $attributes = [
            'force_pw_update' => self::flag($values['forcePasswordChange']),
            'force_tfa' => self::flag($values['forceSecondFactorSetup']),
            'tls_enforce_in' => self::flag($values['tlsEnforceInbound']),
            'tls_enforce_out' => self::flag($values['tlsEnforceOutbound']),
            'sogo_access' => self::flag($values['webmailRedirect']),
        ];

        foreach (MailboxValues::PROTOCOLS as $protocol => $key) {
            $attributes[$key] = self::flag($values['protocolAccess'][$protocol]);
        }

        $attributes += [
            'relayhost' => '0',
            'passwd_update' => time(),
            'mailbox_format' => $this->defaults->mailboxFormat(),
            'quarantine_notification' => (string) $values['quarantineNotification'],
            'quarantine_category' => MailboxValues::QUARANTINE_CATEGORIES[$values['quarantineCategory']],
            'attribute_hash' => '',
        ];
        $active = MailboxValues::STATUSES[$values['status']];

        DB::table('mailbox')->insert([
            'username' => $address->address,
            'password' => $password->value ?? '',
            'name' => $values['displayName'],
            'quota' => (int) $values['quota'],
            'local_part' => $address->localPart,
            'domain' => $address->domain,
            'attributes' => json_encode($attributes),
            'authsource' => MailboxValues::AUTH_SOURCES[$values['authSource']],
            'active' => $active,
        ]);
        DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.passwd_update', NOW()) WHERE `username` = ?", [$address->address]);

        foreach ($values['tags'] as $tag) {
            DB::table('tags_mailbox')->insert(['username' => $address->address, 'tag_name' => $tag]);
        }

        foreach (['quota2', 'quota2replica'] as $table) {
            DB::statement("INSERT INTO `{$table}` (`username`, `bytes`, `messages`) VALUES (?, '0', '0') ON DUPLICATE KEY UPDATE `bytes` = '0', `messages` = '0'", [$address->address]);
        }

        DB::table('alias')->insert(['address' => $address->address, 'goto' => $address->address, 'domain' => $address->domain, 'active' => $active]);

        $acl = ['username' => $address->address];

        foreach (UserPermissions::COLUMNS as $permission => $column) {
            $acl[$column] = (int) $values['userPermissions'][$permission];
        }

        DB::table('user_acl')->insert($acl);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function followUps(string $address, array $values): MailboxCreationReport
    {
        $report = new MailboxCreationReport;
        $rateLimit = $values['rateLimit'];

        try {
            if (is_array($rateLimit)) {
                $this->rateLimits->set($address, (int) $rateLimit['value'], (string) $rateLimit['frame']);
                $report->rateLimit = 'applied';
            } else {
                // Legacy ratelimit('edit') removes any earlier value of the address.
                $this->rateLimits->delete($address);
            }
        } catch (Throwable $e) {
            Log::warning("The rate limit of {$address} was not stored: ".$e->getMessage());

            if (is_array($rateLimit)) {
                $report->fail('rateLimit', 'The rate limit could not be stored: mailcow\'s shared store is unavailable.');
            }
        }

        try {
            $this->tagged->set($address, (string) $values['taggedMailHandling']);

            if ($values['taggedMailHandling'] !== 'none') {
                $report->taggedMailHandling = 'applied';
            }
        } catch (Throwable $e) {
            Log::warning("The tagged-mail handling of {$address} was not stored: ".$e->getMessage());

            if ($values['taggedMailHandling'] !== 'none') {
                $report->fail('taggedMailHandling', 'The tagged-mail handling could not be stored: mailcow\'s shared store is unavailable.');
            }
        }

        if (config('restv2.skip_sogo')) {
            $report->groupwareDirectoryUpdate = GroupwareOutcome::GroupwareDisabled->value;
        } elseif ($values['status'] !== 'active') {
            $report->groupwareDirectoryUpdate = 'notRequired';
        } else {
            try {
                $this->sogo->refresh([$address]);
                $this->flusher->flush();
                $report->groupwareDirectoryUpdate = GroupwareOutcome::Done->value;
            } catch (Throwable $e) {
                Log::error("The groupware directory entry of {$address} could not be written: ".$e->getMessage());
                $report->fail('groupwareDirectoryUpdate', 'The groupware directory could not be updated.');
            }
        }

        return $report;
    }

    private static function flag(mixed $value): string
    {
        return $value ? '1' : '0';
    }

    /**
     * The stored values of a creation in contract names, for the audit record (never the password).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function auditValues(array $values): array
    {
        return array_intersect_key($values, array_flip([
            'displayName', 'status', 'authSource', 'quota', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect',
            'protocolAccess', 'userPermissions', 'tags', 'taggedMailHandling', 'quarantineNotification', 'quarantineCategory',
            'tlsEnforceInbound', 'tlsEnforceOutbound', 'rateLimit',
        ]));
    }
}
