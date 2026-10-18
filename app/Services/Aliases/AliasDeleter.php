<?php

namespace App\Services\Aliases;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\GroupwareRefresh;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Alias deletion, legacy mailbox('delete', 'alias') (feature 004 FR-033–FR-035): in one transaction
 * on the locked row, the version check (`412`), legacy's statements on `alias` and `sender_acl`
 * (every permission to send as the address; for a catch-all the domain permissions of its domain),
 * the groupware entries of the former destinations in a savepoint, and the audit record with what
 * else was removed; the groupware cache is flushed after the commit. Other aliases that name the
 * address as a destination keep it, as in legacy.
 */
class AliasDeleter
{
    public function __construct(
        private readonly AliasVersion $version,
        private readonly AliasSenderAclEffects $senderAcl,
        private readonly GroupwareRefresh $groupware,
        private readonly GroupwareCacheFlush $flush,
        private readonly AuditLogger $audit,
    ) {}

    public function delete(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        $outcome = DB::transaction(function () use ($principal, $id, $ifMatch, $bulkRequestId): GroupwareOutcome {
            $row = AliasUpdater::lockedRow($id);

            if (ETag::preconditionFails($ifMatch, $this->version->forRow($row))) {
                throw new PreconditionFailedHttpException('The alias was changed since it was read.');
            }

            DB::table('alias')->where('id', $id)->delete();
            $permissions = $this->senderAcl->deleted((string) $row->address);
            $outcome = $this->groupware->inSavepoint(AliasDestination::fromGoto((string) $row->goto)->mailboxCandidates());

            $details = ['removed' => ['senderPermissions' => $permissions], 'groupwareUpdate' => $outcome->value];

            if ($bulkRequestId !== null) {
                $details['bulkRequestId'] = $bulkRequestId;
            }

            $this->audit->record(AuditEventType::AliasDeletion, true, $principal, ['type' => 'alias', 'id' => (string) $id, 'name' => (string) $row->address], null, null, $details);

            return $outcome;
        }, 3);

        if ($outcome === GroupwareOutcome::Done) {
            $this->flush->flush();
        }
    }
}
