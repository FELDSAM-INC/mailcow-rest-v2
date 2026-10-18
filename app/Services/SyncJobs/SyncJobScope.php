<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Auth\Role;
use App\Models\Mailcow\SyncJob;
use Illuminate\Database\Eloquent\Builder;

/**
 * The sync jobs a caller may reach (feature 008 FR-002, research R2), legacy
 * hasMailboxObjectAccess() on the target mailbox (`imapsync.user2`): every job for an
 * administrator, the jobs of mailboxes in the actively assigned domains for a domain administrator,
 * the jobs of the own mailbox for a mailbox user. The target mailbox must exist, as legacy's
 * domain lookup requires, so a job whose mailbox is gone is invisible. A job outside the scope is
 * answered exactly like one that does not exist. Evaluated from mailcow's data on every call.
 */
class SyncJobScope
{
    /**
     * Restricts a query on `imapsync` to the caller's scope (joins `mailbox` as `m`).
     *
     * @param  Builder<SyncJob>  $query
     * @return Builder<SyncJob>
     */
    public function apply(Builder $query, Principal $principal): Builder
    {
        $query->join('mailbox as m', 'm.username', '=', 'imapsync.user2');

        return match ($principal->role) {
            Role::Admin => $query,
            Role::DomainAdmin => $query->whereIn('m.domain', fn ($sub) => $sub->select('domain')->from('domain_admins')
                ->where('username', $principal->username)
                ->where('active', 1)),
            Role::MailboxUser => $query->where('imapsync.user2', $principal->username),
        };
    }

    /**
     * The jobs in the caller's scope, with every `imapsync` column but the log.
     *
     * @return Builder<SyncJob>
     */
    public function query(Principal $principal): Builder
    {
        return $this->apply(SyncJob::query()->select(SyncJobQuery::columns()), $principal);
    }

    public function findInScope(int $id, Principal $principal): ?SyncJob
    {
        return $this->query($principal)->where('imapsync.id', $id)->first();
    }

    /**
     * findInScope() with the `imapsync` row locked for the change (call it inside a transaction).
     * Only the job's row is locked, never its mailbox's: the scope is checked afterwards.
     */
    public function findInScopeForUpdate(int $id, Principal $principal): ?SyncJob
    {
        $job = SyncJob::query()->select(SyncJobQuery::columns())->whereKey($id)->lockForUpdate()->first();

        if ($job === null || ! $this->apply(SyncJob::query(), $principal)->where('imapsync.id', $id)->exists()) {
            return null;
        }

        return $job;
    }
}
