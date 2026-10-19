<?php

namespace App\Services\Quarantine;

use Illuminate\Support\Str;

/**
 * Safe file names of quarantine downloads (feature 006 research R6, FR-016, FR-017):
 *
 *  - an attachment keeps its declared name with path separators, NUL and control characters
 *    removed and trimmed, not shortened (legacy cuts it to 20 characters), or
 *    `attachment-<position>` when nothing is left;
 *  - the whole message is named after the subject as legacy derives it (tags stripped, as
 *    FILTER_SANITIZE_STRING did; quotes, separators and control characters removed; at most 30
 *    characters, multi-byte safe), or after the queue identifier, with `.eml`;
 *  - the `Content-Disposition` always says `attachment`, with an ASCII `filename` and the UTF-8
 *    `filename*` of RFC 8187.
 */
final class DownloadFileName
{
    public static function forAttachment(string $name, int $position): string
    {
        $safe = trim((string) preg_replace('/[\/\\\\\x00-\x1F\x7F]/u', '', $name));

        return $safe === '' ? 'attachment-'.$position : $safe;
    }

    public static function forMessage(?string $subject, string $qid): string
    {
        $name = trim(strip_tags((string) $subject));
        $name = trim((string) preg_replace('/["\'\/\\\\\x00-\x1F\x7F]/u', '', $name));
        $name = trim(mb_substr($name, 0, 30));

        return ($name === '' ? $qid : $name).'.eml';
    }

    public static function disposition(string $name): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/', '_', Str::ascii($name));
        $ascii = trim(str_replace(['"', '\\', '%', '/'], '_', $ascii));

        if ($ascii === '') {
            $ascii = 'download';
        }

        return 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name);
    }
}
