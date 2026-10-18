<?php

namespace App\Services\Resources;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Models\Mailcow\Resource;
use App\Services\Aliases\AliasAddress;
use App\Services\Audit\AuditLogger;
use App\Services\Domains\DomainPresenter;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\GroupwareRefresh;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Resource changes, legacy mailbox('edit', 'resource') (feature 004 FR-051, research R13): the
 * description (trimmed, not empty: a deviation, legacy accepted whitespace on change), kind,
 * booking rule and active state; the address and domain never change. In one transaction on the
 * locked row: the version check (`412`), the rules and read-only members (`422`), legacy's `UPDATE`
 * restricted to resource rows, the groupware entry in a savepoint (an inactive resource loses it),
 * the audit record. A change of nothing is audited with empty `changes`.
 */
class ResourceUpdater
{
    /** Read-only members of `ResourcePatch`; `etag` is ignored. */
    public const READ_ONLY = ['address', 'addressUnicode', 'domain', 'domainUnicode', 'createdAt', 'modifiedAt'];

    public function __construct(
        private readonly ResourceVersion $version,
        private readonly GroupwareRefresh $groupware,
        private readonly GroupwareCacheFlush $flush,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $patch  the sent members of `ResourcePatch`
     * @return bool whether anything changed
     */
    public function update(Principal $principal, string $address, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): bool
    {
        [$changed, $outcome] = DB::transaction(fn (): array => $this->apply($principal, $address, $patch, $ifMatch, $bulkRequestId), 3);

        if ($outcome === GroupwareOutcome::Done) {
            $this->flush->flush();
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{bool, GroupwareOutcome|null}
     */
    private function apply(Principal $principal, string $address, array $patch, ?string $ifMatch, ?string $bulkRequestId): array
    {
        $row = self::lockedRow($address);

        if (ETag::preconditionFails($ifMatch, $this->version->forRow($row))) {
            throw new PreconditionFailedHttpException('The resource was changed since it was read.');
        }

        $stored = (int) $row->multiple_bookings;
        $current = [
            'description' => (string) $row->name,
            'kind' => (string) $row->kind,
            'bookingRule' => Resource::bookingRule($stored),
            'bookingLimit' => Resource::bookingLimit($stored),
            'active' => (int) $row->active !== 0,
        ];
        $values = $current;
        $violations = new FieldViolations;

        if (array_key_exists('description', $patch)) {
            $values['description'] = ResourceRules::description($patch['description'], $violations) ?? $current['description'];
        }

        if (array_key_exists('kind', $patch)) {
            $values['kind'] = (string) $patch['kind'];
        }

        if (array_key_exists('active', $patch)) {
            $values['active'] = (bool) $patch['active'];
        }

        $bookings = $stored;

        if (array_key_exists('bookingRule', $patch) || array_key_exists('bookingLimit', $patch)) {
            $rule = (string) ($patch['bookingRule'] ?? $current['bookingRule']);
            $bookings = ResourceRules::bookings($rule, $patch['bookingLimit'] ?? null, array_key_exists('bookingLimit', $patch), $current['bookingLimit'], $violations) ?? $stored;
            $values['bookingRule'] = Resource::bookingRule($bookings);
            $values['bookingLimit'] = Resource::bookingLimit($bookings);
        }

        $this->immutables($patch, $row, $violations);
        $violations->throwIfAny();

        $changes = [];

        foreach ($current as $field => $old) {
            if ($values[$field] !== $old) {
                $changes[$field] = ['old' => $old, 'new' => $values[$field]];
            }
        }

        $outcome = null;
        $details = ['changes' => $changes === [] ? new stdClass : $changes];

        if ($changes !== []) {
            DB::table('mailbox')->where('username', $address)->where('kind', 'regexp', ResourceQuery::KIND_PATTERN)->update([
                'active' => isset($changes['active']) ? (int) $values['active'] : (int) $row->active,
                'name' => $values['description'],
                'kind' => $values['kind'],
                'multiple_bookings' => $bookings,
            ]);
            $outcome = $this->groupware->inSavepoint([$address]);
            $details['groupwareUpdate'] = $outcome->value;
        }

        if ($bulkRequestId !== null) {
            $details['bulkRequestId'] = $bulkRequestId;
        }

        $this->audit->record(AuditEventType::ResourceUpdate, true, $principal, ['type' => 'resource', 'id' => $address, 'name' => $values['description']], null, null, $details);

        return [$changes !== [], $outcome];
    }

    /** The resource row locked for a change; `404` for anything that is not a resource. */
    public static function lockedRow(string $address): stdClass
    {
        $row = DB::table('mailbox')->where('username', $address)->where('kind', 'regexp', ResourceQuery::KIND_PATTERN)->lockForUpdate()->first();

        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('The resource does not exist.');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function immutables(array $patch, object $row, FieldViolations $violations): void
    {
        $current = [
            'address' => (string) $row->username,
            'addressUnicode' => AliasAddress::unicodeOf((string) $row->username),
            'domain' => (string) $row->domain,
            'domainUnicode' => DomainName::toUnicode((string) $row->domain),
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
        ];

        foreach (array_intersect_key($patch, array_flip(self::READ_ONLY)) as $member => $value) {
            if ($value !== $current[$member]) {
                $violations->add($member, 'immutable', [], 'This member is read-only and differs from the current value.');
            }
        }
    }
}
