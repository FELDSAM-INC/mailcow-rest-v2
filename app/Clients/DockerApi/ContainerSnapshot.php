<?php

namespace App\Clients\DockerApi;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The state of one container of mailcow's compose project as dockerapi reports it (feature 011
 * research R2). Only these fields are kept: the container environment (`Config.Env`, which holds
 * `DBPASS`, `REDISPASS` and other secrets) and every other field are dropped at parse time, so
 * nothing else can reach a cache, a log, a response or an audit record.
 */
final readonly class ContainerSnapshot
{
    public function __construct(
        public string $id,
        public string $service,
        public string $image,
        public string $status,
        public bool $running,
        public ?string $startedAt,
        public ?string $health,
    ) {}

    /**
     * The containers of a compose project from dockerapi's `GET /containers/json` answer (a map of
     * container ID to `docker inspect`), matched case-insensitively on
     * `com.docker.compose.project`, as legacy docker('info').
     *
     * @return list<self>
     */
    public static function listForProject(mixed $answer, string $project): array
    {
        if (! is_array($answer)) {
            throw new DockerApiUnreachable('The container service answered with an unexpected document.');
        }

        if (isset($answer['type']) && is_string($answer['type'])) {
            throw new DockerApiUnreachable('The container service reported a failure: '.(is_string($answer['msg'] ?? null) ? $answer['msg'] : 'unknown'));
        }

        $containers = [];

        foreach ($answer as $inspect) {
            if (! is_array($inspect)) {
                continue;
            }

            $labels = $inspect['Config']['Labels'] ?? null;

            if (! is_array($labels) || strtolower((string) ($labels['com.docker.compose.project'] ?? '')) !== strtolower($project)) {
                continue;
            }

            $snapshot = self::fromInspect($inspect);

            if ($snapshot !== null) {
                $containers[] = $snapshot;
            }
        }

        return $containers;
    }

    /**
     * One container from its `docker inspect` document, or null when it has no compose service
     * label or no valid ID.
     *
     * @param  array<mixed>  $inspect
     */
    public static function fromInspect(array $inspect): ?self
    {
        $service = $inspect['Config']['Labels']['com.docker.compose.service'] ?? null;
        $id = trim((string) ($inspect['Id'] ?? ''));

        if (! is_string($service) || $service === '' || $id === '' || ! ctype_xdigit($id)) {
            return null;
        }

        $state = is_array($inspect['State'] ?? null) ? $inspect['State'] : [];
        $health = $state['Health']['Status'] ?? null;
        $health = is_string($health) && in_array($health, ['starting', 'healthy', 'unhealthy'], true) ? $health : null;

        return new self(
            id: strtolower($id),
            service: $service,
            image: is_string($inspect['Config']['Image'] ?? null) ? $inspect['Config']['Image'] : '',
            status: is_string($state['Status'] ?? null) ? $state['Status'] : 'created',
            running: ($state['Running'] ?? false) === true,
            startedAt: self::time($state['StartedAt'] ?? null),
            health: $health,
        );
    }

    /**
     * Docker's start time as RFC 3339 UTC with seconds, or null for Docker's zero time
     * (`0001-01-01T00:00:00Z`, never started).
     */
    private static function time(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0001-01-01')) {
            return null;
        }

        try {
            // Docker reports nanoseconds; PHP parses at most microseconds.
            $normalised = (string) preg_replace('/(\.\d{6})\d+/', '$1', $value);

            return CarbonImmutable::parse($normalised)->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{id: string, service: string, image: string, status: string, running: bool, startedAt: string|null, health: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'service' => $this->service,
            'image' => $this->image,
            'status' => $this->status,
            'running' => $this->running,
            'startedAt' => $this->startedAt,
            'health' => $this->health,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  a toArray() result
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['service'] ?? ''),
            (string) ($data['image'] ?? ''),
            (string) ($data['status'] ?? 'created'),
            ($data['running'] ?? false) === true,
            isset($data['startedAt']) && is_string($data['startedAt']) ? $data['startedAt'] : null,
            isset($data['health']) && is_string($data['health']) ? $data['health'] : null,
        );
    }
}
