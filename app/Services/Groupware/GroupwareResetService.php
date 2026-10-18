<?php

namespace App\Services\Groupware;

use App\Audit\AuditEventType;
use App\Auth\PermissionFlag;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\SelfService\PermissionGate;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;

/**
 * The groupware resets of a mailbox (feature 005 FR-064 to FR-066, research R17), ported from
 * legacy mailbox('delete', 'eas_cache' | 'sogo_profile'): the same statements in the same order, in
 * one transaction with the audit record. Refused with `403 groupware-disabled` when the server runs
 * without the groupware (`SKIP_SOGO=y`), then without the flag (`easReset`, `sogoProfileReset`).
 */
class GroupwareResetService
{
    public function __construct(
        private readonly PermissionGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    /** Removes the mailbox's cached ActiveSync device state. */
    public function resetActiveSyncCache(Principal $principal, string $mailbox): void
    {
        $this->assertEnabled();
        $this->gate->requireChange($principal, PermissionFlag::EasReset);

        DB::transaction(function () use ($principal, $mailbox): void {
            $removed = ['sogo_cache_folder' => DB::table('sogo_cache_folder')->where('c_uid', $mailbox)->delete()];

            $this->record(AuditEventType::ActiveSyncCacheReset, $principal, $mailbox, $removed);
        });
    }

    /**
     * Removes the mailbox's groupware settings, cached device state, sharing permissions on and by
     * the mailbox, and its folders with their contents. Irreversible, as in legacy.
     */
    public function resetProfile(Principal $principal, string $mailbox): void
    {
        $this->assertEnabled();
        $this->gate->requireChange($principal, PermissionFlag::SogoProfileReset);

        DB::transaction(function () use ($principal, $mailbox): void {
            $folders = fn () => DB::table('sogo_folder_info')->select('c_folder_id')->where('c_path2', $mailbox);
            // legacy escapes the LIKE wildcards so the address matches literally.
            $objects = '%/'.addcslashes($mailbox, '\\%_').'/%';

            $removed = [];
            $removed['sogo_user_profile'] = DB::table('sogo_user_profile')->where('c_uid', $mailbox)->delete();
            $removed['sogo_cache_folder'] = DB::table('sogo_cache_folder')->where('c_uid', $mailbox)->delete();
            $removed['sogo_acl'] = DB::table('sogo_acl')->where(fn ($q) => $q->where('c_object', 'like', $objects)->orWhere('c_uid', $mailbox))->delete();
            $removed['sogo_store'] = DB::table('sogo_store')->whereIn('c_folder_id', $folders())->delete();
            $removed['sogo_quick_contact'] = DB::table('sogo_quick_contact')->whereIn('c_folder_id', $folders())->delete();
            $removed['sogo_quick_appointment'] = DB::table('sogo_quick_appointment')->whereIn('c_folder_id', $folders())->delete();
            $removed['sogo_folder_info'] = DB::table('sogo_folder_info')->where('c_path2', $mailbox)->delete();

            $this->record(AuditEventType::GroupwareProfileReset, $principal, $mailbox, $removed);
        });
    }

    private function assertEnabled(): void
    {
        if (config('restv2.skip_sogo')) {
            throw new ProblemException(ProblemType::GroupwareDisabled);
        }
    }

    /**
     * @param  array<string, int>  $removed
     */
    private function record(AuditEventType $type, Principal $principal, string $mailbox, array $removed): void
    {
        $this->audit->record($type, true, $principal, ['type' => 'groupwareData', 'id' => $mailbox, 'name' => null], null, null, ['removed' => $removed]);
    }
}
