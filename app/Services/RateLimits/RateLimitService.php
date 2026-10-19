<?php

namespace App\Services\RateLimits;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Policies\RateLimitPolicy;
use App\Services\Access\SpamFilterScope;
use App\Services\Audit\AuditLogger;
use App\Services\Domains\DomainScope;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The sending rate limits of domains, alias domains and mailboxes (feature 006 research R12,
 * FR-050 to FR-056), stored where and how legacy ratelimit('edit') stores them: mailcow's
 * `RL_VALUE` hash, the field being the normalised object (lower case, ASCII domain), which the
 * spam filter's `DYN_RL_CHECK` looks up.
 *
 *  - A mailbox reports the limit that applies to it, as the filter picks it: its own stored value,
 *    else its domain's, else none. A stored value counts whatever its text, as the filter applies
 *    any text it finds; an invalid one reports `effectiveLimit: null`.
 *  - A change compares the stored text with the one read (the version tag's value) and writes in
 *    one atomic step (RateLimitStore's compare-and-set), so that a change made meanwhile, also by
 *    the legacy UI, is never overwritten: with `If-Match` it answers `412`; without, the change is
 *    read and tried again once. Its audit record is inserted in a database transaction that
 *    commits after the write and rolls back when the write fails.
 *  - Removing a limit that is not set changes nothing and writes no record.
 *  - The list classifies every field of the hash as an existing domain, alias domain or mailbox
 *    (never a resource) in the caller's scope, with one query per kind; other fields, also
 *    fields in another letter case, are left out (plan parity note 3). The hash is small, so
 *    filtering, search, sorting and paging run in PHP.
 *
 * An unavailable shared store raises SharedStoreUnavailable (`503 temporary-failure`,
 * `dependency: sharedStore`, FR-061), and nothing is changed.
 */
class RateLimitService
{
    public const KINDS = ['domain', 'aliasDomain', 'mailbox'];

    /** Contract sort values (sorting runs in PHP). */
    public const SORTS = ['object' => 'object', 'kind' => 'kind'];

    private const ATTEMPTS = 2;

    public function __construct(
        private readonly SpamFilterScope $scope,
        private readonly RateLimitPolicy $policy,
        private readonly RateLimitStore $store,
        private readonly DomainScope $domains,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed> the contract `RateLimit`
     */
    public function read(Principal $principal, string $kind, string $raw): array
    {
        $this->policy->authorize($principal, RateLimitPolicy::VIEW);
        $object = $this->resolve($principal, $kind, $raw);

        return $this->present($kind, $object, $this->store->raw($object), $kind === 'mailbox' ? $this->mailboxDomainValue($object) : null);
    }

    /**
     * @return array<string, mixed> the contract `RateLimit` after the change
     */
    public function set(Principal $principal, string $kind, string $raw, int $value, string $frame, ?string $ifMatch): array
    {
        $this->policy->authorize($principal, RateLimitPolicy::CHANGE);
        $object = $this->resolve($principal, $kind, $raw);
        $new = RateLimitStore::format($value, $frame);

        $this->write($principal, $kind, $object, $new, $ifMatch);

        return $this->present($kind, $object, $new, $kind === 'mailbox' ? $this->mailboxDomainValue($object) : null);
    }

    public function remove(Principal $principal, string $kind, string $raw, ?string $ifMatch): void
    {
        $this->policy->authorize($principal, RateLimitPolicy::CHANGE);
        $object = $this->resolve($principal, $kind, $raw);

        $this->write($principal, $kind, $object, null, $ifMatch);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(Principal $principal, ListQuery $list, ?string $kind): array
    {
        $this->policy->authorize($principal, RateLimitPolicy::VIEW);
        $values = $this->store->all();
        $entries = [];

        foreach ($this->classify($principal, array_keys($values)) as $object => $entry) {
            if ($kind !== null && $entry['kind'] !== $kind) {
                continue;
            }

            if ($list->search !== null && ! str_contains(mb_strtolower($object), mb_strtolower($list->search))) {
                continue;
            }

            $domainValue = $entry['kind'] === 'mailbox' ? ($values[(string) $entry['domain']] ?? null) : null;
            $entries[] = $this->present($entry['kind'], $object, $values[$object], $domainValue);
        }

        $direction = $list->order === 'desc' ? -1 : 1;
        usort($entries, function (array $a, array $b) use ($list, $direction): int {
            $primary = $list->sort === 'kind' ? $direction * strcmp((string) $a['kind'], (string) $b['kind']) : 0;

            return $primary !== 0 ? $primary : ($list->sort === 'kind' ? 1 : $direction) * strcmp((string) $a['object'], (string) $b['object']);
        });

        return [
            'data' => array_slice($entries, $list->offset, $list->limit),
            'meta' => $list->meta(count($entries)),
        ];
    }

    /**
     * Writes (or, with $new null, removes) an object's limit when it is still the one read, with
     * its audit record.
     */
    private function write(Principal $principal, string $kind, string $object, ?string $new, ?string $ifMatch): void
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $old = $this->store->raw($object);

            if (ETag::preconditionFails($ifMatch, self::etag($object, $old))) {
                throw new PreconditionFailedHttpException('The rate limit has changed since it was read.');
            }

            if ($new === null && $old === null) {
                return;
            }

            try {
                DB::transaction(function () use ($principal, $kind, $object, $old, $new): void {
                    $this->audit->record($new === null ? AuditEventType::RateLimitRemoval : AuditEventType::RateLimitUpdate, true, $principal, ['type' => 'rateLimit', 'id' => $object, 'name' => $object], null, null, ['kind' => $kind, 'old' => $old, 'new' => $new]);
                    $outcome = $new === null ? $this->store->removeIfMatches($object, $old) : $this->store->setIfMatches($object, $old, $new);

                    if ($outcome === RateLimitWrite::Outdated) {
                        throw new PreconditionFailedHttpException('The rate limit has changed since it was read.');
                    }
                });

                return;
            } catch (PreconditionFailedHttpException $e) {
                if ($ifMatch !== null) {
                    throw $e;
                }
            }
        }

        throw new SharedStoreUnavailable('The rate limit kept changing while it was saved.');
    }

    /**
     * The normalised object of a path in the caller's scope, or `404`.
     */
    private function resolve(Principal $principal, string $kind, string $raw): string
    {
        return match ($kind) {
            'domain' => $this->scope->domain($principal, $raw),
            'aliasDomain' => $this->scope->aliasDomain($principal, $raw),
            default => $this->scope->mailbox($principal, $raw),
        };
    }

    /** The stored limit of a mailbox's domain (the fallback of its effective limit). */
    private function mailboxDomainValue(string $mailbox): ?string
    {
        $domain = DB::table('mailbox')->where('username', $mailbox)->value('domain');

        return is_string($domain) ? $this->store->raw($domain) : null;
    }

    /**
     * The fields that are existing objects in the caller's scope.
     *
     * @param  list<string>  $fields
     * @return array<string, array{kind: string, domain: string|null}> object => kind (and a mailbox's domain)
     */
    private function classify(Principal $principal, array $fields): array
    {
        $fields = array_map('strval', $fields);
        $addresses = array_values(array_filter($fields, fn (string $field): bool => str_contains($field, '@')));
        $names = array_values(array_diff($fields, $addresses));
        $found = [];

        if ($names !== []) {
            $domains = $this->domains->restrict(DB::table('domain')->whereIn('domain.domain', $names), $principal)->pluck('domain.domain');

            foreach ($domains as $name) {
                $found[(string) $name] = ['kind' => 'domain', 'domain' => null];
            }

            $aliasDomains = $this->domains->restrict(DB::table('alias_domain')->whereIn('alias_domain.alias_domain', $names), $principal, 'alias_domain.target_domain')->pluck('alias_domain.alias_domain');

            foreach ($aliasDomains as $name) {
                $found[(string) $name] = ['kind' => 'aliasDomain', 'domain' => null];
            }
        }

        if ($addresses !== []) {
            $mailboxes = $this->domains->restrict(DB::table('mailbox')->whereIn('mailbox.username', $addresses)->where('mailbox.kind', ''), $principal, 'mailbox.domain')
                ->get(['mailbox.username', 'mailbox.domain']);

            foreach ($mailboxes as $row) {
                $found[(string) $row->username] = ['kind' => 'mailbox', 'domain' => (string) $row->domain];
            }
        }

        // The collation matches case-insensitively; the filter looks up the exact field.
        return array_filter($found, fn (string $object): bool => in_array($object, $fields, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array<string, mixed> the contract `RateLimit`
     */
    private function present(string $kind, string $object, ?string $raw, ?string $domainRaw): array
    {
        $stored = RateLimitStore::classify($raw);
        $entry = ['object' => $object, 'kind' => $kind] + $stored;

        if ($kind === 'mailbox') {
            [$source, $effective] = match (true) {
                $raw !== null => ['mailbox', $stored['limit']],
                $domainRaw !== null => ['domain', RateLimitStore::classify($domainRaw)['limit']],
                default => ['none', null],
            };
            $entry += ['effectiveLimit' => $effective, 'effectiveSource' => $source];
        }

        return $entry + ['etag' => self::etag($object, $raw)];
    }

    /** The version tag over the stored text (also when none is set). */
    public static function etag(string $object, ?string $raw): string
    {
        return ETag::of(['rateLimit', $object, $raw]);
    }
}
