<?php

namespace Tests\Unit\Clients;

use App\Clients\DockerApi\ContainerSnapshot;
use App\Clients\DockerApi\DockerApiTimeout;
use App\Clients\DockerApi\DockerApiUnreachable;
use App\Clients\DockerApi\DockerCallResult;
use App\Clients\DockerApi\HttpDockerApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Monitoring\MonitoringFixtures;
use Tests\TestCase;

/**
 * Feature 011 T009 (research R2): the dockerapi operations of the monitoring feature. Containers
 * come from `GET /containers/json?all=true`, filtered by compose project and parsed without their
 * environment; start, stop and restart find stopped containers; resource samples and host figures
 * use a 5 second timeout; text exec answers are returned as data. TLS is never verified.
 */
class HttpDockerApiClientMonitoringTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $options = [];

    private function client(): HttpDockerApiClient
    {
        return $this->app->make(HttpDockerApiClient::class);
    }

    /**
     * Answers the container lookups with the recorded fixture and everything else with $answer.
     */
    private function fake(mixed $answer = ['type' => 'success', 'msg' => 'command completed successfully']): void
    {
        Http::fake(function (Request $request, array $options) use ($answer) {
            $this->options[] = ['url' => $request->url()] + $options;

            if (str_contains($request->url(), '/containers/json')) {
                $all = MonitoringFixtures::containers();

                // Without ?all=true dockerapi lists running containers only.
                return Http::response(str_contains($request->url(), 'all=true') ? $all : array_filter($all, fn (array $c): bool => $c['State']['Running'] === true));
            }

            return $answer instanceof \Closure ? $answer($request) : (is_string($answer) ? Http::response($answer, 200, ['Content-Type' => 'text/plain']) : Http::response($answer));
        });
    }

    public function test_containers_lists_every_container_of_the_project_including_stopped_ones(): void
    {
        $this->fake();

        $containers = $this->client()->containers();

        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === 'https://dockerapi/containers/json?all=true');
        $services = array_map(fn (ContainerSnapshot $c): string => $c->service, $containers);
        $this->assertCount(19, $containers);
        $this->assertContains('sogo-mailcow', $services);
        $this->assertContains('clamd-mailcow', $services);
        $this->assertNotContains('website', $services, 'a container of another project was listed');
    }

    public function test_the_project_matches_case_insensitively(): void
    {
        config(['restv2.compose_project_name' => 'MailcowDockerized']);
        $this->fake();

        $this->assertCount(19, $this->client()->containers());
    }

    public function test_states_times_and_health_are_mapped(): void
    {
        $this->fake();

        $byService = [];
        foreach ($this->client()->containers() as $container) {
            $byService[$container->service] = $container;
        }

        $sogo = $byService['sogo-mailcow'];
        $this->assertFalse($sogo->running);
        $this->assertSame('exited', $sogo->status);
        $this->assertSame('2026-10-17T22:15:30Z', $sogo->startedAt);
        $this->assertSame(MonitoringFixtures::containerId('sogo-mailcow'), $sogo->id);
        $this->assertSame('ghcr.io/mailcow/sogo:5.12.11-1', $sogo->image);

        $this->assertTrue($byService['postfix-mailcow']->running);
        $this->assertSame('2026-10-18T06:00:00Z', $byService['postfix-mailcow']->startedAt);
        $this->assertNull($byService['postfix-mailcow']->health, 'an absent health check is null');
        $this->assertNull($byService['memcached-mailcow']->health, 'Docker health "none" is null');
        $this->assertSame('healthy', $byService['nginx-mailcow']->health);
    }

    public function test_docker_zero_time_is_null(): void
    {
        $inspect = MonitoringFixtures::containers()[MonitoringFixtures::containerId('acme-mailcow')];
        $inspect['State']['StartedAt'] = '0001-01-01T00:00:00Z';

        $this->assertNull(ContainerSnapshot::fromInspect($inspect)?->startedAt);
    }

    public function test_no_environment_value_survives_parsing(): void
    {
        $this->fake();

        $containers = $this->client()->containers();
        $serialised = serialize($containers);
        $json = (string) json_encode(array_map(fn (ContainerSnapshot $c): array => $c->toArray(), $containers));

        foreach (MonitoringFixtures::PLANTED_SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $serialised);
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function actions(): array
    {
        return ['start' => ['start'], 'stop' => ['stop'], 'restart' => ['restart']];
    }

    #[DataProvider('actions')]
    public function test_actions_find_a_stopped_container(string $action): void
    {
        $this->fake();

        $result = $this->client()->{$action}('sogo-mailcow');

        $this->assertTrue($result->isSuccess());
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === 'https://dockerapi/containers/json?all=true');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === 'https://dockerapi/containers/'.MonitoringFixtures::containerId('sogo-mailcow').'/'.$action);
    }

    public function test_an_action_on_an_unknown_service_is_an_unreachable_failure(): void
    {
        $this->fake();

        $result = $this->client()->start('unknown-mailcow');

        $this->assertTrue($result->isUnreachable());
        Http::assertSentCount(1);
    }

    public function test_a_danger_answer_of_an_action_is_a_failure_with_its_message(): void
    {
        $this->fake(['type' => 'danger', 'msg' => 'x']);

        $result = $this->client()->stop('postfix-mailcow');

        $this->assertSame(DockerCallResult::FAILED, $result->status);
        $this->assertFalse($result->isUnreachable());
        $this->assertSame('x', $result->message);
    }

    public function test_an_action_timeout_has_an_unknown_outcome(): void
    {
        $this->fake(fn () => Http::failedConnection('cURL error 28: Operation timed out after 60001 milliseconds'));

        $this->assertTrue($this->client()->restart('postfix-mailcow')->isUnknown());
    }

    public function test_an_unreachable_service_fails_an_action_as_unreachable(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to dockerapi port 443'));

        $this->assertTrue($this->client()->start('postfix-mailcow')->isUnreachable());
    }

    public function test_container_stats_post_to_the_update_endpoint_with_a_five_second_timeout(): void
    {
        $samples = MonitoringFixtures::stats('stats-postfix-3.json');
        $this->fake($samples);

        $result = $this->client()->containerStats('postfix-mailcow');

        $this->assertSame($samples, $result);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === 'https://dockerapi/container/'.MonitoringFixtures::containerId('postfix-mailcow').'/stats/update');
        $stats = array_values(array_filter($this->options, fn (array $o): bool => str_contains($o['url'], '/stats/update')));
        $this->assertEquals(5, $stats[0]['timeout']);
        $this->assertFalse($stats[0]['verify']);
    }

    public function test_container_stats_of_a_stopped_container_are_empty(): void
    {
        $this->fake([]);

        $this->assertSame([], $this->client()->containerStats('sogo-mailcow'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/stats/update'));
    }

    public function test_host_stats_use_a_five_second_timeout(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->options[] = $options;

            return Http::response(MonitoringFixtures::hostStats());
        });

        $stats = $this->client()->hostStats();

        $this->assertSame(8, $stats['cpu']['cores']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && $r->url() === 'https://dockerapi/host/stats');
        $this->assertEquals(5, $this->options[0]['timeout']);
        $this->assertFalse($this->options[0]['verify']);
    }

    public function test_an_unreachable_service_fails_a_read(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect to dockerapi port 443'));

        try {
            $this->client()->containers();
            $this->fail('no exception');
        } catch (DockerApiUnreachable $e) {
            $this->assertNotInstanceOf(DockerApiTimeout::class, $e);
        }
    }

    public function test_a_read_timeout_is_a_timeout(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds'));

        $this->expectException(DockerApiTimeout::class);
        $this->client()->hostStats();
    }

    public function test_a_danger_answer_of_the_container_list_is_unreachable(): void
    {
        Http::fake(fn () => Http::response(['type' => 'danger', 'msg' => 'docker socket closed']));

        $this->expectException(DockerApiUnreachable::class);
        $this->client()->containers();
    }

    public function test_reads_and_actions_use_docker_timeout(): void
    {
        config(['restv2.vars_overrides.DOCKER_TIMEOUT' => '42']);
        $this->fake();

        $this->client()->containers();
        $this->client()->restart('postfix-mailcow');

        foreach ($this->options as $options) {
            $this->assertEquals(42, $options['timeout']);
            $this->assertFalse($options['verify']);
        }
    }

    public function test_exec_text_returns_a_text_answer(): void
    {
        $output = MonitoringFixtures::text('postqueue-j.txt');
        $this->fake($output);

        $result = $this->client()->execText('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame($output, $result->data);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
            && $r->url() === 'https://dockerapi/containers/'.MonitoringFixtures::containerId('postfix-mailcow').'/exec'
            && json_decode($r->body(), true) === ['cmd' => 'mailq', 'task' => 'list']);
    }

    public function test_exec_text_decodes_a_json_string_answer(): void
    {
        $this->fake(fn () => Http::response('"/dev/sda1,107G,27G,76G,26%,/var/vmail"', 200, ['Content-Type' => 'application/json']));

        $result = $this->client()->execText('dovecot-mailcow', ['cmd' => 'system', 'task' => 'df', 'dir' => '/var/vmail']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('/dev/sda1,107G,27G,76G,26%,/var/vmail', $result->data);
    }

    public function test_exec_text_null_answer_is_a_failure(): void
    {
        $this->fake(fn () => Http::response('null', 200, ['Content-Type' => 'application/json']));

        $result = $this->client()->execText('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list']);

        $this->assertSame(DockerCallResult::FAILED, $result->status);
        $this->assertFalse($result->isUnreachable());
    }

    public function test_exec_text_danger_answer_is_a_failure_with_its_message(): void
    {
        $this->fake(['type' => 'danger', 'msg' => 'postqueue: fatal: Queue report unavailable']);

        $result = $this->client()->execText('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list']);

        $this->assertSame(DockerCallResult::FAILED, $result->status);
        $this->assertSame('postqueue: fatal: Queue report unavailable', $result->message);
    }

    public function test_exec_text_timeout_has_an_unknown_outcome(): void
    {
        $this->fake(fn () => Http::failedConnection('cURL error 28: Operation timed out'));

        $this->assertTrue($this->client()->execText('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'flush'])->isUnknown());
    }

    public function test_exec_text_on_an_unreachable_service_is_unreachable(): void
    {
        Http::fake(fn () => Http::failedConnection('cURL error 7: Failed to connect'));

        $this->assertTrue($this->client()->execText('postfix-mailcow', ['cmd' => 'mailq', 'task' => 'list'])->isUnreachable());
    }

    public function test_exec_keeps_looking_up_running_containers_only(): void
    {
        $this->fake();

        $result = $this->client()->exec('sogo-mailcow', ['cmd' => 'reload', 'task' => 'sogo']);

        $this->assertTrue($result->isUnreachable(), 'a stopped container cannot run an exec command');
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://dockerapi/containers/json');
    }
}
