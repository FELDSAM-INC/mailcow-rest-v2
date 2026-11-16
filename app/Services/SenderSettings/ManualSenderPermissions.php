<?php

namespace App\Services\SenderSettings;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Group grants are rebuilt by mailcow's group module and must not become manual grants. */
final class ManualSenderPermissions
{
    public static function query(): Builder
    {
        $query = DB::table('sender_acl');

        if (SchemaVersion::hasNovember2026Features()) {
            $query->whereNull('group_id');
        }

        return $query;
    }
}
