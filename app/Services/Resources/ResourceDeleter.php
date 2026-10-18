<?php

namespace App\Services\Resources;

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
 * Resource deletion, legacy mailbox('delete', 'resource') (feature 004 FR-052, research R13): in one
 * transaction on the locked row (only resource rows: a deviation, legacy deleted any `mailbox` row
 * by name), the version check (`412`), legacy's statements on `mailbox` and the groupware tables
 * (profile, cached folders, sharing permissions, stored items, quick tables, folders), the groupware
 * entry removed in a savepoint, the audit record with what was removed; the cache is flushed after
 * the commit.
 */
class ResourceDeleter
{
    public function __construct(
        private readonly ResourceVersion $version,
        private readonly GroupwareRefresh $groupware,
        private readonly GroupwareCacheFlush $flush,
        private readonly AuditLogger $audit,
    ) {}

    public function delete(Principal $principal, string $address, ?string $ifMatch, ?string $bulkRequestId = null): void
    {
        $outcome = DB::transaction(function () use ($principal, $address, $ifMatch, $bulkRequestId): GroupwareOutcome {
            $row = ResourceUpdater::lockedRow($address);

            if (ETag::preconditionFails($ifMatch, $this->version->forRow($row))) {
                throw new PreconditionFailedHttpException('The resource was changed since it was read.');
            }

            $folders = fn () => DB::table('sogo_folder_info')->select('c_folder_id')->where('c_path2', $address);
            $objects = '%/'.addcslashes($address, '\\%_').'/%';

            DB::table('mailbox')->where('username', $address)->where('kind', 'regexp', ResourceQuery::KIND_PATTERN)->delete();
            $removed = [
                'groupwareProfiles' => DB::table('sogo_user_profile')->where('c_uid', $address)->delete(),
                'groupwareCacheEntries' => DB::table('sogo_cache_folder')->where('c_uid', $address)->delete(),
                'groupwareSharingPermissions' => DB::table('sogo_acl')->where(fn ($q) => $q->where('c_object', 'like', $objects)->orWhere('c_uid', $address))->delete(),
                'groupwareItems' => DB::table('sogo_store')->whereIn('c_folder_id', $folders())->delete()
                    + DB::table('sogo_quick_contact')->whereIn('c_folder_id', $folders())->delete()
                    + DB::table('sogo_quick_appointment')->whereIn('c_folder_id', $folders())->delete(),
                'groupwareFolders' => DB::table('sogo_folder_info')->where('c_path2', $address)->delete(),
            ];
            ksort($removed);

            $outcome = $this->groupware->inSavepoint([$address]);
            $details = ['removed' => $removed, 'groupwareUpdate' => $outcome->value];

            if ($bulkRequestId !== null) {
                $details['bulkRequestId'] = $bulkRequestId;
            }

            $this->audit->record(AuditEventType::ResourceDeletion, true, $principal, ['type' => 'resource', 'id' => $address, 'name' => (string) $row->name], null, null, $details);

            return $outcome;
        }, 3);

        if ($outcome === GroupwareOutcome::Done) {
            $this->flush->flush();
        }
    }
}
