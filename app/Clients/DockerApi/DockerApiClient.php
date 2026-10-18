<?php

namespace App\Clients\DockerApi;

/**
 * mailcow's container service, dockerapi (feature 002 research D14, feature 003 research R16,
 * feature 011 research R2).
 * Calls are synchronous and bounded by DOCKER_TIMEOUT unless noted; a timeout has an unknown
 * outcome. Faked in tests.
 */
interface DockerApiClient
{
    /**
     * Restarts the container of a compose service, for example `sogo-mailcow`, whatever its state:
     * a stopped container is started (feature 011 research R2).
     */
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

    /**
     * Feature 011 (research R2): sends an exec command whose answer is text (`postqueue -j`,
     * `postcat -q`) or a JSON string (`system df`) rather than `{type, msg}`. A successful result
     * carries the text as `data`; dockerapi's `null` answer (the container is not running) and a
     * `{"type": "danger"}` answer are failures.
     *
     * @param  array<string, mixed>  $command
     */
    public function execText(string $service, array $command): DockerCallResult;

    /**
     * Feature 011: the containers of mailcow's compose project, including stopped ones
     * (`GET /containers/json?all=true`), in dockerapi's order. Containers of other projects are
     * left out; environments are dropped at parse time.
     *
     * @return list<ContainerSnapshot>
     *
     * @throws DockerApiUnreachable when the container service cannot be reached
     * @throws DockerApiTimeout when it does not answer within DOCKER_TIMEOUT
     */
    public function containers(): array;

    /** Feature 011: starts the container of a compose service, also a stopped one. */
    public function start(string $service): DockerCallResult;

    /** Feature 011: stops the container of a compose service. */
    public function stop(string $service): DockerCallResult;

    /**
     * Feature 011: the raw `docker stats` samples dockerapi keeps for the running container of a
     * compose service (`POST /container/{id}/stats/update`, at most three samples about 5 seconds
     * apart), oldest first; an empty list when no running container of the service exists.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DockerApiUnreachable
     * @throws DockerApiTimeout when no answer arrived within $timeout seconds
     */
    public function containerStats(string $service, float $timeout = 5.0): array;

    /**
     * Feature 011: dockerapi's host figures (`GET /host/stats`, psutil; dockerapi caches them for
     * 10 seconds).
     *
     * @return array<string, mixed>
     *
     * @throws DockerApiUnreachable
     * @throws DockerApiTimeout when no answer arrived within 5 seconds
     */
    public function hostStats(): array;
}
