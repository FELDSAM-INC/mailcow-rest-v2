<?php

namespace App\Services\Routing;

use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use App\Support\Routing\CredentialFingerprint;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads of relayhosts (feature 009 FR-007, FR-015–FR-017, FR-022, FR-023, research R10): the list
 * with its usage counts from two derived tables joined once (never a lookup per row), the usage
 * list, the choices, and the representation and version tag shared by every read and write.
 *
 * A domain's `relayhost` and a mailbox's `attributes.relayhost` are compared with the relayhost ID
 * numerically, as the mail server's lookups compare them, so legacy spellings such as `03` count;
 * resources are not mailboxes. The password is selected only to compute the version tag and
 * `passwordSet`; it never enters a representation.
 */
class RelayhostQuery
{
    /** contract sort value => column */
    public const SORTS = [
        'id' => 'r.id',
        'host' => 'r.hostname',
        'username' => 'r.username',
        'active' => 'r.active',
        'domainCount' => 'domain_count',
        'mailboxCount' => 'mailbox_count',
    ];

    public const CHOICE_SORTS = ['id' => 'id', 'host' => 'hostname'];

    public const USAGE_SORTS = ['kind' => 'u.kind', 'name' => 'u.name'];

    /** The numeric relayhost ID of a domain row (`0` for none). */
    public const DOMAIN_REFERENCE = 'CAST(`relayhost` AS UNSIGNED)';

    /** The numeric relayhost ID of a mailbox row (`0` or NULL for none). */
    public const MAILBOX_REFERENCE = "CAST(JSON_UNQUOTE(JSON_VALUE(`attributes`, '$.relayhost')) AS UNSIGNED)";

    /**
     * @param  array{active: bool|null, inUse: bool|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function page(ListQuery $list, array $filters): array
    {
        $query = $this->base();

        if ($filters['active'] !== null) {
            $query->where('r.active', $filters['active'] ? '<>' : '=', 0);
        }

        if ($filters['inUse'] !== null) {
            $query->whereRaw('COALESCE(d.cnt, 0) + COALESCE(m.cnt, 0) '.($filters['inUse'] ? '> 0' : '= 0'));
        }

        $list->applySearch($query, ['r.hostname', 'r.username']);

        /** @var array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}} */
        return $list->paginate($query, fn (object $row): array => self::present($row), 'r.id');
    }

    /** The relayhost row with its usage counts, or null. */
    public function find(int $id): ?object
    {
        return $this->base()->where('r.id', $id)->first();
    }

    public function exists(int $id): bool
    {
        return DB::table('relayhosts')->where('id', $id)->exists();
    }

    /**
     * The relayhost of a path or bulk target ID as sent, or null when it does not exist.
     */
    public function resolve(string $id): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1 || (int) $id > 2147483647) {
            return null;
        }

        return $this->exists((int) $id) ? (int) $id : null;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function choices(ListQuery $list): array
    {
        $query = DB::table('relayhosts')->select(['id', 'hostname', 'username', 'active']);
        $list->applySearch($query, ['hostname']);

        /** @var array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}} */
        return $list->paginate($query, fn (object $row): array => [
            'id' => (int) $row->id,
            'host' => (string) $row->hostname,
            'username' => (string) $row->username === '' ? null : (string) $row->username,
            'active' => (int) $row->active !== 0,
        ], 'id');
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function usage(int $id, ListQuery $list, ?string $kind): array
    {
        $domains = DB::table('domain')->selectRaw("'domain' AS `kind`, `domain` AS `name`")->whereRaw(self::DOMAIN_REFERENCE.' = ?', [$id]);
        $mailboxes = DB::table('mailbox')->selectRaw("'mailbox' AS `kind`, `username` AS `name`")->where('kind', '')->whereRaw(self::MAILBOX_REFERENCE.' = ?', [$id]);
        $query = DB::query()->fromSub($domains->unionAll($mailboxes), 'u')->select(['u.kind', 'u.name']);

        if ($kind !== null) {
            $query->where('u.kind', $kind);
        }

        $list->applySearch($query, ['u.name']);

        /** @var array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}} */
        return $list->paginate($query, fn (object $row): array => ['kind' => (string) $row->kind, 'name' => (string) $row->name], 'u.name');
    }

    /**
     * The domains and mailboxes assigned to a relayhost (for its deletion), sorted.
     *
     * @return array{domains: list<string>, mailboxes: list<string>}
     */
    public function assignedTo(int $id): array
    {
        return [
            'domains' => DB::table('domain')->whereRaw(self::DOMAIN_REFERENCE.' = ?', [$id])->orderBy('domain')->pluck('domain')->map(fn (mixed $d): string => (string) $d)->all(),
            'mailboxes' => DB::table('mailbox')->where('kind', '')->whereRaw(self::MAILBOX_REFERENCE.' = ?', [$id])->orderBy('username')->pluck('username')->map(fn (mixed $u): string => (string) $u)->all(),
        ];
    }

    /**
     * The contract `Relayhost` of a row (with `domain_count` and `mailbox_count`).
     *
     * @return array<string, mixed>
     */
    public static function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'host' => (string) $row->hostname,
            'username' => (string) $row->username === '' ? null : (string) $row->username,
            'passwordSet' => (string) $row->password !== '',
            'active' => (int) $row->active !== 0,
            'usage' => ['domains' => (int) ($row->domain_count ?? 0), 'mailboxes' => (int) ($row->mailbox_count ?? 0)],
            'etag' => self::version($row),
        ];
    }

    /**
     * The version tag of the stored settings, the password only as its HMAC fingerprint (research
     * R2); never the usage.
     */
    public static function version(object $row): string
    {
        return ETag::of(['relayhost', (int) $row->id, (string) $row->hostname, (string) $row->username, CredentialFingerprint::of((string) $row->password), (int) $row->active]);
    }

    private function base(): Builder
    {
        $domains = DB::table('domain')->selectRaw(self::DOMAIN_REFERENCE.' AS `rh`, COUNT(*) AS `cnt`')->groupByRaw('`rh`');
        $mailboxes = DB::table('mailbox')->selectRaw(self::MAILBOX_REFERENCE.' AS `rh`, COUNT(*) AS `cnt`')->where('kind', '')->groupByRaw('`rh`');

        return DB::table('relayhosts as r')
            ->leftJoinSub($domains, 'd', 'd.rh', '=', 'r.id')
            ->leftJoinSub($mailboxes, 'm', 'm.rh', '=', 'r.id')
            ->select(['r.id', 'r.hostname', 'r.username', 'r.password', 'r.active'])
            ->selectRaw('COALESCE(`d`.`cnt`, 0) AS `domain_count`, COALESCE(`m`.`cnt`, 0) AS `mailbox_count`');
    }
}
