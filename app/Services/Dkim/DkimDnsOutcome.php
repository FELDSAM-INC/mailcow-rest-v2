<?php

namespace App\Services\Dkim;

/**
 * Outcome of the FeldHost DKIM DNS publication (contract `DkimDnsPublicationStatus`), plus
 * feature 002's `skipped` (no new key was generated, so nothing was published).
 */
enum DkimDnsOutcome: string
{
    case Published = 'published';
    case Removed = 'removed';
    case NoRecordToRemove = 'noRecordToRemove';
    case NoZone = 'noZone';
    case Failed = 'failed';
    case NotConfigured = 'notConfigured';
    case Skipped = 'skipped';
}
