<?php

namespace App\Services\AliasDomains;

use App\Services\Domains\DomainPresenter;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Domain\DomainName;

/**
 * The contract `AliasDomain` of rows of AliasDomainQuery (feature 004 FR-038): readable names,
 * the target's relay flag, the alias count, the rate limit from mailcow's shared store (one `HMGET`
 * per page; SharedStoreUnavailable answers `503`), UTC times and the version tag.
 */
class AliasDomainPresenter
{
    public function __construct(
        private readonly RateLimitStore $rateLimits,
        private readonly AliasDomainVersion $version,
    ) {}

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(array $rows): array
    {
        $limits = $this->rateLimits->getMany(array_values(array_map(fn (object $r): string => (string) $r->alias_domain, $rows)));

        return array_map(fn (object $row): array => $this->one($row, $limits[(string) $row->alias_domain] ?? null), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentOne(object $row): array
    {
        return $this->present([$row])[0];
    }

    /**
     * @param  array{value: int, frame: string}|null  $rateLimit
     * @return array<string, mixed>
     */
    private function one(object $row, ?array $rateLimit): array
    {
        $name = (string) $row->alias_domain;
        $target = (string) $row->target_domain;

        return [
            'name' => $name,
            'nameUnicode' => DomainName::toUnicode($name),
            'targetDomain' => $target,
            'targetDomainUnicode' => DomainName::toUnicode($target),
            'active' => (int) $row->active !== 0,
            'targetIsRelayDomain' => (int) ($row->target_backupmx ?? 0) === 1,
            'aliasCount' => (int) ($row->alias_count ?? 0),
            'rateLimit' => $rateLimit,
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
            'etag' => $this->version->forRow($row),
        ];
    }
}
