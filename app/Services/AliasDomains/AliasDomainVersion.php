<?php

namespace App\Services\AliasDomains;

use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;

/**
 * Version tags of alias domains (feature 004 FR-060, research R18): over the name, the target and
 * the active state only, never the alias count, the target's relay state or the rate limit.
 */
class AliasDomainVersion
{
    public function forRow(object $row): string
    {
        return ETag::of(['aliasDomain', (string) $row->alias_domain, (string) $row->target_domain, (int) $row->active]);
    }

    public function of(string $aliasDomain): ?string
    {
        $row = DB::table('alias_domain')->where('alias_domain', $aliasDomain)->first();

        return $row === null ? null : $this->forRow($row);
    }
}
