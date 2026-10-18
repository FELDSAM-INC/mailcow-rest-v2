<?php

namespace App\Services\Monitoring;

use App\Clients\DockerApi\ContainerSnapshot;

/**
 * The contract representation of a container (`Container`, feature 011 FR-008): state, health,
 * protection, role, the available actions and when the states were collected.
 */
class ContainerPresenter
{
    private const STATES = ['running', 'restarting', 'paused', 'exited', 'created', 'dead', 'removing'];

    public function __construct(private readonly ContainerRegistry $registry) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ContainerSnapshot $container, string $collectedAt): array
    {
        return [
            'name' => $container->service,
            'image' => $container->image,
            'state' => in_array($container->status, self::STATES, true) ? $container->status : ($container->running ? 'running' : 'dead'),
            'running' => $container->running,
            'startedAt' => $container->startedAt,
            'health' => $container->health,
            'protected' => $this->registry->isProtected($container),
            'role' => $this->registry->role($container),
            'availableActions' => $this->registry->availableActions($container),
            'collectedAt' => $collectedAt,
        ];
    }
}
