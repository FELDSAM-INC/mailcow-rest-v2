<?php

namespace App\Services\MailboxGroups;

use App\Clients\DockerApi\DockerApiClient;
use App\Services\Groupware\GroupwareCacheFlush;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\GroupwareRefresh;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** External changes run after commit; failed/removing grants are retried by upstream cron. */
final class GroupEffects
{
    private const RIGHTS = [
        'read' => ['lookup', 'read', 'write-seen'],
        'write' => ['lookup', 'read', 'write-seen', 'write', 'write-deleted', 'insert', 'post', 'expunge', 'create'],
        'full' => ['lookup', 'read', 'write-seen', 'write', 'write-deleted', 'insert', 'post', 'expunge', 'create', 'delete'],
    ];

    public function __construct(private readonly DockerApiClient $docker, private readonly GroupwareRefresh $sogo,
        private readonly GroupwareCacheFlush $cache) {}

    /** @param list<string> $owners
     * @return list<string>
     */
    public function removeDeleted(int $id, array $owners): array
    {
        $warnings = [];
        foreach ($owners as $owner) {
            try {
                if (! $this->docker->exec('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'set_acl_all',
                    'user' => $owner, 'id' => 'group=mcg_'.$id, 'rights' => []])->isSuccess()) {
                    $warnings[] = 'imapGrantCleanupFailed:'.$owner;
                }
            } catch (Throwable) {
                $warnings[] = 'imapGrantCleanupFailed:'.$owner;
            }
        }

        return $warnings;
    }

    /** @param list<string> $members
     * @return list<string>
     */
    public function apply(int $id, array $members): array
    {
        $warnings = [];
        foreach (DB::table('mailbox_group_imap_grant')->where('group_id', $id)->get() as $grant) {
            try {
                $result = $this->docker->exec('dovecot-mailcow', [
                    'cmd' => 'doveadm', 'task' => 'set_acl_all', 'user' => $grant->owner, 'id' => 'group=mcg_'.$id,
                    'rights' => $grant->state === 'removing' ? [] : self::RIGHTS[$grant->rights],
                ]);
                $success = $result->isSuccess();
            } catch (Throwable $e) {
                Log::warning('The group IMAP grant could not be applied.', ['exception' => $e]);
                $success = false;
            }
            if ($success && $grant->state === 'removing') {
                DB::table('mailbox_group_imap_grant')->where('id', $grant->id)->delete();
            } else {
                DB::table('mailbox_group_imap_grant')->where('id', $grant->id)->update([
                    'state' => $grant->state === 'removing' ? 'removing' : ($success ? 'applied' : 'failed'),
                    'last_error' => $success ? null : 'dovecot_unavailable',
                ]);
            }
            if (! $success) {
                $warnings[] = 'imapGrantPending:'.$grant->owner;
            }
        }
        try {
            if ($members !== [] && ! $this->docker->exec('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'auth_cache_flush', 'users' => $members])->isSuccess()) {
                $warnings[] = 'authCacheFlushFailed';
            }
        } catch (Throwable) {
            $warnings[] = 'authCacheFlushFailed';
        }
        try {
            if ($this->sogo->inSavepoint($members) === GroupwareOutcome::Failed) {
                $warnings[] = 'groupwareRefreshFailed';
            }
            $this->cache->flush();
        } catch (Throwable) {
            $warnings[] = 'groupwareRefreshFailed';
        }

        // Upstream's minutely reconciler initializes missing SOGo folders and maintains subscriptions.
        return array_values(array_unique($warnings));
    }
}
