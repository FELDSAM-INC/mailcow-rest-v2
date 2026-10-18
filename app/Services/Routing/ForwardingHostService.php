<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ListQuery;
use App\Support\Routing\IpNetworkLiteral;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * Forwarding hosts, legacy fwdhost() (feature 009 FR-074–FR-082, research R16, R17): the list
 * (filtered, searched, sorted and paged in PHP from two `HGETALL` calls), the representation and
 * version tag, and the writes. A creation resolves the host (ForwardingHostResolver), updates every
 * listed address that equals a resolved one in canonical form (it keeps its stored spelling, as
 * legacy overwrote it), adds the others in canonical form, and writes them all in one Redis
 * transaction. Each write runs its audit insert in a database transaction whose last step is the
 * Redis transaction; when anything fails after the Redis transaction was attempted, the previous
 * fields are restored. An unavailable store answers `503` before anything is written.
 */
class ForwardingHostService implements RoutingBulkHandler
{
    public const SORTS = ['address' => 'address', 'source' => 'source', 'spamFilter' => 'spamFilter'];

    public function __construct(
        private readonly ForwardingHostStore $store,
        private readonly ForwardingHostResolver $resolver,
        private readonly RoutingAudit $audit,
    ) {}

    /**
     * @param  array{spamFilter: bool|null, source: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(ListQuery $list, array $filters): array
    {
        $entries = [];
        $search = $list->search === null ? null : mb_strtolower($list->search);

        foreach ($this->store->all() as $address => $entry) {
            if (($filters['spamFilter'] !== null && $filters['spamFilter'] === $entry['keepSpam'])
                || ($filters['source'] !== null && $entry['source'] !== $filters['source'])
                || ($search !== null && ! str_contains(mb_strtolower($address), $search) && ! str_contains(mb_strtolower($entry['source']), $search))) {
                continue;
            }

            $entries[] = self::present($address, $entry['source'], $entry['keepSpam']);
        }

        $direction = $list->order === 'desc' ? -1 : 1;
        usort($entries, function (array $a, array $b) use ($list, $direction): int {
            $byAddress = strcmp(IpNetworkLiteral::sortKeyOf($a['address']), IpNetworkLiteral::sortKeyOf($b['address']));
            $primary = match ($list->sort) {
                'source' => strcmp(mb_strtolower($a['source']), mb_strtolower($b['source'])),
                'spamFilter' => (int) $a['spamFilter'] <=> (int) $b['spamFilter'],
                default => $byAddress,
            };

            return $direction * ($primary !== 0 ? $primary : $byAddress);
        });

        return ['data' => array_slice($entries, $list->offset, $list->limit), 'meta' => $list->meta(count($entries))];
    }

    /**
     * The entry stored under exactly this address, or null.
     *
     * @return array<string, mixed>|null contract `ForwardingHost`
     */
    public function find(string $address): ?array
    {
        $entry = $this->store->all()[$address] ?? null;

        return $entry === null ? null : self::present($address, $entry['source'], $entry['keepSpam']);
    }

    /**
     * @return array{address: string, source: string, spamFilter: bool, etag: string}
     */
    public static function present(string $address, string $source, bool $keepSpam): array
    {
        return ['address' => $address, 'source' => $source, 'spamFilter' => ! $keepSpam, 'etag' => self::version($address, $source, $keepSpam)];
    }

    public static function version(string $address, string $source, bool $keepSpam): string
    {
        return ETag::of(['forwardingHost', $address, $source, $keepSpam]);
    }

    /**
     * @return array{created: bool, result: array{source: string, entries: list<array{outcome: string, forwardingHost: array<string, mixed>}>}}
     */
    public function create(Principal $principal, string $host, bool $spamFilter): array
    {
        $resolved = $this->resolver->resolve($host);
        $stored = $this->store->all();
        $byCanonical = [];

        foreach (array_keys($stored) as $address) {
            $canonical = IpNetworkLiteral::parse($address)?->canonical();

            if ($canonical !== null) {
                $byCanonical[$canonical] ??= $address;
            }
        }

        $entries = [];

        foreach ($resolved['addresses'] as $canonical) {
            $address = $byCanonical[$canonical] ?? $canonical;
            $entries[$address] = isset($stored[$address]) ? 'updated' : 'added';
        }

        uksort($entries, fn (string $a, string $b): int => strcmp(IpNetworkLiteral::sortKeyOf($a), IpNetworkLiteral::sortKeyOf($b)));
        $addresses = array_map('strval', array_keys($entries));

        $this->write($addresses, function () use ($principal, $resolved, $entries, $spamFilter): void {
            $this->audit->success(AuditEventType::ForwardingHostCreation, $principal, RoutingAudit::target('forwardingHost', $resolved['source'], implode(' ', array_keys($entries))), [
                'entries' => array_map(fn (string $address, string $outcome): array => ['address' => $address, 'outcome' => $outcome], array_map('strval', array_keys($entries)), array_values($entries)),
                'spamFilter' => $spamFilter,
            ]);

            $this->store->apply(array_fill_keys(array_keys($entries), $resolved['source']), array_fill_keys(array_keys($entries), ! $spamFilter));
        });

        return [
            'created' => in_array('added', $entries, true),
            'result' => [
                'source' => $resolved['source'],
                'entries' => array_map(fn (string $address): array => ['outcome' => $entries[$address], 'forwardingHost' => self::present($address, $resolved['source'], ! $spamFilter)], $addresses),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function update(Principal $principal, string $address, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        $current = $this->find($address) ?? throw new NotFoundHttpException('The forwarding host does not exist.');

        if (ETag::preconditionFails($ifMatch, (string) $current['etag'])) {
            throw new PreconditionFailedHttpException('The forwarding host was changed since it was read.');
        }

        $violations = new FieldViolations;
        PatchMembers::check($patch, ['spamFilter'], array_intersect_key($current, array_flip(['address', 'source', 'etag'])), $violations);
        $violations->throwIfAny();

        $spamFilter = array_key_exists('spamFilter', $patch) && is_bool($patch['spamFilter']) ? $patch['spamFilter'] : (bool) $current['spamFilter'];
        $changes = $spamFilter === $current['spamFilter'] ? [] : ['spamFilter' => ['old' => $current['spamFilter'], 'new' => $spamFilter]];

        $this->write([$address], function () use ($principal, $address, $changes, $spamFilter, $bulkRequestId): void {
            $this->audit->success(AuditEventType::ForwardingHostUpdate, $principal, RoutingAudit::target('forwardingHost', $address, $address), ['changes' => (object) $changes], $bulkRequestId);

            if ($changes !== []) {
                $this->store->apply([], [$address => ! $spamFilter]);
            }
        });
    }

    public function delete(Principal $principal, string $address, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        $current = $this->find($address) ?? throw new NotFoundHttpException('The forwarding host does not exist.');

        if (ETag::preconditionFails($ifMatch, (string) $current['etag'])) {
            throw new PreconditionFailedHttpException('The forwarding host was changed since it was read.');
        }

        $this->write([$address], function () use ($principal, $address, $current, $bulkRequestId): void {
            $this->audit->success(AuditEventType::ForwardingHostDeletion, $principal, RoutingAudit::target('forwardingHost', $address, $address), ['source' => $current['source']], $bulkRequestId);
            $this->store->apply([], [], [$address]);
        });
    }

    public function bulkEvent(string $action): AuditEventType
    {
        return $action === 'delete' ? AuditEventType::ForwardingHostDeletion : AuditEventType::ForwardingHostUpdate;
    }

    public function bulkResolve(Principal $principal, string $id): ?string
    {
        try {
            return $this->find($id) === null ? null : $id;
        } catch (Throwable) {
            // The store is unavailable: the item fails with the store's error when it is applied.
            return $id;
        }
    }

    public function bulkApply(Principal $principal, string $key, string $action, ?string $ifMatch, string $bulkRequestId): ?string
    {
        if ($action === 'delete') {
            $this->delete($principal, $key, $ifMatch, $bulkRequestId);

            return null;
        }

        $this->update($principal, $key, ['spamFilter' => $action === 'enableSpamFilter'], $ifMatch, $bulkRequestId);

        return $this->find($key)['etag'] ?? null;
    }

    public function bulkAuditTarget(string $id, ?string $key): array
    {
        return RoutingAudit::target('forwardingHost', $key ?? $id, $key ?? $id);
    }

    /**
     * Runs the audit insert and the Redis transaction (its last step) in one database transaction;
     * when anything fails after the Redis transaction was attempted, the snapshot of the touched
     * fields is written back.
     *
     * @param  list<string>  $addresses
     * @param  callable(): void  $write
     */
    private function write(array $addresses, callable $write): void
    {
        $snapshot = $this->store->snapshot($addresses);

        try {
            DB::transaction(function () use ($write): void {
                $write();
            });
        } catch (Throwable $e) {
            try {
                $this->store->restore($snapshot);
            } catch (Throwable $restore) {
                Log::error('Forwarding host fields could not be restored after a failed change: '.$restore->getMessage());
            }

            throw $e;
        }
    }
}
