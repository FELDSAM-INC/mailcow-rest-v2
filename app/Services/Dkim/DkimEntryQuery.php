<?php

namespace App\Services\Dkim;

use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\SharedStore\DkimKeyStore;
use App\Services\Identity\IdentityService;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * The DKIM entry list (feature 010 FR-009, FR-010, research R8), built per request without caching:
 *
 *  1. names and kinds from MariaDB (`domain`, `alias_domain`), restricted to the caller's scope;
 *  2. which names have a key, from the field names of `DKIM_PUB_KEYS` and `DKIM_SELECTORS`;
 *  3. for administrators, the unused keys: key names that are, spelled exactly so, neither a domain
 *     nor an alias domain;
 *  4. selectors and public keys are loaded only for the filters and sorts that need them, private
 *     key existence (pipelined `HEXISTS`) only for the completeness filter;
 *  5. search, filters, sort (ties by name), page;
 *  6. the page's keys in three round trips.
 *
 * Key lengths are parsed once per public key text within a request.
 */
class DkimEntryQuery
{
    public const SORTS = ['name' => 'name', 'state' => 'state', 'selector' => 'selector', 'keyLength' => 'keyLength'];

    /** @var array<string, int|null> sha1 of a public key text => bits */
    private array $bits = [];

    public function __construct(
        private readonly DkimKeyStore $store,
        private readonly IdentityService $identity,
    ) {}

    /**
     * @param  array{state: list<string>, kind: string|null, selector: string|null, complete: bool|null, domain: string|null}  $filters
     * @return array{entries: list<DkimEntry>, total: int}
     */
    public function page(Principal $principal, array $filters, ListQuery $list): array
    {
        $candidates = $this->candidates($principal);
        $keyNames = $this->store->keyNames();

        if ($principal->role === Role::Admin) {
            foreach (array_keys($keyNames) as $name) {
                $name = (string) $name;

                if (! isset($candidates[$name])) {
                    $candidates[$name] = [DkimName::NONE, null];
                }
            }
        }

        $rows = [];

        foreach ($candidates as $name => [$kind, $target]) {
            $name = (string) $name;
            $hasKey = isset($keyNames[$name]);
            $state = ! $hasKey ? DkimEntry::MISSING : ($kind === DkimName::NONE ? DkimEntry::UNUSED : DkimEntry::VALID);
            $rows[] = ['name' => $name, 'kind' => $kind, 'target' => $target, 'hasKey' => $hasKey, 'state' => $state, 'display' => self::displayName($name)];
        }

        $rows = array_values(array_filter($rows, fn (array $row): bool => $this->matchesCheapFilters($row, $filters, $list->search)));

        $selectors = $filters['selector'] !== null || $filters['complete'] !== null || $list->sort === 'selector' ? $this->store->selectors() : [];
        $pubs = $filters['complete'] !== null || $list->sort === 'keyLength' ? $this->store->publicKeys() : [];

        if ($filters['selector'] !== null) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['hasKey'] && ($selectors[$row['name']] ?? null) === $filters['selector']));
        }

        if ($filters['complete'] !== null) {
            $rows = $this->filterComplete($rows, $filters['complete'], $selectors, $pubs);
        }

        $rows = $this->sort($rows, $list, $selectors, $pubs);
        $total = count($rows);
        $page = array_slice($rows, $list->offset, $list->limit);
        $keys = $this->store->readMany(array_values(array_map(fn (array $row): string => $row['name'], array_filter($page, fn (array $row): bool => $row['hasKey']))));

        return [
            'entries' => array_map(fn (array $row): DkimEntry => new DkimEntry(
                new DkimName($row['name'], $row['display'], $row['kind'], $row['target']),
                $keys[$row['name']] ?? null,
            ), $page),
            'total' => $total,
        ];
    }

    /**
     * The entry of a resolved name, with its current key.
     */
    public function entry(DkimName $name): DkimEntry
    {
        return new DkimEntry($name, $this->store->read($name->name));
    }

    /**
     * The readable form of a stored name: the Unicode form of a normalised name, a legacy spelling
     * as stored.
     */
    public static function displayName(string $name): string
    {
        return DomainName::normalize($name) === $name ? DomainName::toUnicode($name) : $name;
    }

    /**
     * Domains and alias domains in the caller's scope: name => [kind, target domain].
     *
     * @return array<string, array{string, string|null}>
     */
    private function candidates(Principal $principal): array
    {
        $domains = DB::table('domain')->select('domain');
        $aliases = DB::table('alias_domain')->select(['alias_domain', 'target_domain']);

        if ($principal->role !== Role::Admin) {
            $scope = $principal->role === Role::DomainAdmin ? $this->identity->scope($principal)->domains : [];
            $domains->whereIn('domain', $scope);
            $aliases->whereIn('target_domain', $scope);
        }

        $candidates = [];

        foreach ($domains->pluck('domain') as $domain) {
            $candidates[(string) $domain] = [DkimName::DOMAIN, null];
        }

        foreach ($aliases->get() as $row) {
            $candidates[(string) $row->alias_domain] ??= [DkimName::ALIAS_DOMAIN, (string) $row->target_domain];
        }

        return $candidates;
    }

    /**
     * @param  array{name: string, kind: string, target: string|null, hasKey: bool, state: string, display: string}  $row
     * @param  array{state: list<string>, kind: string|null, selector: string|null, complete: bool|null, domain: string|null}  $filters
     */
    private function matchesCheapFilters(array $row, array $filters, ?string $search): bool
    {
        if ($filters['state'] !== [] && ! in_array($row['state'], $filters['state'], true)) {
            return false;
        }

        if ($filters['kind'] !== null && $row['kind'] !== $filters['kind']) {
            return false;
        }

        if ($filters['domain'] !== null) {
            $inDomain = ($row['kind'] === DkimName::DOMAIN && $row['name'] === $filters['domain'])
                || ($row['kind'] === DkimName::ALIAS_DOMAIN && $row['target'] === $filters['domain']);

            if (! $inDomain) {
                return false;
            }
        }

        if ($search !== null) {
            $needle = mb_strtolower($search);

            return str_contains(mb_strtolower($row['name']), $needle) || str_contains(mb_strtolower($row['display']), $needle);
        }

        return true;
    }

    /**
     * @param  list<array{name: string, kind: string, target: string|null, hasKey: bool, state: string, display: string}>  $rows
     * @param  array<string, string>  $selectors
     * @param  array<string, string>  $pubs
     * @return list<array{name: string, kind: string, target: string|null, hasKey: bool, state: string, display: string}>
     */
    private function filterComplete(array $rows, bool $complete, array $selectors, array $pubs): array
    {
        $fields = [];

        foreach ($rows as $row) {
            if ($row['hasKey'] && isset($selectors[$row['name']])) {
                $fields[] = $selectors[$row['name']].'.'.$row['name'];
            }
        }

        $exists = $this->store->privateKeysExist(array_values(array_unique($fields)));

        return array_values(array_filter($rows, function (array $row) use ($complete, $selectors, $pubs, $exists): bool {
            if (! $row['hasKey']) {
                return false;
            }

            $selector = $selectors[$row['name']] ?? null;
            $isComplete = $selector !== null && isset($pubs[$row['name']]) && ($exists[$selector.'.'.$row['name']] ?? false);

            return $isComplete === $complete;
        }));
    }

    /**
     * @param  list<array{name: string, kind: string, target: string|null, hasKey: bool, state: string, display: string}>  $rows
     * @param  array<string, string>  $selectors
     * @param  array<string, string>  $pubs
     * @return list<array{name: string, kind: string, target: string|null, hasKey: bool, state: string, display: string}>
     */
    private function sort(array $rows, ListQuery $list, array $selectors, array $pubs): array
    {
        $direction = $list->order === 'desc' ? -1 : 1;
        $value = match ($list->sort) {
            'state' => fn (array $row): string => $row['state'],
            'selector' => fn (array $row): ?string => $row['hasKey'] ? ($selectors[$row['name']] ?? null) : null,
            'keyLength' => fn (array $row): ?int => $row['hasKey'] && isset($pubs[$row['name']]) ? $this->bits($pubs[$row['name']]) : null,
            default => null,
        };

        if ($value !== null) {
            foreach ($rows as $i => $row) {
                $rows[$i]['sortValue'] = $value($row);
            }
        }

        usort($rows, function (array $a, array $b) use ($value, $direction): int {
            $primary = 0;

            if ($value !== null) {
                $primary = match (true) {
                    $a['sortValue'] === null && $b['sortValue'] === null => 0,
                    $a['sortValue'] === null => 1,
                    $b['sortValue'] === null => -1,
                    is_int($a['sortValue']) && is_int($b['sortValue']) => $a['sortValue'] <=> $b['sortValue'],
                    default => strcmp((string) $a['sortValue'], (string) $b['sortValue']),
                };
            }

            return ($primary !== 0 ? $primary : strcmp($a['name'], $b['name'])) * $direction;
        });

        return array_map(function (array $row): array {
            unset($row['sortValue']);

            return $row;
        }, $rows);
    }

    private function bits(string $publicKey): ?int
    {
        $hash = sha1($publicKey);

        if (! array_key_exists($hash, $this->bits)) {
            $this->bits[$hash] = (new DkimPublicKey($publicKey))->bits();
        }

        return $this->bits[$hash];
    }
}
