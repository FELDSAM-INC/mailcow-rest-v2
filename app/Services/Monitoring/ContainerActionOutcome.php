<?php

namespace App\Services\Monitoring;

use App\Clients\DockerApi\ContainerSnapshot;

/**
 * The outcome of a container action (contract `ContainerActionResult`): `done` or `alreadyInState`,
 * the container read again afterwards and when, and whether the audit record was written.
 */
final readonly class ContainerActionOutcome
{
    public function __construct(
        public string $action,
        public string $outcome,
        public ContainerSnapshot $container,
        public string $collectedAt,
        public bool $auditRecorded,
    ) {}
}
