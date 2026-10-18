<?php

namespace App\Services\Aliases;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\GroupwareRefresh;
use App\Services\Mailboxes\AddressAvailability;
use App\Services\Mailboxes\AddressLock;
use App\Support\Http\FieldViolations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Alias creation, legacy mailbox('add', 'alias') for one address (feature 004 FR-020–FR-028,
 * FR-032–FR-034, research R3–R9):
 *
 *  1. normalise and check every field and entry, collecting all violations (`422`), the external
 *     destinations disabled by the server setting included;
 *  2. the `aliasExternalGoto` permission for external destinations of domain administrators (`403`);
 *  3. under 003's address lock, in one transaction: lock the primary domain row, check that the
 *     address is free (`409 address-in-use`) and that the alias limit has room
 *     (`409 domain-alias-limit-reached`), insert with legacy's column list, remove the explicit
 *     sender permissions the alias makes redundant, refresh the destinations' groupware entries in
 *     a savepoint, write the audit record;
 *  4. after the commit, flush the groupware cache.
 *
 * Copies over alias domains use it too, as creations by the caller (FR-037).
 */
class AliasCreator
{
    public function __construct(
        private readonly AliasRules $rules,
        private readonly AliasLimit $limit,
        private readonly AliasAddressAvailability $availability,
        private readonly AliasSenderAclEffects $senderAcl,
        private readonly AddressLock $locks,
        private readonly GroupwareRefresh $groupware,
        private readonly GroupwareCacheFlush $flush,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the sent members of `AliasCreateRequest`
     * @return int the identifier of the new alias
     */
    public function create(Principal $principal, array $input, ?string $bulkRequestId = null, ?int $copiedFromAliasId = null): int
    {
        $violations = new FieldViolations;
        $address = $this->rules->address($principal, $input['address'] ?? null, $violations);
        $indexes = [];
        // A creation always has a destination: without one, `destinations` is required.
        $destination = $this->rules->destination($input + ['destinationType' => AliasDestination::ADDRESSES], null, $violations, $indexes);
        $nothingStored = AliasDestination::addresses([]);

        foreach (['publicComment', 'privateComment'] as $field) {
            $this->rules->comment($field, $input[$field] ?? null, $violations);
        }

        if ($address !== null && $destination !== null) {
            $this->rules->checkDestinations($destination, $address->address, $indexes, $nothingStored, $violations);
        }

        $violations->throwIfAny();
        assert($address !== null && $destination !== null);

        $this->rules->authorizeExternal($principal, $destination, $nothingStored, $indexes);

        $values = [
            'address' => $address->address,
            'destinationType' => $destination->type,
            'destinations' => $destination->addresses,
            'active' => (bool) ($input['active'] ?? true),
            'senderAllowed' => (bool) ($input['senderAllowed'] ?? true),
            'groupwareVisible' => (bool) ($input['groupwareVisible'] ?? true),
            'internal' => (bool) ($input['internal'] ?? false),
            'publicComment' => (string) ($input['publicComment'] ?? ''),
            'privateComment' => (string) ($input['privateComment'] ?? ''),
        ];
        $primary = AliasLimit::primaryOf($address->domain);

        [$id, $outcome] = $this->locks->run([$address->address], fn (): array => DB::transaction(
            fn (): array => $this->store($principal, $address, $destination, $values, $primary, $bulkRequestId, $copiedFromAliasId),
            3,
        ));

        if ($outcome === GroupwareOutcome::Done) {
            $this->flush->flush();
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{int, GroupwareOutcome}
     */
    private function store(Principal $principal, AliasAddress $address, AliasDestination $destination, array $values, string $primary, ?string $bulkRequestId, ?int $copiedFromAliasId): array
    {
        $this->limit->lockPrimaryDomain($primary);
        $this->availability->assertFree($address->address);
        $this->limit->assertRoom($primary, 1);

        try {
            $id = (int) DB::table('alias')->insertGetId([
                'address' => $address->address,
                'public_comment' => $values['publicComment'],
                'private_comment' => $values['privateComment'],
                'goto' => $destination->toGoto(),
                'domain' => $address->domain,
                'sogo_visible' => (int) $values['groupwareVisible'],
                'internal' => (int) $values['internal'],
                'sender_allowed' => (int) $values['senderAllowed'],
                'active' => (int) $values['active'],
            ]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw AddressAvailability::inUse($address->address, 'alias');
            }

            throw $e;
        }

        $this->senderAcl->afterSave($address->address, $destination, $values['senderAllowed']);
        $outcome = $this->groupware->inSavepoint($destination->mailboxCandidates());

        $details = ['values' => $values, 'groupwareUpdate' => $outcome->value];

        if ($copiedFromAliasId !== null) {
            $details['copiedFromAliasId'] = $copiedFromAliasId;
        }

        if ($bulkRequestId !== null) {
            $details['bulkRequestId'] = $bulkRequestId;
        }

        $this->audit->record(AuditEventType::AliasCreation, true, $principal, ['type' => 'alias', 'id' => (string) $id, 'name' => $address->address], null, null, $details);

        return [$id, $outcome];
    }
}
