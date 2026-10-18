<?php

namespace Tests\Unit\Clients;

use App\Clients\DockerApi\DockerCallResult;
use App\Clients\DockerApi\HttpDockerApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature 002 T009 (research D14): the dockerapi adapter ports legacy docker(): the container is
 * looked up by its compose labels, then restarted or given an exec command; TLS is not verified and
 * the timeout is DOCKER_TIMEOUT.
 */
class HttpDockerApiClientTest extends TestCase
{
    private const SOGO_ID = 'b1b2b3b4b5b6b7b8b9b0b1b2b3b4b5b6b7b8b9b0b1b2b3b4b5b6b7b8b9b0b1b2';

    private const DOVECOT_ID = 'd1d2d3d4d5d6d7d8d9d0d1d2d3d4d5d6d7d8d9d0d1d2d3d4d5d6d7d8d9d0d1d2';

    /** @var list<array<string, mixed>> */
    private array $options = [];

    private function client(): HttpDockerApiClient
    {
        return $this->app->make(HttpDockerApiClient::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function containers(): array
    {
        $container = fn (string $id, string $service, string $project): array => [
            'Id' => $id,
            'State' => 'running',
            'Config' => ['Labels' => ['com.docker.compose.service' => $service, 'com.docker.compose.project' => $project]],
        ];

        return [
            $container(str_repeat('a', 64), 'sogo-mailcow', 'another-project'),
            $container(self::SOGO_ID, 'sogo-mailcow', 'MailcowDockerized'),
            $container(self::DOVECOT_ID, 'dovecot-mailcow', 'mailcowdockerized'),
        ];
    }

    /**
     * @param  array<string, mixed>|\Closure  $action
     */
    private function fake(array|\Closure $action): void
    {
        Http::fake(function (Request $request, array $options) use ($action) {
            $this->options[] = $options;

            if (str_ends_with($request->url(), '/containers/json')) {
                return Http::response($this->containers());
            }

            return is_array($action) ? Http::response($action) : $action($request);
        });
    }

    public function test_a_restart_finds_the_container_by_its_compose_labels(): void
    {
        $this->fake(['type' => 'success', 'msg' => 'command completed successfully']);

        $result = $this->client()->restart('sogo-mailcow');

        $this->assertTrue($result->isSuccess());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_ends_with($r->url(), '://dockerapi/containers/json'));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === 'https://dockerapi/containers/'.self::SOGO_ID.'/restart');
        Http::assertSentCount(2);
    }

    public function test_an_exec_posts_exactly_the_command(): void
    {
        $this->fake(['type' => 'success', 'msg' => 'moved']);

        $result = $this->client()->exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org']);

        $this->assertTrue($result->isSuccess());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === 'https://dockerapi/containers/'.self::DOVECOT_ID.'/exec'
            && json_decode($r->body(), true) === ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org']);
    }

    public function test_a_danger_answer_is_a_failure_with_its_message(): void
    {
        $this->fake(['type' => 'danger', 'msg' => 'x']);

        $result = $this->client()->restart('sogo-mailcow');

        $this->assertSame(DockerCallResult::FAILED, $result->status);
        $this->assertSame('x', $result->message);
    }

    public function test_a_missing_container_is_a_failure(): void
    {
        $this->fake(['type' => 'success']);

        $result = $this->client()->restart('netfilter-mailcow');

        $this->assertSame(DockerCallResult::FAILED, $result->status);
        Http::assertSentCount(1);
    }

    public function test_a_timeout_has_an_unknown_outcome(): void
    {
        $this->fake(fn () => Http::failedConnection('cURL error 28: Operation timed out after 60001 milliseconds'));

        $result = $this->client()->restart('sogo-mailcow');

        $this->assertSame(DockerCallResult::UNKNOWN, $result->status);
    }

    public function test_an_unreachable_service_is_a_failure(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to dockerapi port 443'));

        $result = $this->client()->restart('sogo-mailcow');

        $this->assertSame(DockerCallResult::FAILED, $result->status);
    }

    public function test_tls_is_not_verified_and_the_timeout_is_docker_timeout(): void
    {
        config(['restv2.vars_overrides.DOCKER_TIMEOUT' => '42']);
        $this->fake(['type' => 'success']);

        $this->client()->restart('sogo-mailcow');

        $this->assertCount(2, $this->options);
        foreach ($this->options as $options) {
            $this->assertFalse($options['verify']);
            $this->assertEquals(42, $options['timeout']);
        }
    }

    public function test_a_container_is_reachable_when_its_service_runs_in_the_compose_project(): void
    {
        // Feature 003 T012 (research R16): a container lookup only, no command.
        $this->fake(['type' => 'success']);

        $this->assertTrue($this->client()->reachable('dovecot-mailcow'));
        $this->assertFalse($this->client()->reachable('netfilter-mailcow'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    }

    public function test_nothing_is_reachable_when_the_container_service_is_down(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to dockerapi port 443'));

        $this->assertFalse($this->client()->reachable('dovecot-mailcow'));
    }

    public function test_a_connection_error_is_an_unreachable_failure(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to dockerapi port 443'));

        $result = $this->client()->exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org/anna']);

        $this->assertSame(DockerCallResult::FAILED, $result->status);
        $this->assertTrue($result->isUnreachable());
    }

    public function test_a_missing_container_is_an_unreachable_failure(): void
    {
        $this->fake(['type' => 'success']);

        $result = $this->client()->exec('netfilter-mailcow', ['cmd' => 'x']);

        $this->assertTrue($result->isUnreachable());
    }

    public function test_a_danger_answer_is_not_unreachable(): void
    {
        $this->fake(['type' => 'danger', 'msg' => 'command failed: mv: cannot move']);

        $result = $this->client()->exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'move', 'old_maildir' => 'a/b', 'new_maildir' => 'a/c']);

        $this->assertSame(DockerCallResult::FAILED, $result->status);
        $this->assertFalse($result->isUnreachable());
    }

    public function test_a_query_returns_the_json_answer(): void
    {
        $acl = [['user' => 'anna@example.org', 'id' => 'bob@example.org', 'mailbox' => 'INBOX', 'rights' => ['lookup', 'read']]];
        $this->fake($acl);

        $result = $this->client()->query('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'anna@example.org']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($acl, $result->data);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === 'https://dockerapi/containers/'.self::DOVECOT_ID.'/exec'
            && json_decode($r->body(), true) === ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'anna@example.org']);
    }

    public function test_a_query_answered_without_a_list_is_a_failure(): void
    {
        $this->fake(fn () => Http::response('null'));

        $result = $this->client()->query('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'anna@example.org']);

        $this->assertSame(DockerCallResult::FAILED, $result->status);
    }

    public function test_a_query_that_times_out_has_an_unknown_outcome(): void
    {
        $this->fake(fn () => Http::failedConnection('cURL error 28: Operation timed out after 60001 milliseconds'));

        $this->assertTrue($this->client()->query('dovecot-mailcow', ['cmd' => 'doveadm', 'task' => 'get_acl', 'id' => 'a@b'])->isUnknown());
    }
}
