<?php

namespace App\Services\Mailboxes;

use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;

/**
 * Whether an address is free (feature 003 FR-021, research R7): it may not be used by a mailbox, a
 * resource, an alias or a temporary alias, checked in legacy's order (`mailbox`, `alias`,
 * `spamalias`). Callers hold the address lock (AddressLock) around the check and the insert.
 * Shared with features 004 and 005.
 */
class AddressAvailability
{
    /**
     * The kind of object holding the normalised address (`mailbox`, `resource`, `alias`,
     * `temporaryAlias`), or null when it is free.
     */
    public function holderOf(string $address): ?string
    {
        $kind = DB::table('mailbox')->where('username', $address)->value('kind');

        if ($kind !== null) {
            return (string) $kind === '' ? 'mailbox' : 'resource';
        }

        if (DB::table('alias')->where('address', $address)->exists()) {
            return 'alias';
        }

        return DB::table('spamalias')->where('address', $address)->exists() ? 'temporaryAlias' : null;
    }

    /**
     * @throws ProblemException `409 address-in-use` with the address and its holder
     */
    public function assertFree(string $address): void
    {
        $holder = $this->holderOf($address);

        if ($holder !== null) {
            throw self::inUse($address, $holder);
        }
    }

    public static function inUse(string $address, string $holder): ProblemException
    {
        $kind = ['mailbox' => 'a mailbox', 'resource' => 'a resource', 'alias' => 'an alias', 'temporaryAlias' => 'a temporary alias'][$holder] ?? 'another object';

        return new ProblemException(ProblemType::AddressInUse, "The address {$address} is already used by {$kind}.", ['address' => $address, 'holder' => $holder]);
    }
}
