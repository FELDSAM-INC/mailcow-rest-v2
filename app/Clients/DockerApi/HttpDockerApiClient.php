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
 */
class HttpDockerApiClient implements DockerApiClient
{
    public function __construct(
        private readonly MailcowConfig $config,
        private readonly SharedStore $store,
    ) {}

    public function restart(string $service): DockerCallResult
    {
        return $this->post($service, 'restart', null, false);
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
    private function post(string $service, string $action, ?array $body, bool $expectsData): DockerCallResult
    {
        try {
            $id = $this->containerId($service);

            if ($id === null) {
                return DockerCallResult::unreachable("No running container of {$service} in compose project ".$this->project().'.');
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

    private function containerId(string $service): ?string
    {
        $containers = $this->request()->get($this->url('/containers/json'))->json();

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

    private function request(): PendingRequest
    {
        return Http::withoutVerifying()
            ->acceptJson()
            ->asJson()
            ->timeout($this->config->dockerTimeout());
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
