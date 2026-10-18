<?php

namespace App\Services\Mailboxes;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The container commands legacy sends for mailboxes (feature 003 research R16), through dockerapi:
 * maildir cleanup and move, the IMAP ACLs of shared folders (`doveadm get_acl`, `delete_acl`,
 * `set_acl`) on `dovecot-mailcow`, and the SOGo rename tool on `sogo-mailcow`.
 *
 * With `CLUSTERMODE=replication`, a maildir cleanup or move is confirmed on this node first and then
 * broadcast to every mail node through `MC_CHANNEL` (legacy only broadcasts, without confirmation);
 * the broadcast is not confirmed, and its failure is logged.
 */
class MailStoreCommands
{
    public const DOVECOT = 'dovecot-mailcow';

    public const SOGO = 'sogo-mailcow';

    public function __construct(private readonly DockerApiClient $docker) {}

    /** Moves the mailbox's maildir to mailcow's garbage area; a missing maildir counts as moved. */
    public function cleanupMaildir(string $domain, string $localPart): DockerCallResult
    {
        return $this->maildir(['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => $domain.'/'.$localPart]);
    }

    public function moveMaildir(string $domain, string $oldLocalPart, string $newLocalPart): DockerCallResult
    {
        return $this->maildir(['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => $domain.'/'.$oldLocalPart, 'new_maildir' => $domain.'/'.$newLocalPart]);
    }

    /**
     * The shared-folder ACLs the mailbox grants and receives: a successful result's data is a list
     * of `{user, id, mailbox, rights}`.
     */
    public function getAcls(string $address): DockerCallResult
    {
        $result = $this->docker->query(self::DOVECOT, ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => $address]);

        if (! $result->isSuccess()) {
            return $result;
        }

        $entries = [];

        foreach (is_array($result->data) ? $result->data : [] as $entry) {
            if (is_array($entry) && is_string($entry['user'] ?? null) && is_string($entry['id'] ?? null) && is_string($entry['mailbox'] ?? null)) {
                $entries[] = [
                    'user' => $entry['user'],
                    'id' => $entry['id'],
                    'mailbox' => $entry['mailbox'],
                    'rights' => array_values(array_map('strval', array_filter((array) ($entry['rights'] ?? []), 'is_scalar'))),
                ];
            }
        }

        return DockerCallResult::success($result->message, $entries);
    }

    public function deleteAcl(string $user, string $mailbox, string $id): DockerCallResult
    {
        return $this->docker->exec(self::DOVECOT, ['cmd' => 'doveadm', 'task' => 'delete_acl', 'user' => $user, 'mailbox' => $mailbox, 'id' => $id]);
    }

    /**
     * @param  list<string>  $rights
     */
    public function setAcl(string $user, string $mailbox, string $id, array $rights): DockerCallResult
    {
        return $this->docker->exec(self::DOVECOT, ['cmd' => 'doveadm', 'task' => 'set_acl', 'user' => $user, 'mailbox' => $mailbox, 'id' => $id, 'rights' => $rights]);
    }

    public function renameWebmailUser(string $oldAddress, string $newAddress): DockerCallResult
    {
        return $this->docker->exec(self::SOGO, ['cmd' => 'sogo', 'task' => 'rename_user', 'old_username' => $oldAddress, 'new_username' => $newAddress]);
    }

    /** Whether the mail store's container can be found (preflight checks, deletion preview). */
    public function mailStoreReachable(): bool
    {
        return $this->docker->reachable(self::DOVECOT);
    }

    public function webmailReachable(): bool
    {
        return $this->docker->reachable(self::SOGO);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function maildir(array $command): DockerCallResult
    {
        $result = $this->docker->exec(self::DOVECOT, $command);

        if ($result->isSuccess() && config('restv2.cluster_mode') === 'replication') {
            try {
                $this->docker->broadcast(self::DOVECOT, $command);
            } catch (Throwable $e) {
                Log::warning('The maildir command could not be broadcast to the other mail nodes: '.$e->getMessage());
            }
        }

        return $result;
    }
}
