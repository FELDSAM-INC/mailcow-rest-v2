<?php

namespace App\Services\Domains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\SogoDirectory;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Domain edits, legacy mailbox('edit', 'domain') (FR-030–FR-034, research D15). In one transaction
 * on the locked row: the version check (`412`), the attribute permissions (`403`), the read-only
 * members (`immutable`), the business rules on the merged values (`422`, every violation; the
 * usage floors and the quota order only when the request changes one of their members), the
 * relay normalisation, the full `UPDATE` legacy issues (without the relayhost, which feature 009
 * owns), the tag set replacement and the audit record with each change's old and new value. A
 * patch that changes nothing writes nothing but is audited with empty changes. After the commit the
 * groupware directory is rebuilt, unless nothing changed or the bulk caller defers it.
 *
 * The default mailbox quota (`defquota`) is not editable (owner decision 2026-10-18). A change of
 * the maximum mailbox quota lowers a larger stored default to it, so that legacy's edit form keeps
 * accepting the domain; this is not one of the audited changes.
 */
class DomainUpdater
{
    private const MIB = 1048576;

    /** Editable attribute => `domain` column (tags are `tags_domain` rows). */
    public const COLUMNS = [
        'description' => 'description',
        'active' => 'active',
        'maxMailboxes' => 'mailboxes',
        'maxAliases' => 'aliases',
        'maxMailboxQuota' => 'maxquota',
        'domainQuota' => 'quota',
        'backupMx' => 'backupmx',
        'relayAllRecipients' => 'relay_all_recipients',
        'relayUnknownOnly' => 'relay_unknown_only',
        'globalAddressList' => 'gal',
    ];

    public const READ_ONLY = ['name', 'unicodeName', 'relayhost', 'rateLimit', 'createdAt', 'modifiedAt', 'usage', 'footer', 'domainAdmins', 'etag'];

    private const LIMITS = ['maxMailboxes', 'maxAliases', 'maxMailboxQuota', 'domainQuota'];

    public function __construct(
        private readonly DomainAttributeGuard $guard,
        private readonly DomainRules $rules,
        private readonly DomainUsageCalculator $usage,
        private readonly DomainPresenter $presenter,
        private readonly DomainVersion $version,
        private readonly SogoDirectory $sogo,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $patch  the sent members of `DomainPatch`
     * @param  string|null  $ifMatch  the `If-Match` value (or a bulk item's version as one)
     * @return bool whether anything changed
     */
    public function update(Principal $principal, string $name, array $patch, ?string $ifMatch, ?string $bulkRequestId = null, bool $deferRebuild = false): bool
    {
        $changed = DB::transaction(function () use ($principal, $name, $patch, $ifMatch, $bulkRequestId): bool {
            $row = DB::table('domain')->where('domain', $name)->lockForUpdate()->first();

            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('The domain does not exist.');
            }

            $tags = DB::table('tags_domain')->where('domain', $name)->lockForUpdate()->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();

            if (ETag::preconditionFails($ifMatch, $this->version->forDomain($row, $tags))) {
                throw new PreconditionFailedHttpException('The domain was changed since it was read.');
            }

            $current = self::values($row, $tags);
            $requested = $this->requested($patch, $current, $name);
            $changedAttributes = array_keys(array_filter($requested['changed']));
            $this->guard->authorize($principal, $changedAttributes);

            $violations = new FieldViolations;
            $this->immutables($patch, $row, $principal, $violations);

            $usage = array_intersect(self::LIMITS, $changedAttributes) === [] ? null : $this->usage->forDomain($name);
            $values = $this->rules->check($requested['values'], $violations, $tags, $usage, $changedAttributes);
            $violations->throwIfAny();

            $changes = self::changes($current, $values);

            if ($changes !== []) {
                $defquota = (int) $row->defquota;

                if (isset($changes['maxMailboxQuota'])) {
                    $defquota = min($defquota, intdiv($values['maxMailboxQuota'], self::MIB));
                }

                $this->write($name, $values, $defquota, isset($changes['tags']));
            }

            $details = ['changes' => $changes === [] ? new stdClass : $changes];

            if ($bulkRequestId !== null) {
                $details['bulkRequestId'] = $bulkRequestId;
            }

            $this->audit->record(AuditEventType::DomainUpdate, true, $principal, ['type' => 'domain', 'id' => $name, 'name' => DomainName::toUnicode($name)], null, null, $details);

            return $changes !== [];
        });

        if ($changed && ! $deferRebuild) {
            $this->sogo->rebuild();
        }

        return $changed;
    }

    /**
     * The editable values of a stored domain, in contract names (quotas in bytes).
     *
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public static function values(object $row, array $tags): array
    {
        return [
            'description' => (string) ($row->description ?? ''),
            'active' => (bool) $row->active,
            'maxMailboxes' => (int) $row->mailboxes,
            'maxAliases' => (int) $row->aliases,
            'maxMailboxQuota' => (int) $row->maxquota * self::MIB,
            'domainQuota' => (int) $row->quota * self::MIB,
            'backupMx' => (bool) $row->backupmx,
            'relayAllRecipients' => (bool) $row->relay_all_recipients,
            'relayUnknownOnly' => (bool) $row->relay_unknown_only,
            'globalAddressList' => (bool) $row->gal,
            'tags' => DomainTagSet::sort($tags),
        ];
    }

    /**
     * The merged values and which attributes the request changes. Unchanged values are always
     * accepted, so a client may send back a whole representation.
     *
     * @param  array<string, mixed>  $patch
     * @param  array<string, mixed>  $current
     * @return array{values: array<string, mixed>, changed: array<string, bool>}
     */
    private function requested(array $patch, array $current, string $name): array
    {
        $values = $current;
        $changed = [];

        foreach (array_keys(self::COLUMNS) as $attribute) {
            if (! array_key_exists($attribute, $patch)) {
                continue;
            }

            $value = $patch[$attribute];

            if ($attribute === 'description') {
                $sent = $value === null ? '' : (string) $value;
                $value = $sent === '' ? $name : $sent;
                $changed[$attribute] = $sent !== $current['description'] && $value !== $current['description'];
            } elseif (is_bool($current[$attribute])) {
                $value = (bool) $value;
                $changed[$attribute] = $value !== $current[$attribute];
            } else {
                $changed[$attribute] = $value !== $current[$attribute];
            }

            $values[$attribute] = $value;
        }

        if (array_key_exists('tags', $patch)) {
            $values['tags'] = (array) $patch['tags'];
            $sent = array_values(array_unique(array_map(fn (mixed $t): string => trim((string) (is_scalar($t) ? $t : '')), $values['tags'])));
            $changed['tags'] = ! DomainTagSet::equal($sent, $current['tags']) || count($sent) !== count(array_unique(array_map('mb_strtolower', $sent)));
        }

        return ['values' => $values, 'changed' => $changed];
    }

    /**
     * Read-only members are accepted when equal to the current value, otherwise `immutable`.
     *
     * @param  array<string, mixed>  $patch
     */
    private function immutables(array $patch, object $row, Principal $principal, FieldViolations $violations): void
    {
        $sent = array_intersect_key($patch, array_flip(self::READ_ONLY));

        if ($sent === []) {
            return;
        }

        $name = (string) $row->domain;
        $current = $this->presenter->presentOne($row, $principal);

        foreach ($sent as $member => $value) {
            $equal = match ($member) {
                'name' => is_string($value) && DomainName::normalize($value) === $name,
                default => self::canonical($value) === self::canonical($current[$member] ?? null),
            };

            if (! $equal) {
                $violations->add($member, 'immutable', [], 'This member is read-only and differs from the current value.');
            }
        }
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            $value = array_map(fn (mixed $v): string => self::canonical($v), $value);
        }

        return (string) json_encode($value);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private static function changes(array $current, array $values): array
    {
        $changes = [];
        $values['tags'] = DomainTagSet::sort($values['tags']);

        foreach ($current as $attribute => $old) {
            if ($values[$attribute] !== $old) {
                $changes[$attribute] = ['old' => $old, 'new' => $values[$attribute]];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  int  $defquota  the default mailbox quota to keep for legacy, MiB
     */
    private function write(string $name, array $values, int $defquota, bool $tagsChanged): void
    {
        DB::table('domain')->where('domain', $name)->update([
            'relay_all_recipients' => (int) $values['relayAllRecipients'],
            'relay_unknown_only' => (int) $values['relayUnknownOnly'],
            'backupmx' => (int) $values['backupMx'],
            'gal' => (int) $values['globalAddressList'],
            'active' => (int) $values['active'],
            'quota' => intdiv($values['domainQuota'], self::MIB),
            'defquota' => $defquota,
            'maxquota' => intdiv($values['maxMailboxQuota'], self::MIB),
            'mailboxes' => $values['maxMailboxes'],
            'aliases' => $values['maxAliases'],
            'description' => $values['description'],
        ]);

        if ($tagsChanged) {
            DB::table('tags_domain')->where('domain', $name)->delete();

            foreach ($values['tags'] as $tag) {
                DB::table('tags_domain')->insert(['domain' => $name, 'tag_name' => $tag]);
            }
        }
    }
}
