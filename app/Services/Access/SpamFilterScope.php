<?php

namespace App\Services\Access;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\AliasDomains\AliasDomainQuery;
use App\Services\Domains\DomainScope;
use App\Services\SelfService\MailboxAccess;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The objects feature 006 reaches (FR-001, FR-003, FR-069, research R15), evaluated from mailcow's
 * current data on every call (FR-002). Anything outside the caller's scope answers "not found",
 * exactly like an object that does not exist.
 *
 *  - Quarantine items: administrators every item; domain administrators the items whose recipient
 *    domain is one of their actively assigned domains (so items of deleted mailboxes and of
 *    resources stay visible to them); mailbox users the items addressed to their own mailbox.
 *  - Mailboxes (notification settings, spam score, mailbox lists, mailbox rate limit): 005's
 *    MailboxAccess, which is 003's MailboxScope plus the own mailbox of a mailbox user; resources
 *    are never mailboxes here.
 *  - Domains: 002's DomainScope (an alias domain name is not a domain). Alias domains: 004's
 *    AliasDomainQuery (through their target domain).
 */
class SpamFilterScope
{
    public function __construct(
        private readonly MailboxAccess $mailboxes,
        private readonly DomainScope $domains,
        private readonly AliasDomainQuery $aliasDomains,
    ) {}

    /**
     * The item predicate of a caller, applied to a query on `quarantine`.
     *
     * @return Closure(Builder|EloquentBuilder<\Illuminate\Database\Eloquent\Model>): void
     */
    public function itemScope(Principal $principal, string $table = 'quarantine'): Closure
    {
        return function (Builder|EloquentBuilder $query) use ($principal, $table): void {
            match ($principal->role) {
                Role::Admin => null,
                Role::DomainAdmin => $query->whereRaw("SUBSTRING_INDEX(`{$table}`.`rcpt`, '@', -1) IN (SELECT `domain`.`domain` FROM `domain` WHERE `domain`.`domain` IN (SELECT `domain_admins`.`domain` FROM `domain_admins` WHERE `domain_admins`.`active` = 1 AND `domain_admins`.`username` = ?))", [$principal->username]),
                Role::MailboxUser => $query->where($table.'.rcpt', $principal->username),
            };
        };
    }

    /** The normalised mailbox address the caller reaches, or `404`. */
    public function mailbox(Principal $principal, string $raw): string
    {
        return $this->mailboxes->find($principal, $raw) ?? throw new NotFoundHttpException('The mailbox does not exist.');
    }

    /** findMailbox() without the `404`, for bulk targets. */
    public function findMailbox(Principal $principal, string $raw): ?string
    {
        return $this->mailboxes->find($principal, $raw);
    }

    /** The normalised (ASCII) domain name the caller reaches, or `404`. */
    public function domain(Principal $principal, string $raw): string
    {
        return $this->domains->resolve($principal, $raw) ?? throw new NotFoundHttpException('The domain does not exist.');
    }

    /** The normalised (ASCII) alias domain name the caller reaches, or `404`. */
    public function aliasDomain(Principal $principal, string $raw): string
    {
        if ($principal->role === Role::MailboxUser) {
            throw new NotFoundHttpException('The alias domain does not exist.');
        }

        return $this->aliasDomains->resolve($principal, $raw) ?? throw new NotFoundHttpException('The alias domain does not exist.');
    }
}
