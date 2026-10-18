<?php

namespace App\Services\Aliases;

use App\Services\Mailboxes\AddressAvailability;
use Illuminate\Support\Facades\DB;

/**
 * Whether an address is free for an alias (feature 004 FR-010, research R7): 003's
 * AddressAvailability (mailbox, resource, alias, temporary alias), plus legacy's alias-domain
 * clause: an address in an alias domain is taken when the target domain has a mailbox with the same
 * local part. An alias's own current address does not count (`$ignore`), so that a change of letter
 * case alone is possible. Callers hold 003's AddressLock.
 */
class AliasAddressAvailability
{
    public function __construct(private readonly AddressAvailability $addresses) {}

    public function holderOf(string $address, ?string $ignore = null): ?string
    {
        if ($ignore !== null && mb_strtolower($ignore) === mb_strtolower($address)) {
            return null;
        }

        $holder = $this->addresses->holderOf($address);

        if ($holder !== null) {
            return $holder;
        }

        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $target = DB::table('alias_domain')->where('alias_domain', substr($address, $at + 1))->value('target_domain');

        if ($target === null) {
            return null;
        }

        $kind = DB::table('mailbox')->where('username', substr($address, 0, $at).'@'.$target)->value('kind');

        return $kind === null ? null : ((string) $kind === '' ? 'mailbox' : 'resource');
    }

    public function assertFree(string $address, ?string $ignore = null): void
    {
        $holder = $this->holderOf($address, $ignore);

        if ($holder !== null) {
            throw AddressAvailability::inUse($address, $holder);
        }
    }
}
