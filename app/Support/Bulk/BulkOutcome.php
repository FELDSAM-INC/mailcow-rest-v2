<?php

namespace App\Support\Bulk;

/**
 * The outcome of one bulk item (contract `BulkItemOutcome`, docs/conventions.md "Bulk actions").
 */
enum BulkOutcome: string
{
    case Done = 'done';
    case NotFound = 'notFound';
    case Refused = 'refused';
    case Outdated = 'outdated';
    case Failed = 'failed';

    /**
     * The outcome of a refused item from the status of its problem: `404` not found, `412`
     * outdated, another `4xx` refused, anything else failed.
     */
    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 404 => self::NotFound,
            $status === 412 => self::Outdated,
            $status >= 400 && $status < 500 => self::Refused,
            default => self::Failed,
        };
    }
}
