<?php

namespace App\Services\Aliases;

use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;

/**
 * Version tags of aliases (feature 004 FR-060, research R18): 001's strong ETag over the stored
 * settings of the `alias` row, raw values in a fixed order. Times are not settings and are left
 * out. Single reads, list entries and bulk items use the same function.
 */
class AliasVersion
{
    public function forRow(object $row): string
    {
        return ETag::of([
            'alias',
            (int) $row->id,
            (string) $row->address,
            (string) $row->goto,
            (string) $row->domain,
            (int) $row->active,
            (int) $row->sender_allowed,
            (int) $row->sogo_visible,
            (int) $row->internal,
            $row->public_comment === null ? null : (string) $row->public_comment,
            $row->private_comment === null ? null : (string) $row->private_comment,
        ]);
    }

    /** The tag of a stored alias, or null when it does not exist. */
    public function of(int $id): ?string
    {
        $row = DB::table('alias')->where('id', $id)->first();

        return $row === null ? null : $this->forRow($row);
    }
}
