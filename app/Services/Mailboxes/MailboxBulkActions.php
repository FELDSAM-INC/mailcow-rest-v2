<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\Memcached\MemcachedFlusher;
use App\Exceptions\MailStoreUnreachable;
use App\Exceptions\OutcomeUnknown;
use App\Exceptions\PermissionFlagRequiredException;
use App\Http\RequestContext;
use App\Services\Identity\IdentityService;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;

/**
 * The legacy mass actions of the mailbox list (feature 003 FR-054, FR-055, FR-067, research R19):
 * activate, disable login, deactivate, delete, or allow or disallow one protocol, for up to the bulk
 * limit of mailboxes, each through the single operation's service (MailboxUpdater or MailboxDeleter)
 * with its checks, side effects and audit record, on 002's BulkActionRunner, and one groupware cache
 * flush for the whole request.
 *
 * A domain administrator without `protocolAccess` is refused a protocol action as a whole
 * (`permission-flag-required`). Once the mail store timed out or could not be reached for one
 * deletion, the remaining deletions of the request fail at once without calling it again, so that a
 * dead container service never costs DOCKER_TIMEOUT per mailbox.
 */
class MailboxBulkActions
{
    public const ACTIONS = ['activate', 'disableLogin', 'deactivate', 'delete', 'allowProtocol', 'disallowProtocol'];

    public const PROTOCOL_ACTIONS = ['allowProtocol', 'disallowProtocol'];

    private const STATUSES = ['activate' => 'active', 'disableLogin' => 'loginDisabled', 'deactivate' => 'inactive'];

    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly MailboxScope $scope,
        private readonly MailboxUpdater $updater,
        private readonly MailboxDeleter $deleter,
        private readonly MailboxVersion $version,
        private readonly IdentityService $identity,
        private readonly MemcachedFlusher $flusher,
    ) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $principal, string $action, ?string $protocol, array $targets): array
    {
        if (in_array($action, self::PROTOCOL_ACTIONS, true) && $principal->role === Role::DomainAdmin && ! ($this->identity->permissions($principal)['protocolAccess'] ?? false)) {
            throw new PermissionFlagRequiredException([PermissionFlag::ProtocolAccess]);
        }

        $bulkRequestId = RequestContext::requestId($request);
        $changed = false;
        /** @var OutcomeUnknown|MailStoreUnreachable|null $mailStoreDown */
        $mailStoreDown = null;

        $result = $this->runner->run(
            $request,
            $principal,
            $action === 'delete' ? AuditEventType::MailboxDeletion : AuditEventType::MailboxUpdate,
            $targets,
            fn (string $id): ?string => $this->scope->resolve($principal, $id),
            function (string $address, ?string $ifMatch) use ($principal, $action, $protocol, $bulkRequestId, &$changed, &$mailStoreDown): ?string {
                if ($action === 'delete') {
                    /** @var OutcomeUnknown|MailStoreUnreachable|null $mailStoreDown */
                    if ($mailStoreDown !== null) {
                        throw self::notAttempted($mailStoreDown);
                    }

                    try {
                        $this->deleter->delete($principal, $address, $ifMatch, $bulkRequestId, true);
                    } catch (OutcomeUnknown|MailStoreUnreachable $e) {
                        $mailStoreDown = $e;

                        throw $e;
                    }

                    $changed = true;

                    return null;
                }

                $patch = isset(self::STATUSES[$action])
                    ? ['status' => self::STATUSES[$action]]
                    : ['protocolAccess' => [(string) $protocol => $action === 'allowProtocol']];
                $changed = $this->updater->update($principal, $address, $patch, $ifMatch, $bulkRequestId, true) || $changed;

                return $this->version->ofMailbox($address);
            },
            fn (string $id, ?string $address): array => MailboxAuditTargets::target($address ?? $id),
        );

        if ($changed && ! config('restv2.skip_sogo')) {
            $this->flusher->flush();
        }

        return $result;
    }

    /**
     * The failure of a deletion skipped after the mail store timed out or was unreachable.
     */
    private static function notAttempted(OutcomeUnknown|MailStoreUnreachable $cause): OutcomeUnknown|MailStoreUnreachable
    {
        $detail = 'Not attempted: mailcow\'s container service did not answer or could not be reached for an earlier mailbox of this request. Nothing was removed; retry later.';

        return $cause instanceof OutcomeUnknown
            ? new OutcomeUnknown('mailStoreCleanup', $detail, null, ['mailStoreCleanup' => 'notAttempted'])
            : new MailStoreUnreachable('mailStore', $detail, null, ['mailStoreCleanup' => 'notAttempted']);
    }
}
