<?php

namespace Tests\Unit\Support;

use App\Clients\DockerApi\DockerCallResult;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fakes\FakeDockerApiClient;

/**
 * Feature 003 T005 (research R25): the dockerapi fake answers each `{service, cmd, task}` as
 * scripted (also per call), returns the programmed ACL list, reports reachability per service,
 * logs broadcasts and asserts the calls made and their order.
 */
class FakeDockerApiClientTest extends TestCase
{
    private const CLEANUP = ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org/anna'];

    public function test_unscripted_calls_succeed(): void
    {
        $fake = new FakeDockerApiClient;

        $this->assertTrue($fake->exec('dovecot-mailcow', self::CLEANUP)->isSuccess());
        $this->assertTrue($fake->reachable('dovecot-mailcow'));
        $this->assertSame([], $fake->query('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'a@b'])->data);
    }

    public function test_answers_are_scripted_per_command_and_per_call(): void
    {
        $fake = new FakeDockerApiClient;
        $fake->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::failed('busy'), DockerCallResult::success());

        $this->assertSame(DockerCallResult::FAILED, $fake->exec('dovecot-mailcow', self::CLEANUP)->status);
        $this->assertTrue($fake->exec('dovecot-mailcow', self::CLEANUP)->isSuccess());
        $this->assertTrue($fake->exec('dovecot-mailcow', self::CLEANUP)->isSuccess(), 'The last answer repeats.');
        $this->assertTrue($fake->exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => 'a', 'new_maildir' => 'b'])->isSuccess());
    }

    public function test_unknown_and_unreachable_answers(): void
    {
        $fake = new FakeDockerApiClient;
        $fake->script('sogo-mailcow', 'sogo', 'rename_user', DockerCallResult::unknown('timed out'));
        $fake->script('dovecot-mailcow', 'maildir', 'cleanup', DockerCallResult::unreachable('connection refused'));

        $this->assertTrue($fake->exec('sogo-mailcow', ['cmd' => 'sogo', 'task' => 'rename_user', 'old_username' => 'a', 'new_username' => 'b'])->isUnknown());
        $result = $fake->exec('dovecot-mailcow', self::CLEANUP);
        $this->assertTrue($result->isUnreachable());
        $this->assertFalse($result->isSuccess());
    }

    public function test_the_acl_list_and_reachability_are_programmable(): void
    {
        $fake = new FakeDockerApiClient;
        $acl = [['user' => 'anna@example.org', 'id' => 'bob@example.org', 'mailbox' => 'INBOX', 'rights' => ['lookup', 'read']]];
        $fake->aclList($acl);
        $fake->setReachable('sogo-mailcow', false);

        $this->assertSame($acl, $fake->query('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'anna@example.org'])->data);
        $this->assertFalse($fake->reachable('sogo-mailcow'));
        $this->assertTrue($fake->reachable('dovecot-mailcow'));
    }

    public function test_calls_are_asserted_in_order_and_broadcasts_are_logged(): void
    {
        $fake = new FakeDockerApiClient;
        $fake->query('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'a@b']);
        $fake->exec('dovecot-mailcow', self::CLEANUP);
        $fake->broadcast('dovecot-mailcow', self::CLEANUP);

        $fake->assertExecuted('dovecot-mailcow', self::CLEANUP);
        $fake->assertNotExecuted('sogo-mailcow', ['cmd' => 'sogo', 'task' => 'rename_user', 'old_username' => 'a', 'new_username' => 'b']);
        $fake->assertExecOrder([
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'a@b']],
            ['dovecot-mailcow', self::CLEANUP],
        ]);
        $fake->assertBroadcast('dovecot-mailcow', self::CLEANUP);

        $this->expectException(AssertionFailedError::class);
        $fake->assertExecOrder([
            ['dovecot-mailcow', self::CLEANUP],
            ['dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'a@b']],
        ]);
    }

    public function test_a_callback_runs_before_each_answer(): void
    {
        $fake = new FakeDockerApiClient;
        $seen = [];
        $fake->beforeAnswer(function (string $service, array $command) use (&$seen): void {
            $seen[] = $service.':'.$command['task'];
        });

        $fake->exec('dovecot-mailcow', self::CLEANUP);

        $this->assertSame(['dovecot-mailcow:cleanup'], $seen);
    }
}
