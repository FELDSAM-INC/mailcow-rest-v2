<?php

namespace App\Clients\DockerApi;

use App\Clients\SharedStore\SharedStore;
use App\Services\Config\MailcowConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Redis;
use Throwable;

/**
 * Legacy docker() (functions.docker.inc.php) over Laravel's HTTP client: the container is the one
 * whose labels `com.docker.compose.service` equal the service and `com.docker.compose.project` the
 * compose project (case-insensitive); then `POST /containers/<id>/<action>`. dockerapi presents
 * mailcow's own certificate under another name, so TLS is not verified, as in legacy.
 *
 * A connection error or a missing container is an unreachable failure, a timeout an unknown
 * outcome. Broadcasts go through mailcow's Redis (`MC_CHANNEL`), as legacy docker('broadcast').
 *
 * Feature 011 (research R2): exec commands find running containers only (legacy get_id), while
 * start, stop and restart look the ID up in `GET /containers/json?all=true`, so that a stopped
 * container is found. The container list, resource samples and host figures are reads that throw
 * DockerApiUnreachable or DockerApiTimeout; resource samples and host figures wait 5 seconds at
 * most. Container environments are dropped while the list is parsed (ContainerSnapshot).
 */
class HttpDockerApiClient implements DockerApiClient
{
    public function __construct(
        private readonly MailcowConfig $config,
        private readonly SharedStore $store,
    ) {}

    public function restart(string $service): DockerCallResult
    {
        return $this->post($service, 'restart', null, false, true);
    }

    public function exec(string $service, array $command): DockerCallResult
    {
        return $this->post($service, 'exec', $command, false);
    }

    public function query(string $service, array $command): DockerCallResult
    {
        return $this->post($service, 'exec', $command, true);
    }

    public function reachable(string $service): bool
    {
        try {
            return $this->containerId($service) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function execText(string $service, array $command): DockerCallResult
    {
        try {
            $id = $this->containerId($service);

            if ($id === null) {
                return DockerCallResult::unreachable("No running container of {$service} in compose project ".$this->project().'.');
            }

            return self::text($this->request()->post($this->url("/containers/{$id}/exec"), $command));
        } catch (ConnectionException $e) {
            return $this->isTimeout($e)
                ? DockerCallResult::unknown('The container service did not answer in time.')
                : DockerCallResult::unreachable('The container service is unreachable: '.$e->getMessage());
        } catch (Throwable $e) {
            return DockerCallResult::failed($e->getMessage());
        }
    }

    public function containers(): array
    {
        return ContainerSnapshot::listForProject($this->read(fn (): mixed => $this->request()->get($this->url('/containers/json?all=true'))->json()), $this->project());
    }

    public function start(string $service): DockerCallResult
    {
        return $this->post($service, 'start', null, false, true);
    }

    public function stop(string $service): DockerCallResult
    {
        return $this->post($service, 'stop', null, false, true);
    }

    public function containerStats(string $service, float $timeout = 5.0): array
    {
        return $this->read(function () use ($service, $timeout): array {
            $id = $this->containerId($service, false, $timeout);

            if ($id === null) {
                return [];
            }

            $samples = $this->request($timeout)->post($this->url("/container/{$id}/stats/update"))->json();

            if (! is_array($samples) || ! array_is_list($samples)) {
                throw new DockerApiUnreachable('The container service answered the resource samples with an unexpected document.');
            }

            return array_values(array_filter($samples, 'is_array'));
        });
    }

    public function hostStats(): array
    {
        $stats = $this->read(fn (): mixed => $this->request(5.0)->get($this->url('/host/stats'))->json());

        if (! is_array($stats) || ! isset($stats['cpu'], $stats['memory'])) {
            throw new DockerApiUnreachable('The container service answered the host figures with an unexpected document.');
        }

        return $stats;
    }

    public function broadcast(string $service, array $command): void
    {
        $message = (string) json_encode([
            'api_call' => 'container_post',
            'container_name' => $service,
            'post_action' => 'exec',
            'request' => $command,
        ]);

        $this->store->run(fn (Redis $redis): mixed => $redis->publish('MC_CHANNEL', $message));
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function post(string $service, string $action, ?array $body, bool $expectsData, bool $anyState = false): DockerCallResult
    {
        try {
            $id = $this->containerId($service, $anyState);

            if ($id === null) {
                return DockerCallResult::unreachable(($anyState ? 'No container of ' : 'No running container of ')."{$service} in compose project ".$this->project().'.');
            }

            $request = $this->request();
            $response = $body === null
                ? $request->post($this->url("/containers/{$id}/{$action}"))
                : $request->post($this->url("/containers/{$id}/{$action}"), $body);

            return $expectsData ? self::data($response) : self::answer($response);
        } catch (ConnectionException $e) {
            return $this->isTimeout($e)
                ? DockerCallResult::unknown('The container service did not answer in time.')
                : DockerCallResult::unreachable('The container service is unreachable: '.$e->getMessage());
        } catch (Throwable $e) {
            return DockerCallResult::failed($e->getMessage());
        }
    }

    /** dockerapi's `{type, msg}` answer. */
    private static function answer(Response $response): DockerCallResult
    {
        $answer = $response->json();

        if (is_array($answer) && ($answer['type'] ?? null) === 'success') {
            return DockerCallResult::success(isset($answer['msg']) && is_string($answer['msg']) ? $answer['msg'] : null);
        }

        $message = is_array($answer) && isset($answer['msg']) ? (is_string($answer['msg']) ? $answer['msg'] : (string) json_encode($answer['msg'])) : 'HTTP '.$response->status();

        return DockerCallResult::failed($message);
    }

    /** A command answering a JSON list, such as `doveadm get_acl`. */
    private static function data(Response $response): DockerCallResult
    {
        $answer = $response->json();

        if ($response->successful() && is_array($answer) && array_is_list($answer)) {
            return DockerCallResult::success(null, $answer);
        }

        $message = is_array($answer) && isset($answer['msg']) && is_string($answer['msg']) ? $answer['msg'] : 'Unexpected answer (HTTP '.$response->status().').';

        return DockerCallResult::failed($message);
    }

    /**
     * A text or JSON-string answer (`postqueue -j`, `postcat -q`, `system df`); dockerapi's `null`
     * (the container is not running) and `{"type": "danger"}` are failures.
     */
    private static function text(Response $response): DockerCallResult
    {
        $body = $response->body();
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $response->successful() ? DockerCallResult::success(null, $body) : DockerCallResult::failed('HTTP '.$response->status());
        }

        return match (true) {
            is_string($decoded) => DockerCallResult::success(null, $decoded),
            $decoded === null => DockerCallResult::failed('The container service returned no answer: the container is not running.'),
            is_array($decoded) && ($decoded['type'] ?? null) === 'success' => DockerCallResult::success(is_string($decoded['msg'] ?? null) ? $decoded['msg'] : null, is_string($decoded['msg'] ?? null) ? $decoded['msg'] : ''),
            is_array($decoded) && isset($decoded['msg']) => DockerCallResult::failed(is_string($decoded['msg']) ? $decoded['msg'] : (string) json_encode($decoded['msg'])),
            default => DockerCallResult::success(null, $body),
        };
    }

    /**
     * Runs a read, mapping connection errors to DockerApiUnreachable and timeouts to
     * DockerApiTimeout.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function read(callable $read): mixed
    {
        try {
            return $read();
        } catch (ConnectionException $e) {
            throw $this->isTimeout($e)
                ? new DockerApiTimeout('The container service did not answer in time.', 0, $e)
                : new DockerApiUnreachable('The container service is unreachable: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The ID of the service's container: running containers only, as legacy docker('get_id'), or
     * any state for start, stop and restart (feature 011 R2).
     */
    private function containerId(string $service, bool $anyState = false, ?float $timeout = null): ?string
    {
        $containers = $this->request($timeout)->get($this->url($anyState ? '/containers/json?all=true' : '/containers/json'))->json();

        foreach (is_array($containers) ? $containers : [] as $container) {
            $labels = is_array($container) ? ($container['Config']['Labels'] ?? null) : null;

            if (is_array($labels)
                && ($labels['com.docker.compose.service'] ?? null) === $service
                && strtolower((string) ($labels['com.docker.compose.project'] ?? '')) === strtolower($this->project())) {
                $id = trim((string) ($container['Id'] ?? ''));

                return ctype_xdigit($id) ? $id : null;
            }
        }

        return null;
    }

    private function request(?float $timeout = null): PendingRequest
    {
        return Http::withoutVerifying()
            ->acceptJson()
            ->asJson()
            ->timeout($timeout ?? $this->config->dockerTimeout());
    }

    private function url(string $path): string
    {
        return rtrim((string) config('restv2.dockerapi.url'), '/').$path;
    }

    private function project(): string
    {
        return (string) config('restv2.compose_project_name');
    }

    private function isTimeout(ConnectionException $e): bool
    {
        return str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;
    }
}
