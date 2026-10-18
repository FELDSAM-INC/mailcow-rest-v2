<?php

namespace App\Clients\Development;

use App\Clients\DockerApi\DockerApiClient;
use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\Log;

/**
 * Development stand-in for dockerapi (RESTV2_FAKE_OUTBOUND=y with APP_ENV=local): the dev stack runs
 * no mailcow containers, so restarts and exec commands are logged and reported as done, and queries
 * answer an empty list.
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
}
