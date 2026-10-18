<?php

namespace App\Services\Routing;

use App\Auth\Principal;
use App\Services\Domains\DomainPresenter;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads of BCC maps (feature 009 FR-046–FR-048, FR-056, research R18): the caller's maps
 * (BccScope), the orphaned flag (the map's domain is neither a domain nor an alias domain any
 * more; only administrators reach such maps), filters, search and sorting, and the representation
 * and version tag shared by every read and write. Stored `rcpt` is the contract's `recipient`.
 */
class BccMapQuery
{
    /** contract sort value => column */
    public const SORTS = [
        'id' => 'b.id',
        'type' => 'type_text',
        'localDestination' => 'b.local_dest',
        'bccDestination' => 'b.bcc_dest',
        'domain' => 'b.domain',
        'active' => 'b.active',
        'createdAt' => 'b.created',
        'modifiedAt' => 'b.modified',
    ];

    public function __construct(private readonly BccScope $scope) {}

    /**
     * @param  array{type: string|null, active: bool|null, domain: list<string>, orphaned: bool|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(Principal $principal, ListQuery $list, array $filters): array
    {
        $query = $this->base($principal);

        if ($filters['type'] !== null) {
            $query->where('b.type', $filters['type'] === 'recipient' ? 'rcpt' : 'sender');
        }

        if ($filters['active'] !== null) {
            $query->where('b.active', $filters['active'] ? '<>' : '=', 0);
        }

        if ($filters['domain'] !== []) {
            $query->whereIn('b.domain', $filters['domain']);
        }

        if ($filters['orphaned'] !== null) {
            $query->whereRaw(self::ORPHANED.($filters['orphaned'] ? ' = 1' : ' = 0'));
        }

        $list->applySearch($query, ['b.local_dest', 'b.bcc_dest']);

        /** @var array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}} */
        return $list->paginate($query, fn (object $row): array => self::present($row), 'b.id');
    }

    /** The map of a path or bulk target ID as sent, in the caller's scope, or null. */
    public function resolve(Principal $principal, string $id): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1 || (int) $id > 2147483647) {
            return null;
        }

        return $this->scope->restrict(DB::table('bcc_maps as b'), $principal, 'b.domain')->where('b.id', (int) $id)->exists() ? (int) $id : null;
    }

    public function find(Principal $principal, int $id): ?object
    {
        return $this->base($principal)->where('b.id', $id)->first();
    }

    /**
     * @return array<string, mixed> contract `BccMap`
     */
    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'type' => (string) $row->type === 'rcpt' ? 'recipient' : 'sender',
            'localDestination' => (string) $row->local_dest,
            'bccDestination' => (string) $row->bcc_dest,
            'domain' => (string) $row->domain,
            'orphaned' => (int) ($row->orphaned ?? 0) === 1,
            'active' => (int) $row->active !== 0,
            'createdAt' => DomainPresenter::time($row->created) ?? '1970-01-01T00:00:00Z',
            'modifiedAt' => DomainPresenter::time($row->modified),
            'etag' => self::version($row),
        ];
    }

    public static function version(object $row): string
    {
        return ETag::of(['bccMap', (int) $row->id, (string) $row->local_dest, (string) $row->bcc_dest, (string) $row->domain, (string) $row->type, (int) $row->active]);
    }

    /** Whether a map's domain is gone: 1 or 0. */
    private const ORPHANED = '(NOT EXISTS (SELECT 1 FROM `domain` WHERE `domain`.`domain` = `b`.`domain`) AND NOT EXISTS (SELECT 1 FROM `alias_domain` WHERE `alias_domain`.`alias_domain` = `b`.`domain`))';

    private function base(Principal $principal): Builder
    {
        $query = DB::table('bcc_maps as b')
            ->select(['b.id', 'b.local_dest', 'b.bcc_dest', 'b.domain', 'b.type', 'b.created', 'b.modified', 'b.active'])
            ->selectRaw('CAST(`b`.`type` AS CHAR) AS `type_text`')
            ->selectRaw(self::ORPHANED.' AS `orphaned`');

        return $this->scope->restrict($query, $principal, 'b.domain');
    }
}
