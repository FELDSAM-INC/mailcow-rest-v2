<?php

namespace App\Services\SelfService;

use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Mailboxes\MailboxAddress;
use App\Services\Mailboxes\MailboxScope;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The mailbox of a `/mailboxes/{mailbox}/…` self-service operation (feature 005 FR-001–FR-003,
 * FR-007): the address normalised as feature 003 does, when it is a mailbox (never a resource) the
 * caller reaches. Administrators and domain administrators through 003's MailboxScope (every
 * mailbox, or those of the actively assigned domains); a mailbox user only their own mailbox.
 * Anything else answers "not found", exactly like an address that does not exist.
 */
class MailboxAccess
{
    public function __construct(private readonly MailboxScope $scope) {}

    /**
     * @return string the normalised address of the mailbox
     *
     * @throws NotFoundHttpException
     */
    public function resolve(Principal $principal, string $raw): string
    {
        return $this->find($principal, $raw) ?? throw new NotFoundHttpException('The mailbox does not exist.');
    }

    /**
     * The normalised address, or null when the caller does not reach the mailbox.
     */
    public function find(Principal $principal, string $raw): ?string
    {
        if ($principal->role !== Role::MailboxUser) {
            return $this->scope->resolve($principal, $raw);
        }

        $address = MailboxAddress::normalize($raw);

        if ($address === null || strcasecmp($address->address, $principal->username) !== 0) {
            return null;
        }

        $stored = DB::table('mailbox')->where('username', $principal->username)->where('kind', '')->value('username');

        return is_string($stored) ? $stored : null;
    }
}
