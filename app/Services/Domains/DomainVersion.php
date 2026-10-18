<?php

namespace App\Services\Domains;

use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;

/**
 * Version tags of domains, domain footers and domain templates (FR-041, research D7): 001's strong
 * ETag over the stored settings only. The same function serves single reads, list entries, bulk
 * item checks and the deletion preview, so the tags are equal everywhere.
 *
 *  - Domain: every stored setting, `created` (which distinguishes a domain from an earlier one of
 *    the same name) and the tags sorted without regard to letter case; never usage figures, the
 *    rate limit (Redis, feature 006) or the domain administrators (feature 007).
 *  - Footer: the texts, the reply flag and both exclusion lists; an absent row equals an empty
 *    footer.
 *  - Template: the ID, the name and the attributes JSON with sorted keys.
 */
class DomainVersion
{
    /**
     * @param  list<string>  $tags
     */
    public function forDomain(object $row, array $tags): string
    {
        return ETag::of([
            'domain',
            (string) $row->domain,
            $row->description,
            (int) $row->aliases,
            (int) $row->mailboxes,
            (int) $row->defquota,
            (int) $row->maxquota,
            (int) $row->quota,
            (string) $row->relayhost,
            (int) $row->backupmx,
            (int) $row->gal,
            (int) $row->relay_all_recipients,
            (int) $row->relay_unknown_only,
            (int) $row->active,
            (string) $row->created,
            DomainTagSet::sort($tags),
        ]);
    }

    /** The tag of a stored domain, or null when it does not exist. */
    public function ofDomain(string $domain): ?string
    {
        $row = DB::table('domain')->where('domain', $domain)->first();

        if ($row === null) {
            return null;
        }

        $tags = DB::table('tags_domain')->where('domain', $domain)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();

        return $this->forDomain($row, $tags);
    }

    public function forFooter(string $domain, ?object $row): string
    {
        return ETag::of([
            'domainFooter',
            $domain,
            (string) ($row->html ?? ''),
            (string) ($row->plain ?? ''),
            (int) ($row->skip_replies ?? 0),
            self::list($row->mbox_exclude ?? null),
            self::list($row->alias_domain_exclude ?? null),
        ]);
    }

    public function ofFooter(string $domain): string
    {
        return $this->forFooter($domain, DB::table('domain_wide_footer')->where('domain', $domain)->first());
    }

    public function forTemplate(object $row): string
    {
        $attributes = json_decode((string) ($row->attributes ?? ''), true);

        return ETag::of([
            'domainTemplate',
            (int) $row->id,
            (string) $row->template,
            is_array($attributes) ? self::sortKeys($attributes) : (string) ($row->attributes ?? ''),
        ]);
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $v): mixed => is_array($v) ? self::sortKeys($v) : $v, $value);
    }
}
