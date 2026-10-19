<?php

namespace App\Services\Accounts;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The permission record of a domain administrator (`da_acl`, feature 007 FR-037 to FR-041,
 * research R12). Runs inside the caller's transaction.
 *
 *  - A new account gets a record with only the sent flags, so that every other flag takes the
 *    column default of the server's schema, as legacy's `INSERT INTO da_acl (username)` does and
 *    as the account capabilities report (FR-077). An orphaned record of the username is replaced.
 *  - A change updates only the sent flags (legacy replaced the whole set). Without a record it
 *    creates one with the sent flags and every other flag off (FR-041).
 */
class DomainAdminPermissionService
{
    /**
     * @param  array<string, bool>  $flags  contract names (validated)
     */
    public function insertForNewAccount(string $username, array $flags): void
    {
        DB::table('da_acl')->where('username', $username)->delete();
        DB::table('da_acl')->insert(['username' => $username] + self::columns($flags));
    }

    /**
     * Applies the sent flags and returns whether the stored record changed, with the flags whose
     * value changed as `{flag: {old, new}}` (every sent flag when the record was created).
     *
     * @param  array<string, bool>  $flags  contract names (validated)
     * @return array{changed: bool, changes: array<string, array{old: bool, new: bool}>}
     */
    public function apply(string $username, array $flags, ?stdClass $current): array
    {
        if ($flags === []) {
            return ['changed' => false, 'changes' => []];
        }

        if ($current === null) {
            $columns = array_fill_keys(array_values(DomainAdminFlags::COLUMNS), 0);
            DB::table('da_acl')->insert(['username' => $username] + array_merge($columns, self::columns($flags)));
            $changes = [];

            foreach ($flags as $name => $value) {
                $changes[$name] = ['old' => false, 'new' => $value];
            }

            ksort($changes);

            return ['changed' => true, 'changes' => $changes];
        }

        $old = DomainAdminFlags::fromRow($current);
        $changes = [];

        foreach ($flags as $name => $value) {
            if ($old[$name] !== $value) {
                $changes[$name] = ['old' => $old[$name], 'new' => $value];
            }
        }

        if ($changes !== []) {
            DB::table('da_acl')->where('username', (string) $current->username)->update(self::columns(array_map(fn (array $c): bool => $c['new'], $changes)));
        }

        ksort($changes);

        return ['changed' => $changes !== [], 'changes' => $changes];
    }

    /**
     * @param  array<string, bool>  $flags
     * @return array<string, int> column => 0|1
     */
    private static function columns(array $flags): array
    {
        $columns = [];

        foreach ($flags as $name => $value) {
            $column = DomainAdminFlags::column($name);

            if ($column !== null) {
                $columns[$column] = $value ? 1 : 0;
            }
        }

        return $columns;
    }
}
