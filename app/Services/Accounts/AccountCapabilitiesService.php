<?php

namespace App\Services\Accounts;

use App\Rules\AccountUsername;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;

/**
 * The account capabilities (feature 007 FR-077, research R12, contract `AccountCapabilities`): the
 * permission flags a new domain administrator gets when they are not sent, read on every request
 * from the column defaults of the server's `da_acl` table (they can differ between supported mailcow
 * releases), the defaults of a new account, the bulk limit, the page sizes, the username length
 * limit and the single sign-on token lifetime. Changes nothing.
 */
class AccountCapabilitiesService
{
    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return [
            'domainAdminPermissionDefaults' => $this->permissionDefaults(),
            'newAccountDefaults' => ['active' => true, 'forcePasswordChange' => false, 'forceSecondFactorSetup' => false],
            'bulkLimit' => (int) config('restv2.account_bulk_limit', 100),
            'pageSize' => ['default' => ListQuery::DEFAULT_LIMIT, 'maximum' => ListQuery::MAX_LIMIT],
            'usernameMaxLength' => AccountUsername::MAX_LENGTH,
            'ssoTokenLifetime' => (int) config('restv2.sso_token_lifetime', 30),
        ];
    }

    /**
     * MariaDB reports column defaults as quoted literals (`'1'`) or bare numbers.
     *
     * @return array<string, bool>
     */
    public function permissionDefaults(): array
    {
        $defaults = DB::table('information_schema.COLUMNS')
            ->whereRaw('`TABLE_SCHEMA` = DATABASE()')
            ->where('TABLE_NAME', 'da_acl')
            ->pluck('COLUMN_DEFAULT', 'COLUMN_NAME')
            ->all();
        $flags = [];

        foreach (DomainAdminFlags::COLUMNS as $name => $column) {
            $flags[$name] = (int) trim((string) ($defaults[$column] ?? '0'), "'") === 1;
        }

        return $flags;
    }
}
