<?php

namespace App\Services\Mailboxes;

use App\Support\Http\ETag;
use App\Support\Tags\TagSet;
use Illuminate\Support\Facades\DB;

/**
 * Version tags of mailboxes and mailbox templates (feature 003 FR-038, research R11): 001's strong
 * ETag over the stored settings only, so that single reads, list entries, bulk items and the
 * deletion preview carry the same tag, and a change made by the legacy UI changes it.
 *
 *  - Mailbox: the `mailbox` row's settings (address, name, quota, status, authentication source,
 *    the decoded attributes and custom attributes), its `user_acl` row and its tags sorted without
 *    regard to letter case; never the password, usage figures, last logins, shared-store values or
 *    the modification time.
 *  - Template: the ID, the name and the decoded attributes.
 */
class MailboxVersion
{
    /**
     * @param  list<string>  $tags
     */
    public function forMailbox(object $row, ?object $userAcl, array $tags): string
    {
        $acl = [];

        foreach (UserPermissions::COLUMNS as $column) {
            $acl[$column] = $userAcl === null ? null : (int) ($userAcl->{$column} ?? 0);
        }

        return ETag::of([
            'mailbox',
            (string) $row->username,
            $row->name,
            (int) $row->quota,
            (int) $row->active,
            $row->authsource,
            self::decoded($row->attributes ?? null),
            self::decoded($row->custom_attributes ?? null),
            $acl,
            TagSet::sort($tags),
        ]);
    }

    /** The tag of a stored mailbox (not a resource), or null when there is none. */
    public function ofMailbox(string $address): ?string
    {
        $row = DB::table('mailbox')->where('username', $address)->where('kind', '')->first();

        if ($row === null) {
            return null;
        }

        return $this->forMailbox(
            $row,
            DB::table('user_acl')->where('username', $address)->first(),
            DB::table('tags_mailbox')->where('username', $address)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all(),
        );
    }

    public function forTemplate(object $row): string
    {
        return ETag::of(['mailboxTemplate', (int) $row->id, (string) $row->template, self::decoded($row->attributes ?? null)]);
    }

    public function ofTemplate(int $id): ?string
    {
        $row = DB::table('templates')->where('type', 'mailbox')->where('id', $id)->first();

        return $row === null ? null : $this->forTemplate($row);
    }

    /**
     * A JSON column decoded with recursively sorted keys; text that does not decode stays text.
     */
    private static function decoded(mixed $json): mixed
    {
        if (! is_string($json)) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? self::sortKeys($decoded) : $json;
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
