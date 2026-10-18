<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Config\MailcowConfig;
use App\Services\Domains\DomainPresenter;
use App\Services\Identity\IdentityService;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use App\Support\Tags\TagSet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The contract's `Mailbox` representation (feature 003 FR-008–FR-011, FR-016, research R2, R10),
 * built for a whole page of `mailbox` rows with one query per kind of related data (domains, quota
 * allocated, usage, permissions, tags, last logins, temporary aliases, push settings) and three
 * shared-store reads (rate limits, both tagged-mail hashes): never one lookup per mailbox.
 *
 * Usage comes from `quota2`, or `quota2replica` on a node that is not the master. Last logins are
 * `null` when the operator turned them off. A domain administrator does not see the overlapping
 * user permissions whose flag it lacks. A shared-store failure raises SharedStoreUnavailable
 * (`503`): the representation would be incomplete without it.
 */
class MailboxReader
{
    public const TAGGED_SUBJECT = 'RCPT_WANTS_SUBJECT_TAG';

    public const TAGGED_SUBFOLDER = 'RCPT_WANTS_SUBFOLDER_TAG';

    private const MIB = 1048576;

    public function __construct(
        private readonly MailboxListQuery $listQuery,
        private readonly MailboxVersion $version,
        private readonly MailboxDefaults $defaults,
        private readonly SharedStore $store,
        private readonly MailcowConfig $config,
        private readonly IdentityService $identity,
    ) {}

    /**
     * @param  array{domains: list<string>, statuses: list<string>, tags: list<string>, authSources: list<string>}  $filters
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $page = $this->listQuery->page($principal, $list, $filters);

        return ['data' => $this->present($page['rows'], $principal), 'total' => $page['total']];
    }

    /**
     * The representation of one mailbox (not a resource); null when it does not exist. The caller
     * resolved the scope.
     *
     * @return array<string, mixed>|null
     */
    public function one(Principal $principal, string $address, bool $tolerateSharedStore = false): ?array
    {
        $row = DB::table('mailbox')->where('username', $address)->where('kind', '')->first();

        return $row === null ? null : $this->present([$row], $principal, $tolerateSharedStore)[0];
    }

    /**
     * @param  list<object>  $rows  `mailbox` rows, in the order to return
     * @param  bool  $tolerateSharedStore  for the answer of a change already committed (a creation
     *                                     whose follow-up steps reported the outage): the shared-store
     *                                     values are then shown as not set instead of failing
     * @return list<array<string, mixed>>
     */
    public function present(array $rows, Principal $principal, bool $tolerateSharedStore = false): array
    {
        if ($rows === []) {
            return [];
        }

        $addresses = array_map(fn (object $row): string => (string) $row->username, $rows);
        $domainNames = array_values(array_unique(array_map(fn (object $row): string => (string) $row->domain, $rows)));

        $domains = DB::table('domain')->whereIn('domain', $domainNames)->get(['domain', 'maxquota', 'quota', 'backupmx'])->keyBy('domain');
        $allocated = DB::table('mailbox')->whereIn('domain', $domainNames)->where('kind', '')->groupBy('domain')
            ->get(['domain', DB::raw('COALESCE(SUM(`quota`), 0) AS `allocated`')])->keyBy('domain');
        $usage = DB::table(config('restv2.master') ? 'quota2' : 'quota2replica')->whereIn('username', $addresses)->get()->keyBy('username');
        $acls = DB::table('user_acl')->whereIn('username', $addresses)->get()->keyBy('username');
        $tags = self::grouped(DB::table('tags_mailbox')->whereIn('username', $addresses)->get(['username', 'tag_name']), 'tag_name');
        $logins = $this->lastLogins($addresses);
        $temporary = DB::table('spamalias')->whereIn('goto', $addresses)
            ->where(fn ($q) => $q->where('validity', '>=', time())->orWhere('permanent', '!=', 0))
            ->groupBy('goto')->get(['goto', DB::raw('COUNT(*) AS `count`')])->keyBy('goto');
        $pushover = array_flip(DB::table('pushover')->whereIn('username', $addresses)->where('active', 1)->pluck('username')->map(fn (mixed $u): string => (string) $u)->all());

        try {
            $rateLimits = $this->store->getFields(RateLimitStore::HASH, array_values(array_unique([...$addresses, ...$domainNames])));
            $subject = $this->store->getFields(self::TAGGED_SUBJECT, $addresses);
            $subfolder = $this->store->getFields(self::TAGGED_SUBFOLDER, $addresses);
        } catch (SharedStoreUnavailable $e) {
            if (! $tolerateSharedStore) {
                throw $e;
            }

            [$rateLimits, $subject, $subfolder] = [[], [], []];
        }

        $flags = $principal->role === Role::DomainAdmin ? $this->identity->permissions($principal) : [];
        $builtIn = $this->defaults->builtIn();
        $result = [];

        foreach ($rows as $row) {
            $address = (string) $row->username;
            $domain = (string) $row->domain;
            $attributes = self::decode($row->attributes ?? null);
            $quota = (int) $row->quota;
            $used = $usage->get($address);
            $bytes = (int) ($used->bytes ?? 0);
            $domainRow = $domains->get($domain);
            $mailboxTags = $tags[$address] ?? [];
            $acl = $acls->get($address);
            $rateLimit = RateLimitStore::parse($rateLimits[$address] ?? null);
            $domainRateLimit = RateLimitStore::parse($rateLimits[$domain] ?? null);
            $flag = fn (string $key, bool $default): bool => MailboxValues::flag($attributes[$key] ?? null) ?? $default;
            $protocols = [];

            foreach (MailboxValues::PROTOCOLS as $protocol => $key) {
                $protocols[$protocol] = $flag($key, true);
            }

            $notification = $attributes['quarantine_notification'] ?? null;
            $recovery = $attributes['recovery_email'] ?? null;
            $relayhost = $attributes['relayhost'] ?? null;
            $otherMailboxes = (int) ($allocated->get($domain)->allocated ?? 0) - $quota;
            $largest = $domainRow === null ? 0 : max(0, min((int) $domainRow->maxquota * self::MIB, (int) $domainRow->quota * self::MIB - $otherMailboxes));

            $result[] = [
                'address' => $address,
                'localPart' => (string) $row->local_part,
                'domain' => $domain,
                'domainUnicode' => DomainName::toUnicode($domain),
                'displayName' => (string) ($row->name ?? ''),
                'status' => MailboxValues::status($row->active),
                'authSource' => MailboxValues::authSource($row->authsource ?? null),
                'quota' => $quota,
                'quotaUsed' => max(0, $bytes),
                'messageCount' => max(0, (int) ($used->messages ?? 0)),
                'percentUsed' => $quota === 0 ? null : max(0, (int) round($bytes / $quota * 100)),
                'largestAllowedQuota' => $largest,
                'forcePasswordChange' => $flag('force_pw_update', false),
                'forceSecondFactorSetup' => $flag('force_tfa', false),
                'webmailRedirect' => $flag('sogo_access', true),
                'protocolAccess' => $protocols,
                'userPermissions' => UserPermissions::visibleTo(UserPermissions::fromRow($acl), $principal, $flags),
                'tags' => TagSet::sort($mailboxTags),
                'customAttributes' => self::customAttributes($row->custom_attributes ?? null),
                'recoveryEmail' => is_string($recovery) && $recovery !== '' ? mb_substr($recovery, 0, 255) : null,
                'passwordChangedAt' => self::passwordChangedAt($attributes['passwd_update'] ?? null),
                'lastLogins' => $logins[$address] ?? ['imap' => null, 'pop3' => null, 'smtp' => null, 'webmailSso' => null],
                'taggedMailHandling' => isset($subject[$address]) ? 'subject' : (isset($subfolder[$address]) ? 'subfolder' : 'none'),
                'tlsEnforceInbound' => $flag('tls_enforce_in', false),
                'tlsEnforceOutbound' => $flag('tls_enforce_out', false),
                'quarantineNotification' => in_array($notification, MailboxValues::QUARANTINE_NOTIFICATIONS, true) ? $notification : $builtIn['quarantineNotification'],
                'quarantineCategory' => MailboxValues::quarantineCategory($attributes['quarantine_category'] ?? null) ?? $builtIn['quarantineCategory'],
                'rateLimit' => $rateLimit ?? $domainRateLimit,
                'rateLimitSource' => $rateLimit !== null ? 'mailbox' : ($domainRateLimit !== null ? 'domain' : 'none'),
                'relayhostId' => is_numeric($relayhost) && (int) $relayhost > 0 ? (int) $relayhost : null,
                'activeTemporaryAliases' => (int) ($temporary->get($address)->count ?? 0),
                'pushNotificationsActive' => isset($pushover[$address]),
                'domainRelayed' => (bool) ($domainRow->backupmx ?? false),
                'createdAt' => DomainPresenter::time($row->created),
                'modifiedAt' => DomainPresenter::time($row->modified),
                'etag' => $this->version->forMailbox($row, $acl, $mailboxTags),
            ];
        }

        return $result;
    }

    /**
     * The most recent recent-login record per service, or nothing when last logins are not
     * reported (legacy `$SHOW_LAST_LOGIN`).
     *
     * @param  list<string>  $addresses
     * @return array<string, array{imap: string|null, pop3: string|null, smtp: string|null, webmailSso: string|null}>
     */
    private function lastLogins(array $addresses): array
    {
        if (! $this->config->showLastLogin()) {
            return [];
        }

        $services = ['imap' => 'imap', 'pop3' => 'pop3', 'smtp' => 'smtp', 'SSO' => 'webmailSso'];
        $rows = DB::table('sasl_log')->whereIn('username', $addresses)->whereIn('service', array_keys($services))
            ->groupBy('username', 'service')
            ->get(['username', 'service', DB::raw('MAX(`datetime`) AS `last`')]);
        $logins = [];

        foreach ($rows as $row) {
            $address = (string) $row->username;
            $logins[$address] ??= ['imap' => null, 'pop3' => null, 'smtp' => null, 'webmailSso' => null];
            $member = $services[(string) $row->service] ?? null;

            if ($member !== null) {
                $logins[$address][$member] = DomainPresenter::time($row->last);
            }
        }

        return $logins;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, list<string>>
     */
    private static function grouped(Collection $rows, string $column): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row->username][] = (string) $row->{$column};
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : [];
    }

    /**
     * Custom attributes as a JSON object of texts; legacy values that are not texts are shown as
     * their JSON text.
     */
    public static function customAttributes(mixed $json): stdClass
    {
        $attributes = new stdClass;

        foreach (self::decode($json) as $name => $value) {
            $attributes->{(string) $name} = is_string($value) ? $value : (is_scalar($value) ? var_export($value, true) : (string) json_encode($value));
        }

        return $attributes;
    }

    /**
     * `passwd_update`: legacy writes `NOW()` (mailcow local time) or, in older rows, a Unix time.
     */
    public static function passwordChangedAt(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestamp((int) $value, 'UTC')->format('Y-m-d\TH:i:s\Z');
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1 ? DomainPresenter::time($value) : null;
    }
}
