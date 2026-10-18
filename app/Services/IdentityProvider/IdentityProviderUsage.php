<?php

namespace App\Services\IdentityProvider;

use App\Auth\IdentityProviderType;
use Illuminate\Support\Facades\DB;

/**
 * Mailboxes per external authentication source (FR-006, FR-015, FR-017): counted over every row
 * of `mailbox` as legacy's "authsource in use" checks select them (`authsource` not NULL and not
 * `mailcow`). A deletion is blocked by any of them, a save of another type by those of the other
 * types.
 */
class IdentityProviderUsage
{
    /**
     * @return array{keycloak: int, genericOidc: int, ldap: int}
     */
    public function counts(): array
    {
        $counts = ['keycloak' => 0, 'genericOidc' => 0, 'ldap' => 0];

        $rows = DB::table('mailbox')
            ->whereNotNull('authsource')
            ->where('authsource', '<>', 'mailcow')
            ->groupBy('authsource')
            ->selectRaw('`authsource`, COUNT(*) AS `n`')
            ->get();

        foreach ($rows as $row) {
            $type = IdentityProviderType::fromLegacy((string) $row->authsource);

            if ($type !== null) {
                $counts[$type->value] = (int) $row->n;
            }
        }

        return $counts;
    }

    /**
     * The number of mailboxes that block a save of the given type (null: a deletion).
     *
     * @param  array{keycloak: int, genericOidc: int, ldap: int}|null  $counts
     */
    public function blocking(?IdentityProviderType $newType, ?array $counts = null): int
    {
        $counts ??= $this->counts();

        if ($newType !== null) {
            unset($counts[$newType->value]);
        }

        return array_sum($counts);
    }
}
