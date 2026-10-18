<?php

namespace App\Services\Resources;

use App\Support\Http\ETag;

/**
 * Version tags of resources (feature 004 FR-060, research R18): over the address, description,
 * kind, booking rule, active state and domain of the `mailbox` row.
 */
class ResourceVersion
{
    public function forRow(object $row): string
    {
        return ETag::of([
            'resource',
            (string) $row->username,
            (string) $row->name,
            (string) $row->kind,
            (int) $row->multiple_bookings,
            (int) $row->active,
            (string) $row->domain,
        ]);
    }
}
