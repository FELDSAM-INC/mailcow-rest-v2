<?php

namespace App\Services\AliasDomains;

use App\Support\Domain\DomainName;
use Illuminate\Support\Facades\DB;

/**
 * The deletion of one alias domain as feature 004 defines it (FR-045 there), implemented once for
 * both features (docs/conventions.md: cleanup that more than one feature performs is implemented
 * once; feature 002 research D19). Call it inside the caller's transaction; the DKIM key is kept.
 *
 *  - deleteRows(): the sender permissions for the alias domain's aliases and for `@<alias domain>`,
 *    its aliases, temporary aliases, BCC maps (`<a>` and `@<a>`, unlike legacy), spam allow and
 *    block lists, and the `alias_domain` row;
 *  - redisFields(): its `DOMAIN_MAP` and `RL_VALUE` fields, for the caller's MULTI.
 */
class AliasDomainCleanup
{
    public function deleteRows(string $aliasDomain): void
    {
        DB::table('sender_acl')
            ->where(fn ($q) => $q->whereIn('send_as', DB::table('alias')->select('address')->where('domain', $aliasDomain))
                ->orWhere('send_as', '@'.$aliasDomain))
            ->delete();
        DB::table('alias')->where('domain', $aliasDomain)->delete();
        DB::table('spamalias')->where('address', 'like', DomainName::addressPattern($aliasDomain))->delete();
        DB::table('bcc_maps')->whereIn('local_dest', [$aliasDomain, '@'.$aliasDomain])->delete();
        DB::table('filterconf')->where('object', $aliasDomain)->delete();
        DB::table('alias_domain')->where('alias_domain', $aliasDomain)->delete();
    }

    /**
     * @return array<string, list<string>> hash => fields
     */
    public function redisFields(string $aliasDomain): array
    {
        return ['DOMAIN_MAP' => [$aliasDomain], 'RL_VALUE' => [$aliasDomain]];
    }
}
