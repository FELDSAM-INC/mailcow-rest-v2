<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Memcached\MemcachedFlusher;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\AuditedFailure;
use App\Exceptions\MailboxDependencyUnavailable;
use App\Exceptions\MailStoreUnreachable;
use App\Exceptions\OutcomeUnknown;
use App\Services\Accounts\AccountGeneration;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\SogoDirectory;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Http\ETag;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * Mailbox deletion, legacy mailbox('delete', 'mailbox') with the spec's deviations (feature 003
 * FR-039–FR-042, research R15), under the address lock:
 *
 *  1. the version check against a fresh read (`412`) and mailcow's shared store (`503`);
 *  2. the mail store moves the maildir to the garbage area first (every node in a replicated
 *     cluster). A failure or an unreachable mail store refuses the deletion (`503`), no answer
 *     within DOCKER_TIMEOUT is "outcome unknown" (`504`); either way nothing is removed, and a
 *     retry is safe because a maildir already gone counts as moved;
 *  3. in one transaction: every row legacy deletes, in legacy's order (the tags, Sieve filters and
 *     app passwords go with the mailbox through their foreign keys), the address removed from other
 *     aliases' recipients, then what legacy leaves behind (recent logins, the API's own
 *     preferences); the rate limit and both tagged-mail fields in the shared store (restored if the
 *     transaction fails); the groupware entry; the audit record;
 *  4. after the commit, the groupware cache flush, unless the bulk caller defers it.
 *
 * A failure after the mail move answers `500`; its audit record says the mail was moved, which an
 * operator can restore from the garbage area during MAILDIR_GC_TIME. Refusals are recorded by the
 * shared refusal auditing with the details the exceptions carry.
 */
class MailboxDeleter
{
    public function __construct(
        private readonly AddressLock $lock,
        private readonly SharedStore $store,
        private readonly MailStoreCommands $mailStore,
        private readonly MailboxDeletionPlanner $planner,
        private readonly MailboxVersion $version,
        private readonly SogoDirectory $sogo,
        private readonly MemcachedFlusher $flusher,
        private readonly AuditLogger $audit,
    ) {}

    public function delete(Principal $principal, string $address, ?string $ifMatch, ?string $bulkRequestId = null, bool $deferFlush = false): void
    {
        $this->lock->run([$address], function () use ($principal, $address, $ifMatch, $bulkRequestId): void {
            $row = $this->checkedRow($address, $ifMatch, false);
            $this->store->ping();
            $this->cleanupMaildir((string) $row->domain, (string) $row->local_part);
            $this->removeRows($principal, $address, $ifMatch, $bulkRequestId);
        });

        // Feature 007 (research R11): every impersonation of the deleted mailbox ends, also when a
        // mailbox with the same address is created within the same second.
        app(AccountGeneration::class)->bump('mailbox', $address);

        if (! $deferFlush && ! config('restv2.skip_sogo')) {
            $this->flusher->flush();
        }
    }

    private function checkedRow(string $address, ?string $ifMatch, bool $lock): stdClass
    {
        $query = DB::table('mailbox')->where('username', $address)->where('kind', '');
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('The mailbox does not exist.');
        }

        $acl = DB::table('user_acl')->where('username', $address)->first();
        $tags = DB::table('tags_mailbox')->where('username', $address)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();

        if (ETag::preconditionFails($ifMatch, $this->version->forMailbox($row, $acl, $tags))) {
            throw new PreconditionFailedHttpException('The mailbox was changed since it was read.');
        }

        return $row;
    }

    private function cleanupMaildir(string $domain, string $localPart): void
    {
        $result = $this->mailStore->cleanupMaildir($domain, $localPart);

        if ($result->isSuccess()) {
            return;
        }

        if ($result->isUnknown()) {
            throw new OutcomeUnknown('mailStoreCleanup', 'The mail store did not answer in time; the mail may or may not have been moved to the garbage area. Nothing was removed; retry later.', null, ['mailStoreCleanup' => 'unknown']);
        }

        $detail = 'The mail store could not move the mail to the garbage area: '.$result->message.' Nothing was removed.';

        throw $result->isUnreachable()
            ? new MailStoreUnreachable('mailStore', $detail, null, ['mailStoreCleanup' => 'failed'])
            : new MailboxDependencyUnavailable('mailStore', $detail, null, ['mailStoreCleanup' => 'failed']);
    }

    private function removeRows(Principal $principal, string $address, ?string $ifMatch, ?string $bulkRequestId): void
    {
        $undo = null;

        try {
            DB::transaction(function () use ($principal, $address, $ifMatch, $bulkRequestId, &$undo): void {
                $undo = null;
                $row = $this->checkedRow($address, $ifMatch, true);
                $plan = $this->planner->plan($address);
                $this->deleteRows($address);
                $undo = $this->store->deleteFields([
                    RateLimitStore::HASH => [$address],
                    TaggedMailHandlingStore::SUBJECT => [$address],
                    TaggedMailHandlingStore::SUBFOLDER => [$address],
                ]);
                $this->sogo->refresh([$address]);

                $details = ['removed' => $plan->removed(), 'mailStoreCleanup' => 'done'];

                if ($bulkRequestId !== null) {
                    $details['bulkRequestId'] = $bulkRequestId;
                }

                $this->audit->record(AuditEventType::MailboxDeletion, true, $principal, ['type' => 'mailbox', 'id' => $address, 'name' => (string) ($row->name ?? '')], null, null, $details);
            }, 3);
        } catch (Throwable $e) {
            $this->undo($undo, $address);

            throw match (true) {
                $e instanceof SharedStoreUnavailable => new MailboxDependencyUnavailable('sharedStore', 'mailcow\'s shared store is unavailable; the mail was moved to the garbage area but no record was removed. Retry later.', null, ['mailStoreCleanup' => 'done']),
                $e instanceof HttpExceptionInterface => $e,
                default => new AuditedFailure('The mailbox records could not be removed after the mail was moved to the garbage area.', ['mailStoreCleanup' => 'done'], $e),
            };
        }
    }

    /**
     * Legacy's statements, in its order, and what legacy leaves behind.
     */
    private function deleteRows(string $address): void
    {
        DB::table('alias')->where('goto', $address)->delete();
        DB::table('pushover')->where('username', $address)->delete();
        DB::table('quarantine')->where('rcpt', $address)->delete();
        DB::table('quota2')->where('username', $address)->delete();
        DB::table('quota2replica')->where('username', $address)->delete();
        DB::table('mailbox')->where('username', $address)->delete();
        DB::table('sender_acl')->where('logged_in_as', $address)->orWhere('send_as', $address)->delete();
        DB::table('user_acl')->where('username', $address)->delete();
        DB::table('spamalias')->where('goto', $address)->delete();
        DB::table('imapsync')->where('user2', $address)->delete();
        DB::table('filterconf')->where('object', $address)->delete();
        DB::table('sogo_user_profile')->where('c_uid', $address)->delete();
        DB::table('sogo_cache_folder')->where('c_uid', $address)->delete();
        DB::table('sogo_acl')->where('c_object', 'like', MailboxDeletionPlanner::sogoObjectPattern($address))->orWhere('c_uid', $address)->delete();

        $folders = fn ($query) => $query->select('c_folder_id')->from('sogo_folder_info')->where('c_path2', $address);

        foreach (['sogo_store', 'sogo_quick_contact', 'sogo_quick_appointment'] as $table) {
            DB::table($table)->whereIn('c_folder_id', $folders)->delete();
        }

        DB::table('sogo_folder_info')->where('c_path2', $address)->delete();
        DB::table('bcc_maps')->where('local_dest', $address)->delete();

        foreach (['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_authorization_codes'] as $table) {
            DB::table($table)->where('user_id', $address)->delete();
        }

        DB::table('tfa')->where('username', $address)->delete();
        DB::table('fido2')->where('username', $address)->delete();

        foreach (DB::table('alias')->whereRaw('`goto` REGEXP ?', [MailboxDeletionPlanner::gotoPattern($address)])->get(['id', 'goto']) as $alias) {
            $remaining = array_values(array_filter(explode(',', (string) $alias->goto), fn (string $goto): bool => strcasecmp(trim($goto), $address) !== 0));
            DB::table('alias')->where('id', $alias->id)->update(['goto' => implode(',', $remaining)]);
        }

        // Beyond legacy (FR-041): nothing a later mailbox of the same address could inherit.
        DB::table('sasl_log')->where('username', $address)->delete();
        DB::table('restv2_preferences')->where('account_kind', 'mailbox')->where('username', $address)->delete();
    }

    private function undo(?Closure $undo, string $address): void
    {
        if ($undo === null) {
            return;
        }

        try {
            $undo();
        } catch (Throwable $e) {
            Log::error("The shared-store entries of {$address} could not be restored after the failed deletion: ".$e->getMessage());
        }
    }
}
