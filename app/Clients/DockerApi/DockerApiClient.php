<?php

namespace App\Clients\DockerApi;

/**
 * mailcow's container service, dockerapi (feature 002 research D14). Calls are synchronous and
 * bounded by DOCKER_TIMEOUT; a timeout has an unknown outcome. Faked in tests.
 */
interface DockerApiClient
{
    /** Restarts the container of a compose service, for example `sogo-mailcow`. */
    public function restart(string $service): DockerCallResult;

    /**
     * Sends an exec command to the container of a compose service, for example
     * `exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org'])`.
     *
     * @param  array<string, mixed>  $command
     */
    public function exec(string $service, array $command): DockerCallResult;
}
