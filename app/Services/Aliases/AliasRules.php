<?php

namespace App\Services\Aliases;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Config\MailcowConfig;
use App\Services\Identity\IdentityService;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;

/**
 * The business rules of alias creations, changes and copies (feature 004 FR-008–FR-010,
 * FR-021–FR-028, data-model.md "Alias" validation). Every violation is collected so that one `422`
 * names every invalid entry (FR-028); field errors use the index of the entry in the request
 * (`destinations.2`).
 */
class AliasRules
{
    public const COMMENT_MAX_BYTES = 160;

    public function __construct(
        private readonly AliasScope $scope,
        private readonly MailcowConfig $config,
        private readonly IdentityService $identity,
    ) {}

    /**
     * The normalised address, or null after recording its violation: a valid address or catch-all
     * (`invalidFormat`, `format: aliasAddress`) in an existing domain or alias domain in the
     * caller's scope (`notFound`, the same for a domain that does not exist, FR-003).
     */
    public function address(Principal $principal, mixed $raw, FieldViolations $violations): ?AliasAddress
    {
        $address = is_string($raw) ? AliasAddress::parse($raw) : null;

        if ($address === null || ! $address->isValid() || strlen($address->address) > 255) {
            $violations->add('address', 'invalidFormat', ['format' => 'aliasAddress'], 'Not a valid alias address (an e-mail address, or @domain for a catch-all).');

            return null;
        }

        if (! $this->scope->reachesDomain($principal, $address->domain)) {
            $violations->add('address', 'notFound', ['resource' => 'domain'], 'The domain does not exist.');

            return null;
        }

        return $address;
    }

    /**
     * The destination a request sends (`destinationType`, `destinations`), or null when it sends
     * neither or after recording a violation of the whole member. Destination addresses are
     * normalised; `$indexes` maps each valid entry's request index to its normalised address.
     *
     * @param  array<string, mixed>  $input
     * @param  array<int, string>  $indexes  request index => normalised address (out)
     */
    public function destination(array $input, ?AliasDestination $current, FieldViolations $violations, array &$indexes = []): ?AliasDestination
    {
        $indexes = [];
        $hasType = array_key_exists('destinationType', $input);
        $hasList = array_key_exists('destinations', $input);

        if (! $hasType && ! $hasList) {
            return null;
        }

        $type = $hasType ? (string) $input['destinationType'] : AliasDestination::ADDRESSES;

        if ($type !== AliasDestination::ADDRESSES) {
            if ($hasList) {
                $violations->add('destinations', 'conflictsWith', ['field' => 'destinationType'], 'Destination addresses cannot be combined with a special action.');

                return null;
            }

            return AliasDestination::special($type);
        }

        if (! $hasList) {
            if ($current !== null && ! $current->isSpecial()) {
                return $current;
            }

            $violations->add('destinations', 'required', [], 'Give at least one destination address.');

            return null;
        }

        $valid = true;

        foreach (array_values((array) $input['destinations']) as $i => $raw) {
            $address = is_string($raw) ? AliasAddress::parse($raw) : null;

            if ($address === null || $address->catchAll || ! $address->isValid()) {
                $violations->add("destinations.{$i}", 'invalidFormat', ['format' => 'email']);
                $valid = false;

                continue;
            }

            $indexes[$i] = $address->address;
        }

        if ($indexes === [] && $valid) {
            $violations->add('destinations', 'required', [], 'Give at least one destination address.');

            return null;
        }

        // With invalid entries the valid ones are still returned, so that their rules are checked
        // too and one response names every invalid entry (FR-028).
        return AliasDestination::addresses(array_values($indexes));
    }

    /**
     * The rules on a destination list that the request sends (FR-025, FR-027): no destination
     * equal to the alias's address, no resource, at most 65,535 characters joined, and, when the
     * server disables them, no added external destination.
     *
     * @param  array<int, string>  $indexes  request index => normalised address
     */
    public function checkDestinations(AliasDestination $destination, string $address, array $indexes, AliasDestination $stored, FieldViolations $violations): void
    {
        if ($destination->isSpecial()) {
            return;
        }

        $resources = array_flip(array_map('mb_strtolower', $this->resources($destination->addresses)));
        $added = array_flip(array_map('mb_strtolower', $destination->addedComparedTo($stored)));
        $external = $this->config->aliasDisableExternalDomains() ? array_flip(array_map('mb_strtolower', $this->external(array_keys($added)))) : [];

        foreach ($indexes as $i => $destinationAddress) {
            $key = mb_strtolower($destinationAddress);

            if ($key === mb_strtolower($address)) {
                $violations->add("destinations.{$i}", 'conflictsWith', ['field' => 'address', 'value' => $address], 'A destination cannot be the alias\'s own address.');
            } elseif (isset($resources[$key])) {
                $violations->add("destinations.{$i}", 'notEligible', ['kind' => 'resource'], 'A resource cannot be a destination.');
            } elseif (isset($external[$key])) {
                $violations->add("destinations.{$i}", 'notEligible', ['kind' => 'externalDestination'], 'External destinations are disabled on this server.');
            }
        }

        if (! $destination->fitsStorage()) {
            $violations->add('destinations', 'tooLong', ['max' => AliasDestination::MAX_LENGTH, 'unit' => 'characters']);
        }
    }

    /**
     * A comment that the request sets: at most 160 bytes in UTF-8 (FR-023).
     */
    public function comment(string $field, mixed $value, FieldViolations $violations): void
    {
        if (is_string($value) && strlen($value) > self::COMMENT_MAX_BYTES) {
            $violations->add($field, 'tooLong', ['max' => self::COMMENT_MAX_BYTES, 'unit' => 'bytes']);
        }
    }

    /**
     * The `aliasExternalGoto` permission for the external destinations a domain administrator adds
     * (FR-006, FR-027): every such destination is named in one `403 attribute-not-permitted`.
     *
     * @param  array<int, string>  $indexes  request index => normalised address
     */
    public function authorizeExternal(Principal $principal, AliasDestination $destination, AliasDestination $stored, array $indexes): void
    {
        if ($principal->role === Role::Admin || $destination->isSpecial()) {
            return;
        }

        if ($this->identity->permissions($principal)[PermissionFlag::AliasExternalGoto->value] ?? false) {
            return;
        }

        $added = array_flip(array_map('mb_strtolower', $destination->addedComparedTo($stored)));
        $external = array_flip(array_map('mb_strtolower', $this->external(array_keys($added))));
        $refused = [];

        foreach ($indexes as $i => $address) {
            if (isset($external[mb_strtolower($address)])) {
                $refused["destinations.{$i}"] = PermissionFlag::AliasExternalGoto->value;
            }
        }

        if ($refused !== []) {
            throw new ProblemException(
                ProblemType::AttributeNotPermitted,
                'External destinations need the aliasExternalGoto permission: '.implode(', ', array_keys($refused)).'.',
                ['attributes' => array_keys($refused), 'requiredFlags' => $refused],
            );
        }
    }

    /**
     * The given addresses whose domain is neither an active domain nor an active alias domain of
     * this server (legacy is_local_mailcow_domain()).
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    public function external(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }

        $domainOf = fn (string $a): string => mb_strtolower(substr($a, (int) strrpos($a, '@') + 1));
        $domains = array_values(array_unique(array_map($domainOf, $addresses)));
        $local = array_flip(array_map('mb_strtolower', [
            ...DB::table('domain')->whereIn('domain', $domains)->where('active', 1)->pluck('domain')->all(),
            ...DB::table('alias_domain')->whereIn('alias_domain', $domains)->where('active', 1)->pluck('alias_domain')->all(),
        ]));

        return array_values(array_filter($addresses, fn (string $a): bool => ! isset($local[$domainOf($a)])));
    }

    /**
     * The given addresses that are resources.
     *
     * @param  list<string>  $addresses
     * @return list<string>
     */
    private function resources(array $addresses): array
    {
        return $addresses === [] ? [] : DB::table('mailbox')->whereIn('username', $addresses)->where('kind', 'regexp', 'location|thing|group')
            ->pluck('username')->map(fn (mixed $u): string => (string) $u)->all();
    }
}
