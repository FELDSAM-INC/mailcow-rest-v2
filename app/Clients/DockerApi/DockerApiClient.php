<?php

namespace App\Clients\DockerApi;

/**
 * mailcow's container service, dockerapi (feature 002 research D14, feature 003 research R16).
 * Calls are synchronous and bounded by DOCKER_TIMEOUT; a timeout has an unknown outcome. Faked in
 * tests.
 */
interface DockerApiClient
{
    /** Restarts the container of a compose service, for example `sogo-mailcow`. */
    public function restart(string $service): DockerCallResult;

    /**
     * Sends an exec command to the container of a compose service, for example
     * `exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org'])`.
     * dockerapi answers `{"type": "success"|"danger", "msg": ...}`.
     *
     * @param  array<string, mixed>  $command
     */
    public function exec(string $service, array $command): DockerCallResult;

    /**
     * Sends an exec command whose answer is JSON data rather than dockerapi's `{type, msg}`, for
     * example `doveadm get_acl`, which answers a list of ACL entries. A successful result carries
     * the decoded data.
     *
     * @param  array<string, mixed>  $command
     */
    public function query(string $service, array $command): DockerCallResult;

    /**
     * Whether a running container of the compose service can be found, without sending any
     * command (preflight checks and the deletion preview).
     */
    public function reachable(string $service): bool;

    /**
     * Legacy docker('broadcast'): publishes the exec command on mailcow's `MC_CHANNEL`, so that
     * the container of the service on every node of a replicated cluster runs it. Unconfirmed.
     *
     * @param  array<string, mixed>  $command
     */
    public function broadcast(string $service, array $command): void;
}
