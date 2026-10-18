<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Domains\DomainScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a mailbox address from a path or a bulk target for a caller (feature 003 FR-001–FR-003):
 * the normalised address when it is a mailbox (never a resource) of a domain in the caller's scope,
 * otherwise null, which the caller answers with "not found" exactly like an address that does not
 * exist. Evaluated from mailcow's data on every call (FR-002).
 */
class MailboxScope
{
    public function __construct(private readonly DomainScope $domains) {}

    public function resolve(Principal $principal, string $raw): ?string
    {
        if (! in_array($principal->role, [Role::Admin, Role::DomainAdmin], true)) {
            return null;
        }

        $address = MailboxAddress::normalize($raw);

        if ($address === null) {
            return null;
        }

        $query = DB::table('mailbox')->where('username', $address->address)->where('kind', '');

        return $this->domains->restrict($query, $principal, 'mailbox.domain')->exists() ? $address->address : null;
    }

    /**
     * Restricts a query on `mailbox` (its domain column given) to the caller's scope.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function restrict(Builder $query, Principal $principal, string $domainColumn = 'mailbox.domain'): Builder
    {
        return $this->domains->restrict($query, $principal, $domainColumn);
    }

    /** Whether a domain (ASCII name) is a `domain` row in the caller's scope. */
    public function domainInScope(Principal $principal, string $domain): bool
    {
        return $this->domains->resolve($principal, $domain) !== null;
    }
}
