<?php

namespace App\Clients\DockerApi;

use App\Services\Config\MailcowConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Legacy docker() (functions.docker.inc.php) over Laravel's HTTP client: the container is the one
 * whose labels `com.docker.compose.service` equal the service and `com.docker.compose.project` the
 * compose project (case-insensitive); then `POST /containers/<id>/<action>`. dockerapi presents
 * mailcow's own certificate under another name, so TLS is not verified, as in legacy.
 */
class HttpDockerApiClient implements DockerApiClient
{
    public function __construct(private readonly MailcowConfig $config) {}

    public function restart(string $service): DockerCallResult
    {
        return $this->post($service, 'restart', null);
    }

    public function exec(string $service, array $command): DockerCallResult
    {
        return $this->post($service, 'exec', $command);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function post(string $service, string $action, ?array $body): DockerCallResult
    {
        try {
            $id = $this->containerId($service);

            if ($id === null) {
                return DockerCallResult::failed("No running container of {$service} in compose project ".$this->project().'.');
            }

            $request = $this->request();
            $response = $body === null
                ? $request->post($this->url("/containers/{$id}/{$action}"))
                : $request->post($this->url("/containers/{$id}/{$action}"), $body);
            $answer = $response->json();

            if (is_array($answer) && ($answer['type'] ?? null) === 'success') {
                return DockerCallResult::success(isset($answer['msg']) && is_string($answer['msg']) ? $answer['msg'] : null);
            }

            $message = is_array($answer) && isset($answer['msg']) ? (is_string($answer['msg']) ? $answer['msg'] : (string) json_encode($answer['msg'])) : 'HTTP '.$response->status();

            return DockerCallResult::failed($message);
        } catch (ConnectionException $e) {
            return $this->isTimeout($e)
                ? DockerCallResult::unknown('The container service did not answer in time.')
                : DockerCallResult::failed('The container service is unreachable: '.$e->getMessage());
        } catch (Throwable $e) {
            return DockerCallResult::failed($e->getMessage());
        }
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
