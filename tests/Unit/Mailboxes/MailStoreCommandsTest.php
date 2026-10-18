<?php

namespace Tests\Unit\Mailboxes;

use App\Clients\DockerApi\DockerCallResult;
use App\Services\Mailboxes\MailStoreCommands;
use Tests\TestCase;

/**
 * Feature 003 T023 (research R16): the container commands are exactly legacy's, and in replicated
 * cluster mode a maildir command confirmed on this node is broadcast to every mail node.
 */
class MailStoreCommandsTest extends TestCase
{
    private function commands(): MailStoreCommands
    {
        return $this->app->make(MailStoreCommands::class);
    }

    public function test_the_commands_are_legacys(): void
    {
        $this->dockerApi()->aclList([['user' => 'anna@example.org', 'id' => 'bob@example.org', 'mailbox' => 'INBOX', 'rights' => ['lookup', 'read']], ['bogus']]);

        $this->commands()->cleanupMaildir('example.org', 'anna');
        $this->commands()->moveMaildir('example.org', 'anna', 'anna.smith');
        $acls = $this->commands()->getAcls('anna@example.org');
        $this->commands()->deleteAcl('anna@example.org', 'INBOX', 'bob@example.org');
        $this->commands()->setAcl('anna@example.org', 'INBOX', 'bob@example.org', ['lookup', 'read']);
        $this->commands()->renameWebmailUser('anna@example.org', 'anna.smith@example.org');

        $this->assertSame([['user' => 'anna@example.org', 'id' => 'bob@example.org', 'mailbox' => 'INBOX', 'rights' => ['lookup', 'read']]], $acls->data);
        $this->assertSame([
            ['dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org/anna']],
            ['dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => 'example.org/anna', 'new_maildir' => 'example.org/anna.smith']],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'anna@example.org']],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'delete_acl', 'user' => 'anna@example.org', 'mailbox' => 'INBOX', 'id' => 'bob@example.org']],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'set_acl', 'user' => 'anna@example.org', 'mailbox' => 'INBOX', 'id' => 'bob@example.org', 'rights' => ['lookup', 'read']]],
            ['sogo-mailcow', ['cmd' => 'sogo', 'task' => 'rename_user', 'old_username' => 'anna@example.org', 'new_username' => 'anna.smith@example.org']],
        ], $this->dockerApi()->commands());
        $this->assertSame([], $this->dockerApi()->broadcasts());
    }

    public function test_cluster_mode_broadcasts_confirmed_maildir_commands(): void
    {
        config(['restv2.cluster_mode' => 'replication']);
        $cleanup = ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org/anna'];

        $this->commands()->cleanupMaildir('example.org', 'anna');
        $this->dockerApi()->assertBroadcast('dovecot-mailcow', $cleanup);

        $this->dockerApi()->script('dovecot-mailcow', 'maildir', 'move', DockerCallResult::failed('busy'));
        $this->commands()->moveMaildir('example.org', 'anna', 'bob');
        $this->assertCount(1, $this->dockerApi()->broadcasts(), 'A failed command is not broadcast.');
    }

    public function test_a_failed_acl_query_is_passed_on(): void
    {
        $this->dockerApi()->script('dovecot-mailcow', 'doveadm', 'get_acl', DockerCallResult::unknown('timed out'));

        $this->assertTrue($this->commands()->getAcls('anna@example.org')->isUnknown());
    }
}
