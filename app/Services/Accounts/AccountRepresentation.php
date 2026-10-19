<?php

namespace App\Services\Accounts;

use App\Auth\Role;
use App\Casts\MailcowDateTime;
use App\Support\Domain\DomainName;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reads administrator and domain administrator accounts (feature 007 FR-006 to FR-009, FR-013,
 * research R2) and builds the contract's `Admin` and `DomainAdmin` representations. A page of
 * accounts is completed with three batched queries (assignments with `domainExists`, the `tfa`
 * aggregate and the active `fido2` counts) and the `da_acl` rows, never one query per account.
 *
 * Usernames are looked up lower-cased; the `admin` table's case-insensitive collation also finds a
 * name that a legacy rename stored with upper-case letters. Passwords are never read.
 */
class AccountRepresentation
{
    /** Columns of `admin` that the representation and the version tag read. */
    public const COLUMNS = ['username', 'superadmin', 'created', 'modified', 'active', 'attributes'];

    /**
     * The `admin` row of an account of the role, or null for an unknown username or the other role.
     */
    public function row(Role $role, string $username, bool $lock = false): ?stdClass
    {
        $query = DB::table('admin')
            ->where('username', mb_strtolower(trim($username)))
            ->where('superadmin', $role === Role::Admin ? 1 : 0)
            ->select(self::COLUMNS);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The representation of one account, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(Role $role, string $username): ?array
    {
        $row = $this->row($role, $username);

        return $row === null ? null : $this->present($role, [$row])[0];
    }

    /**
     * The `domain_admins` rows of a username, ordered by domain, with `domain_exists`.
     *
     * @return list<stdClass>
     */
    public function assignments(string $username, bool $lock = false): array
    {
        return $this->assignmentsOf([$username], $lock)[mb_strtolower($username)] ?? [];
    }

    /**
     * The `da_acl` row of a username, or null.
     */
    public function daAcl(string $username, bool $lock = false): ?stdClass
    {
        $query = DB::table('da_acl')->where('username', $username);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * The version tag of a stored account, read inside the caller's transaction.
     */
    public function version(Role $role, stdClass $row, bool $lock = false): string
    {
        if ($role === Role::Admin) {
            return AccountVersion::forAdmin($row);
        }

        $username = (string) $row->username;

        return AccountVersion::forDomainAdmin($row, $this->assignments($username, $lock), $this->daAcl($username, $lock));
    }

    /**
     * The representations of `admin` rows of one role, in the given order.
     *
     * @param  list<stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    public function present(Role $role, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $usernames = array_map(fn (stdClass $row): string => (string) $row->username, $rows);
        $secondFactors = $this->secondFactorAggregates($usernames);
        $passkeys = $this->activePasskeyCounts($usernames);
        $assignments = $role === Role::DomainAdmin ? $this->assignmentsOf($usernames) : [];
        $daAcls = $role === Role::DomainAdmin ? $this->daAclsOf($usernames) : [];
        $result = [];

        foreach ($rows as $row) {
            $key = mb_strtolower((string) $row->username);
            $data = [
                'username' => (string) $row->username,
                'active' => (int) $row->active === 1,
                'forcePasswordChange' => AccountVersion::attribute($row->attributes ?? null, 'force_pw_update'),
                'forceSecondFactorSetup' => AccountVersion::attribute($row->attributes ?? null, 'force_tfa'),
                'secondFactorStatus' => SecondFactorStatus::fromAggregate($secondFactors[$key] ?? null)->value,
                'activePasskeyCount' => $passkeys[$key] ?? 0,
                'createdAt' => MailcowDateTime::toUtcString($row->created),
                'modifiedAt' => MailcowDateTime::toUtcString($row->modified),
            ];

            if ($role === Role::DomainAdmin) {
                $own = $assignments[$key] ?? [];
                $daAcl = $daAcls[$key] ?? null;
                $data['domains'] = array_map(fn (stdClass $assignment): array => self::assignment($assignment), $own);
                $data['permissions'] = DomainAdminFlags::fromRow($daAcl);
                $data['etag'] = AccountVersion::forDomainAdmin($row, $own, $daAcl);
            } else {
                $data['etag'] = AccountVersion::forAdmin($row);
            }

            $result[] = $data;
        }

        return $result;
    }

    /**
     * Contract `DomainAdminAssignment`.
     *
     * @return array{domain: string, domainUnicode: string, active: bool, createdAt: string|null, domainExists: bool}
     */
    public static function assignment(stdClass $assignment): array
    {
        return [
            'domain' => (string) $assignment->domain,
            'domainUnicode' => DomainName::toUnicode((string) $assignment->domain),
            'active' => (int) $assignment->active === 1,
            'createdAt' => MailcowDateTime::toUtcString($assignment->created),
            'domainExists' => (int) ($assignment->domain_exists ?? 0) === 1,
        ];
    }

    /**
     * @param  list<string>  $usernames
     * @return array<string, list<stdClass>> lower-cased username => assignments ordered by domain
     */
    private function assignmentsOf(array $usernames, bool $lock = false): array
    {
        $query = DB::table('domain_admins')
            ->leftJoin('domain', 'domain.domain', '=', 'domain_admins.domain')
            ->whereIn('domain_admins.username', $usernames)
            ->orderBy('domain_admins.domain')
            ->orderBy('domain_admins.id')
            ->select(['domain_admins.id', 'domain_admins.username', 'domain_admins.domain', 'domain_admins.active', 'domain_admins.created'])
            ->selectRaw('`domain`.`domain` IS NOT NULL AS `domain_exists`');
        $grouped = [];

        foreach (($lock ? $query->lockForUpdate() : $query)->get() as $row) {
            $grouped[mb_strtolower((string) $row->username)][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<string>  $usernames
     * @return array<string, stdClass>
     */
    private function daAclsOf(array $usernames): array
    {
        $rows = [];

        foreach (DB::table('da_acl')->whereIn('username', $usernames)->get() as $row) {
            $rows[mb_strtolower((string) $row->username)] = $row;
        }

        return $rows;
    }

    /**
     * Per username: null without counted `tfa` entries, 1 when one is active, 0 otherwise.
     *
     * @param  list<string>  $usernames
     * @return array<string, int>
     */
    private function secondFactorAggregates(array $usernames): array
    {
        $aggregates = [];

        foreach (DB::table('tfa')
            ->whereIn('username', $usernames)
            ->whereIn('authmech', SecondFactorStatus::COUNTED)
            ->groupBy('username')
            ->selectRaw('LOWER(`username`) AS `account`, MAX(`active`) AS `any_active`')
            ->get() as $row) {
            $key = (string) $row->account;
            $aggregates[$key] = max($aggregates[$key] ?? 0, (int) $row->any_active === 1 ? 1 : 0);
        }

        return $aggregates;
    }

    /**
     * @param  list<string>  $usernames
     * @return array<string, int>
     */
    private function activePasskeyCounts(array $usernames): array
    {
        $counts = [];

        foreach (DB::table('fido2')
            ->whereIn('username', $usernames)
            ->where('active', 1)
            ->groupBy('username')
            ->selectRaw('LOWER(`username`) AS `account`, COUNT(*) AS `passkeys`')
            ->get() as $row) {
            $counts[(string) $row->account] = ($counts[(string) $row->account] ?? 0) + (int) $row->passkeys;
        }

        return $counts;
    }
}
