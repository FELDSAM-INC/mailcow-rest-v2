<?php

namespace App\Policies;

use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\PermissionFlagRequiredException;
use App\Exceptions\ProblemException;
use App\Services\Identity\IdentityService;
use App\Support\Http\ProblemType;
use InvalidArgumentException;

/**
 * Who may use the operations of feature 009 (FR-005, FR-006, FR-029), checked before any lookup so
 * that a refusal never reveals whether an object outside the caller's scope exists: first the role
 * (`403 forbidden`), then the permission flag of a domain administrator
 * (`403 permission-flag-required` naming the flag), evaluated from mailcow's data on every call
 * (FR-002). Object scope (`404`) is DomainScope's, MailboxScope's and BccScope's. A key principal
 * acts as its owner. Not a Gate policy: the Gate cannot answer the flag refusal.
 */
class RoutingPolicy
{
    /** Relayhosts, transports, connection tests, recipient maps, TLS policy maps, forwarding hosts. */
    public const MANAGE_ROUTING = 'manageRouting';

    public const VIEW_RELAYHOST_CHOICES = 'viewRelayhostChoices';

    public const VIEW_RELAYHOST_ASSIGNMENT = 'viewRelayhostAssignment';

    public const CHANGE_DOMAIN_RELAYHOST_ASSIGNMENT = 'changeDomainRelayhostAssignment';

    public const CHANGE_MAILBOX_RELAYHOST_ASSIGNMENT = 'changeMailboxRelayhostAssignment';

    public const VIEW_BCC_MAPS = 'viewBccMaps';

    public const CHANGE_BCC_MAPS = 'changeBccMaps';

    public const VIEW_ROUTING_CAPABILITIES = 'viewRoutingCapabilities';

    public function __construct(private readonly IdentityService $identity) {}

    /**
     * @throws ProblemException `forbidden` or `permission-flag-required`
     */
    public function authorize(Principal $principal, string $ability): void
    {
        if ($principal->role === Role::Admin) {
            return;
        }

        if ($principal->role !== Role::DomainAdmin) {
            throw new ProblemException(ProblemType::Forbidden);
        }

        match ($ability) {
            self::MANAGE_ROUTING => throw new ProblemException(ProblemType::Forbidden, 'Only administrators manage relayhosts, transports, recipient maps, TLS policy maps and forwarding hosts.'),
            self::VIEW_RELAYHOST_ASSIGNMENT, self::VIEW_BCC_MAPS, self::VIEW_ROUTING_CAPABILITIES => null,
            self::VIEW_RELAYHOST_CHOICES => $this->requireAny($principal, [PermissionFlag::DomainRelayhost, PermissionFlag::MailboxRelayhost], 'Reading the relayhost choices needs the domainRelayhost or the mailboxRelayhost permission.'),
            self::CHANGE_DOMAIN_RELAYHOST_ASSIGNMENT => $this->requireAny($principal, [PermissionFlag::DomainRelayhost], 'Changing a domain\'s relayhost needs the domainRelayhost permission.'),
            self::CHANGE_MAILBOX_RELAYHOST_ASSIGNMENT => $this->requireAny($principal, [PermissionFlag::MailboxRelayhost], 'Changing a mailbox\'s relayhost needs the mailboxRelayhost permission.'),
            self::CHANGE_BCC_MAPS => $this->requireAny($principal, [PermissionFlag::BccMaps], 'Changing BCC maps needs the bccMaps permission.'),
            default => throw new InvalidArgumentException("Unknown routing ability {$ability}."),
        };
    }

    public function allows(Principal $principal, string $ability): bool
    {
        try {
            $this->authorize($principal, $ability);

            return true;
        } catch (ProblemException) {
            return false;
        }
    }

    /**
     * @param  list<PermissionFlag>  $flags
     */
    private function requireAny(Principal $principal, array $flags, string $detail): void
    {
        $granted = $this->identity->permissions($principal);

        foreach ($flags as $flag) {
            if ($granted[$flag->value] ?? false) {
                return;
            }
        }

        throw new PermissionFlagRequiredException($flags, count($flags) > 1 ? 'any' : 'all', $detail);
    }
}
