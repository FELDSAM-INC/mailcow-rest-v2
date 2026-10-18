<?php

namespace App\Services\Domains;

use App\Auth\Principal;
use App\Auth\Role;
use App\Casts\MailcowDateTime;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Domain\DomainName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The contract's `Domain` representation (FR-007, research D2) of a page of domain rows: stored
 * settings (quotas in bytes), tags, relayhost, rate limit, times in UTC, usage figures, version tag
 * and, for administrators only, the domain administrators. Related data is read for the whole page
 * with one query per kind.
 */
class DomainPresenter
{
    private const MIB = 1048576;

    public function __construct(
        private readonly DomainUsageCalculator $usage,
        private readonly RateLimitStore $rateLimits,
        private readonly DomainVersion $version,
    ) {}

    /**
     * @param  list<object>  $rows  `domain` rows, in the order to return
     * @return list<array<string, mixed>>
     */
    public function present(array $rows, Principal $principal): array
    {
        if ($rows === []) {
            return [];
        }

        $names = array_map(fn (object $row): string => (string) $row->domain, $rows);
        $tags = $this->tags($names);
        $usage = $this->usage->forDomains($names);
        $rateLimits = $this->rateLimits($names);
        $relayhosts = $this->relayhosts($rows);
        $admins = $principal->role === Role::Admin ? $this->domainAdmins($names) : null;

        $result = [];

        foreach ($rows as $row) {
            $name = (string) $row->domain;
            $domainTags = $tags[$name] ?? [];
            $relayhostId = self::relayhostId((string) $row->relayhost);

            $data = [
                'name' => $name,
                'unicodeName' => DomainName::toUnicode($name),
                'description' => (string) ($row->description ?? ''),
                'active' => (bool) $row->active,
                'maxMailboxes' => (int) $row->mailboxes,
                'maxAliases' => (int) $row->aliases,
                'defaultMailboxQuota' => (int) $row->defquota * self::MIB,
                'maxMailboxQuota' => (int) $row->maxquota * self::MIB,
                'domainQuota' => (int) $row->quota * self::MIB,
                'backupMx' => (bool) $row->backupmx,
                'relayAllRecipients' => (bool) $row->relay_all_recipients,
                'relayUnknownOnly' => (bool) $row->relay_unknown_only,
                'globalAddressList' => (bool) $row->gal,
                'tags' => DomainTagSet::sort($domainTags),
                'relayhost' => $relayhostId === null ? null : ['id' => $relayhostId, 'hostname' => $relayhosts[$relayhostId] ?? null],
                'rateLimit' => $rateLimits[$name] ?? null,
                'createdAt' => self::time($row->created),
                'modifiedAt' => self::time($row->modified),
                'usage' => ($usage[$name] ?? null)?->toArray(),
            ];

            if ($admins !== null) {
                $data['domainAdmins'] = $admins[$name] ?? [];
            }

            $data['etag'] = $this->version->forDomain($row, $domainTags);
            $result[] = $data;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentOne(object $row, Principal $principal): array
    {
        return $this->present([$row], $principal)[0];
    }

    /**
     * mailcow's local DATETIME as RFC 3339 UTC, or null.
     */
    public static function time(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, MailcowDateTime::zone())->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * `domain.relayhost` is a string ID; `'0'`, empty or non-numeric means none.
     */
    public static function relayhostId(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    private function tags(array $names): array
    {
        $tags = [];

        foreach (DB::table('tags_domain')->whereIn('domain', $names)->get(['domain', 'tag_name']) as $row) {
            $tags[(string) $row->domain][] = (string) $row->tag_name;
        }

        return $tags;
    }

    /**
     * Rate limits live in Redis; a read that cannot reach it shows none rather than failing the
     * whole read.
     *
     * @param  list<string>  $names
     * @return array<string, array{value: int, frame: string}|null>
     */
    private function rateLimits(array $names): array
    {
        try {
            return $this->rateLimits->getMany($names);
        } catch (SharedStoreUnavailable $e) {
            Log::warning('Domain rate limits could not be read: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, string>
     */
    private function relayhosts(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (object $row): ?int => self::relayhostId((string) $row->relayhost), $rows))));

        if ($ids === []) {
            return [];
        }

        return DB::table('relayhosts')->whereIn('id', $ids)->pluck('hostname', 'id')
            ->mapWithKeys(fn (mixed $host, mixed $id): array => [(int) $id => (string) $host])->all();
    }

    /**
     * Every assignment of the domain, as legacy lists them, sorted and distinct.
     *
     * @param  list<string>  $names
     * @return array<string, list<string>>
     */
    private function domainAdmins(array $names): array
    {
        $admins = [];

        foreach (DB::table('domain_admins')->whereIn('domain', $names)->orderBy('username')->distinct()->get(['domain', 'username']) as $row) {
            $admins[(string) $row->domain][] = (string) $row->username;
        }

        return array_map(fn (array $list): array => array_values(array_unique($list)), $admins);
    }
}
