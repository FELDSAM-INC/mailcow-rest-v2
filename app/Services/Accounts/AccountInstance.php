<?php

namespace App\Services\Accounts;

use App\Auth\AccountKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An opaque identity for one upstream account row. Foreign-key cascades remove it on deletion
 * and move its username on rename, including writes made by legacy mailcow. Unlike `created`,
 * this identity cannot be inherited by an account recreated within the same second.
 */
class AccountInstance
{
    public function bind(AccountKind $kind, string $username): ?string
    {
        return DB::transaction(function () use ($kind, $username): ?string {
            $owner = DB::table($kind->value)->where('username', $username)->lockForUpdate()->value('username');
            if (! is_string($owner)) {
                return null;
            }

            $column = self::column($kind);
            DB::table('restv2_account_instances')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                $column => $owner,
            ]);

            $id = DB::table('restv2_account_instances')->where($column, $owner)->value('id');

            return is_string($id) ? $id : null;
        });
    }

    public function matches(AccountKind $kind, string $username, string $id): bool
    {
        return DB::table('restv2_account_instances')->where('id', $id)
            ->where(self::column($kind), $username)->exists();
    }

    private static function column(AccountKind $kind): string
    {
        return $kind === AccountKind::Admin ? 'admin_username' : 'mailbox_username';
    }
}
