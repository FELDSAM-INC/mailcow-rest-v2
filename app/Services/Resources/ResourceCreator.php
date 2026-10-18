<?php

namespace App\Services\Resources;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Aliases\AliasAddressAvailability;
use App\Services\Audit\AuditLogger;
use App\Services\Domains\DomainScope;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\GroupwareRefresh;
use App\Services\Mailboxes\AddressAvailability;
use App\Services\Mailboxes\AddressLock;
use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Resource creation, legacy mailbox('add', 'resource') (feature 004 FR-048–FR-050, research R13):
 * the address derived from the description, the domain an existing domain in scope (alias domains
 * refused), every violation reported together (`422`); under 003's address lock, in one
 * transaction: the address must be free (`409 address-in-use`), legacy's `INSERT` (password
 * `RESOURCE`, quota 0, so it never counts as a mailbox), the groupware entry in a savepoint, the
 * audit record. The groupware cache is flushed after the commit.
 */
class ResourceCreator
{
    public function __construct(
        private readonly DomainScope $domains,
        private readonly AliasAddressAvailability $availability,
        private readonly AddressLock $locks,
        private readonly GroupwareRefresh $groupware,
        private readonly GroupwareCacheFlush $flush,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  the sent members of `ResourceCreateRequest`
     * @return string the address of the new resource
     */
    public function create(Principal $principal, array $input): string
    {
        $violations = new FieldViolations;
        $description = ResourceRules::description($input['description'] ?? null, $violations);
        $local = $description === null ? null : ResourceAddressDeriver::localPart($description);

        if ($local !== null && ! ResourceAddressDeriver::acceptable($local)) {
            $violations->add('description', 'invalidFormat', ['format' => 'resourceDescription'], 'The description must give 1 to 64 ASCII letters or digits for the address.');
            $local = null;
        }

        $domain = $this->domain($principal, $input['domain'] ?? null, $violations);
        $rule = (string) ($input['bookingRule'] ?? 'unlimited');
        $bookings = ResourceRules::bookings($rule, $input['bookingLimit'] ?? null, array_key_exists('bookingLimit', $input), null, $violations);
        $violations->throwIfAny();
        assert($description !== null && $local !== null && $domain !== null && $bookings !== null);

        $address = $local.'@'.$domain;
        $values = [
            'address' => $address,
            'description' => $description,
            'domain' => $domain,
            'kind' => (string) $input['kind'],
            'bookingRule' => $rule,
            'bookingLimit' => $bookings > 0 ? $bookings : null,
            'active' => (bool) ($input['active'] ?? true),
        ];

        $outcome = $this->locks->run([$address], fn (): GroupwareOutcome => DB::transaction(function () use ($principal, $address, $local, $values, $bookings): GroupwareOutcome {
            $this->availability->assertFree($address);

            try {
                DB::table('mailbox')->insert([
                    'username' => $address,
                    'password' => 'RESOURCE',
                    'name' => $values['description'],
                    'quota' => 0,
                    'local_part' => $local,
                    'domain' => $values['domain'],
                    'active' => (int) $values['active'],
                    'multiple_bookings' => $bookings,
                    'kind' => $values['kind'],
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw AddressAvailability::inUse($address, $this->availability->holderOf($address) ?? 'resource');
                }

                throw $e;
            }

            $outcome = $this->groupware->inSavepoint([$address]);
            $this->audit->record(AuditEventType::ResourceCreation, true, $principal, ['type' => 'resource', 'id' => $address, 'name' => $values['description']], null, null, ['values' => $values, 'groupwareUpdate' => $outcome->value]);

            return $outcome;
        }, 3));

        if ($outcome === GroupwareOutcome::Done) {
            $this->flush->flush();
        }

        return $address;
    }

    /** The resource's domain: an existing domain in the caller's scope, not an alias domain. */
    private function domain(Principal $principal, mixed $raw, FieldViolations $violations): ?string
    {
        $name = is_string($raw) ? DomainName::normalizeValid($raw) : null;
        $target = $name === null ? null : DB::table('alias_domain')->where('alias_domain', $name)->value('target_domain');

        if ($target !== null && $this->domains->resolve($principal, (string) $target) !== null) {
            $violations->add('domain', 'notEligible', ['kind' => 'aliasDomain'], 'A resource cannot be created in an alias domain.');

            return null;
        }

        $domain = $name === null ? null : $this->domains->resolve($principal, $name);

        if ($domain === null) {
            $violations->add('domain', 'notFound', ['resource' => 'domain'], 'The domain does not exist.');
        }

        return $domain;
    }
}
