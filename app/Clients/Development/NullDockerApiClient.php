<?php

namespace App\Clients\Development;

use App\Clients\DockerApi\ContainerSnapshot;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerApiUnreachable;
use App\Clients\DockerApi\DockerCallResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Development stand-in for dockerapi (RESTV2_FAKE_OUTBOUND=y with APP_ENV=local): the dev stack runs
 * no mailcow containers, so restarts and exec commands are logged and reported as done, and queries
 * answer an empty list.
 *
 * Feature 011: the reads answer the recorded payloads of tests/fixtures/monitoring (containers,
 * host figures, resource samples, `df`, `postqueue -j`, `postcat`), so that the monitoring
 * operations can be tried in the dev stack; container actions change nothing.
 */
final class NullDockerApiClient implements DockerApiClient
{
    public function restart(string $service): DockerCallResult
    {
        Log::info("Development: dockerapi restart of {$service} skipped.");

        return DockerCallResult::success('skipped in development');
    }

    public function exec(string $service, array $command): DockerCallResult
    {
        Log::info("Development: dockerapi exec in {$service} skipped.", ['command' => $command]);

        return DockerCallResult::success('skipped in development');
    }

    public function query(string $service, array $command): DockerCallResult
    {
        Log::info("Development: dockerapi query in {$service} answered with an empty list.", ['command' => $command]);

        return DockerCallResult::success(null, []);
    }

    public function reachable(string $service): bool
    {
        return true;
    }

    public function broadcast(string $service, array $command): void
    {
        Log::info("Development: dockerapi broadcast to {$service} skipped.", ['command' => $command]);
    }

    public function execText(string $service, array $command): DockerCallResult
    {
        $file = match (($command['cmd'] ?? '').' '.($command['task'] ?? '')) {
            'system df' => 'df-ok.txt',
            'mailq list' => 'postqueue-j.txt',
            'mailq cat' => 'postcat-ok.txt',
            default => null,
        };

        Log::info("Development: dockerapi exec in {$service} answered from the recorded fixtures.", ['command' => $command]);

        return $file === null ? DockerCallResult::success(null, '') : DockerCallResult::success(null, rtrim(self::fixture($file), "\n"));
    }

    public function containers(): array
    {
        return ContainerSnapshot::listForProject(json_decode(self::fixture('containers.json'), true), 'mailcowdockerized');
    }

    public function start(string $service): DockerCallResult
    {
        Log::info("Development: dockerapi start of {$service} skipped.");

        return DockerCallResult::success('skipped in development');
    }

    public function stop(string $service): DockerCallResult
    {
        Log::info("Development: dockerapi stop of {$service} skipped.");

        return DockerCallResult::success('skipped in development');
    }

    public function containerStats(string $service, float $timeout = 5.0): array
    {
        $samples = json_decode(self::fixture('stats-postfix-3.json'), true);

        return is_array($samples) ? array_values(array_filter($samples, 'is_array')) : [];
    }

    public function hostStats(): array
    {
        $stats = json_decode(self::fixture('host-stats.json'), true);

        if (! is_array($stats)) {
            throw new DockerApiUnreachable('The recorded host figures are missing.');
        }

        $stats['system_time'] = CarbonImmutable::now()->setTimezone((string) config('restv2.timezone', 'UTC'))->format('d.m.Y H:i:s');

        return $stats;
    }

    private static function fixture(string $name): string
    {
        $content = @file_get_contents(base_path('tests/fixtures/monitoring/'.$name));

        if ($content === false) {
            throw new DockerApiUnreachable("The recorded payload {$name} is missing (development stand-in).");
        }

        return $content;
    }
}
