<?php

namespace App\Services\Aliases;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\Domains\DomainPresenter;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\GroupwareRefresh;
use App\Services\Mailboxes\AddressLock;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Alias changes, legacy mailbox('edit', 'alias') (feature 004 FR-025–FR-034, FR-060, FR-061,
 * research R4–R9), JSON Merge Patch semantics. Under 003's address lock of a new address, in one
 * transaction on the locked row:
 *
 *  1. the version check (`412`);
 *  2. the rules of creation on the merged values, collecting every violation (`422`): a new address
 *     in an existing domain in scope (an orphaned alias must move), a replaced destination list
 *     valid in every entry, changed comments, read-only members equal to the current or resulting
 *     value (`immutable`);
 *  3. `aliasExternalGoto` for the external destinations the request adds (`403`); destinations
 *     already on the alias are never re-checked (clarification 2026-10-18);
 *  4. a new address must be free (its own current address excepted) and, in another primary
 *     domain, fit its alias limit (`409`);
 *  5. legacy's `UPDATE`, the sender permissions carried over and made redundant, the groupware
 *     entries of the former and new destinations in a savepoint, the audit record with every
 *     change's old and new value. A change of nothing is audited with empty `changes`.
 */
class AliasUpdater
{
    /** Read-only members of `AliasPatch`; `etag` is ignored. */
    public const READ_ONLY = ['id', 'addressUnicode', 'kind', 'domain', 'domainUnicode', 'inAliasDomain', 'primaryDomain', 'primaryDomainUnicode', 'createdAt', 'modifiedAt', 'orphaned'];

    public function __construct(
        private readonly AliasRules $rules,
        private readonly AliasLimit $limit,
        private readonly AliasAddressAvailability $availability,
        private readonly AliasSenderAclEffects $senderAcl,
        private readonly AliasVersion $version,
        private readonly AddressLock $locks,
        private readonly GroupwareRefresh $groupware,
        private readonly GroupwareCacheFlush $flush,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $patch  the sent members of `AliasPatch`
     * @return bool whether anything changed
     */
    public function update(Principal $principal, int $id, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): bool
    {
        $newAddress = is_string($patch['address'] ?? null) ? AliasAddress::parse($patch['address'])?->address : null;
        $run = fn (): array => DB::transaction(fn (): array => $this->apply($principal, $id, $patch, $ifMatch, $bulkRequestId), 3);
        [$changed, $outcome] = $newAddress === null ? $run() : $this->locks->run([$newAddress], $run);

        if ($outcome === GroupwareOutcome::Done) {
            $this->flush->flush();
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{bool, GroupwareOutcome|null}
     */
    private function apply(Principal $principal, int $id, array $patch, ?string $ifMatch, ?string $bulkRequestId): array
    {
        $row = self::lockedRow($id);

        if (ETag::preconditionFails($ifMatch, $this->version->forRow($row))) {
            throw new PreconditionFailedHttpException('The alias was changed since it was read.');
        }

        $current = self::values($row);
        $storedDestination = AliasDestination::fromGoto((string) $row->goto);
        $violations = new FieldViolations;

        // Address: a sent address meets the rules of creation; an orphaned alias must move.
        $address = AliasAddress::parse((string) $row->address);
        $addressChanged = false;

        if (array_key_exists('address', $patch)) {
            $address = $this->rules->address($principal, $patch['address'], $violations);
            $addressChanged = $address !== null && $address->address !== (string) $row->address;
        } elseif (self::orphaned((string) $row->domain)) {
            $violations->add('address', 'notFound', ['resource' => 'domain'], 'The alias\'s domain no longer exists; move it to an existing domain.');
        }

        // Destination: sending destinations or a special action replaces the whole destination.
        $indexes = [];
        $sentDestination = $this->rules->destination($patch, $storedDestination, $violations, $indexes);
        $destination = $sentDestination ?? $storedDestination;
        $replaced = $sentDestination !== null && (array_key_exists('destinations', $patch) || $sentDestination->type !== $storedDestination->type);

        if ($address !== null && $replaced) {
            $this->rules->checkDestinations($destination, $address->address, $indexes, $storedDestination, $violations);
        } elseif ($address !== null && $addressChanged && in_array($address->key(), array_map('mb_strtolower', $destination->addresses), true)) {
            $violations->add('address', 'conflictsWith', ['field' => 'destinations', 'value' => $address->address], 'The address is one of the alias\'s destinations.');
        }

        foreach (['publicComment', 'privateComment'] as $field) {
            if (array_key_exists($field, $patch) && $patch[$field] !== $current[$field]) {
                $this->rules->comment($field, $patch[$field], $violations);
            }
        }

        $this->immutables($patch, $row, $address, $violations);
        $violations->throwIfAny();
        assert($address !== null);

        if ($replaced) {
            $this->rules->authorizeExternal($principal, $destination, $storedDestination, $indexes);
        }

        if ($addressChanged) {
            $this->availability->assertFree($address->address, (string) $row->address);
            $oldPrimary = AliasLimit::primaryOf((string) $row->domain);
            $newPrimary = AliasLimit::primaryOf($address->domain);

            if (mb_strtolower($oldPrimary) !== mb_strtolower($newPrimary)) {
                $this->limit->lockPrimaryDomain($newPrimary);
                $this->limit->assertRoom($newPrimary, 1);
            }
        }

        $values = self::merge($current, $patch, $address, $destination);
        $changes = self::changes($current, $values);
        $outcome = null;

        if ($changes !== []) {
            DB::table('alias')->where('id', $id)->update([
                'address' => $values['address'],
                'public_comment' => self::raw($row->public_comment, $current['publicComment'], $values['publicComment']),
                'private_comment' => self::raw($row->private_comment, $current['privateComment'], $values['privateComment']),
                'domain' => $address->domain,
                'goto' => $destination->toGoto(),
                'sogo_visible' => self::flag($row->sogo_visible, $current['groupwareVisible'], $values['groupwareVisible']),
                'internal' => self::flag($row->internal, $current['internal'], $values['internal']),
                'sender_allowed' => self::flag($row->sender_allowed, $current['senderAllowed'], $values['senderAllowed']),
                'active' => self::flag($row->active, $current['active'], $values['active']),
            ]);

            if ($addressChanged) {
                $this->senderAcl->moved((string) $row->address, $address->address);
            }
        }

        $this->senderAcl->afterSave($address->address, $destination, $values['senderAllowed']);

        $details = ['changes' => $changes === [] ? new stdClass : $changes];

        if ($changes !== []) {
            $outcome = $this->groupware->inSavepoint([...$storedDestination->mailboxCandidates(), ...$destination->mailboxCandidates()]);
            $details['groupwareUpdate'] = $outcome->value;
        }

        if ($bulkRequestId !== null) {
            $details['bulkRequestId'] = $bulkRequestId;
        }

        $this->audit->record(AuditEventType::AliasUpdate, true, $principal, ['type' => 'alias', 'id' => (string) $id, 'name' => $values['address']], null, null, $details);

        return [$changes !== [], $outcome];
    }

    /**
     * The alias row locked for the change; `404` for a missing row and for a mailbox's own row.
     */
    public static function lockedRow(int $id): stdClass
    {
        $row = DB::table('alias')->where('id', $id)->lockForUpdate()->first();

        if (! $row instanceof stdClass || DB::table('mailbox')->where('username', $row->address)->exists()) {
            throw new NotFoundHttpException('The alias does not exist.');
        }

        return $row;
    }

    /**
     * The changeable values of a stored alias, in contract names.
     *
     * @return array<string, mixed>
     */
    public static function values(object $row): array
    {
        $destination = AliasDestination::fromGoto((string) $row->goto);

        return [
            'address' => (string) $row->address,
            'destinationType' => $destination->type,
            'destinations' => $destination->addresses,
            'active' => (int) $row->active !== 0,
            'senderAllowed' => (int) $row->sender_allowed !== 0,
            'groupwareVisible' => (int) $row->sogo_visible !== 0,
            'internal' => (int) $row->internal !== 0,
            'publicComment' => (string) ($row->public_comment ?? ''),
            'privateComment' => (string) ($row->private_comment ?? ''),
        ];
    }

    private static function orphaned(string $domain): bool
    {
        return ! DB::table('domain')->where('domain', $domain)->exists() && ! DB::table('alias_domain')->where('alias_domain', $domain)->exists();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private static function merge(array $current, array $patch, AliasAddress $address, AliasDestination $destination): array
    {
        $values = $current;
        $values['address'] = $address->address;
        $values['destinationType'] = $destination->type;
        $values['destinations'] = $destination->addresses;

        foreach (['active', 'senderAllowed', 'groupwareVisible', 'internal'] as $flag) {
            if (array_key_exists($flag, $patch)) {
                $values[$flag] = (bool) $patch[$flag];
            }
        }

        foreach (['publicComment', 'privateComment'] as $comment) {
            if (array_key_exists($comment, $patch) && is_string($patch[$comment])) {
                $values[$comment] = $patch[$comment];
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private static function changes(array $current, array $values): array
    {
        $changes = [];

        foreach ($current as $field => $old) {
            if ($values[$field] !== $old) {
                $changes[$field] = ['old' => $old, 'new' => $values[$field]];
            }
        }

        return $changes;
    }

    /** A flag column: the stored value when unchanged (legacy may store 2), else 0 or 1. */
    private static function flag(mixed $stored, bool $current, bool $new): int
    {
        return $current === $new ? (int) $stored : (int) $new;
    }

    /** A comment column: the stored value (NULL kept) when unchanged, else the new text. */
    private static function raw(mixed $stored, string $current, string $new): ?string
    {
        return $current === $new ? ($stored === null ? null : (string) $stored) : $new;
    }

    /**
     * Read-only members are accepted when equal to the current value, or, for those that follow
     * from the address, to the value of the resulting address; otherwise `immutable`.
     *
     * @param  array<string, mixed>  $patch
     */
    private function immutables(array $patch, object $row, ?AliasAddress $address, FieldViolations $violations): void
    {
        $sent = array_intersect_key($patch, array_flip(self::READ_ONLY));

        if ($sent === []) {
            return;
        }

        $resulting = $address === null ? null : self::derived($address);
        $fixed = [
            'id' => (int) $row->id,
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
        ];

        foreach ($sent as $member => $value) {
            if (array_key_exists($member, $fixed)) {
                $equal = $value === $fixed[$member];
            } elseif ($resulting === null) {
                continue;
            } else {
                $equal = $value === $resulting[$member];
            }

            if (! $equal) {
                $violations->add($member, 'immutable', [], 'This member is read-only and differs from the current value.');
            }
        }
    }

    /**
     * The members that follow from an address.
     *
     * @return array<string, mixed>
     */
    private static function derived(AliasAddress $address): array
    {
        $target = DB::table('alias_domain')->where('alias_domain', $address->domain)->value('target_domain');
        $target = $target === null ? null : (string) $target;
        $primary = $target ?? $address->domain;

        return [
            'addressUnicode' => $address->unicode(),
            'kind' => $address->catchAll ? 'catchAll' : 'regular',
            'domain' => $address->domain,
            'domainUnicode' => DomainName::toUnicode($address->domain),
            'inAliasDomain' => $target !== null,
            'primaryDomain' => $primary,
            'primaryDomainUnicode' => DomainName::toUnicode($primary),
            'orphaned' => $target === null && ! DB::table('domain')->where('domain', $address->domain)->exists(),
        ];
    }
}
