<?php

namespace Tests\Support;

use RuntimeException;

/**
 * tests/feature-registry.json: the implemented features, their contract operations and their
 * problem types. The shared contract and docs/problems.md also describe planned features; the
 * contract coverage check, the route check and the problem type check only consider the features
 * listed here.
 */
final class FeatureRegistry
{
    /**
     * @param  array<string, array{operations: list<string>, problemTypes: list<string>}>  $features
     * @param  list<string>  $pendingOperations
     */
    private function __construct(
        public readonly array $features,
        public readonly array $pendingOperations,
    ) {}

    public static function load(?string $path = null): self
    {
        $path ??= dirname(__DIR__).'/feature-registry.json';
        $data = json_decode((string) @file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['features'] ?? null)) {
            throw new RuntimeException("{$path} is missing or not valid JSON.");
        }

        $features = [];

        foreach ($data['features'] as $name => $feature) {
            $features[(string) $name] = [
                'operations' => array_values($feature['operations'] ?? []),
                'problemTypes' => array_values($feature['problemTypes'] ?? []),
            ];
        }

        return new self($features, array_values($data['pendingOperations'] ?? []));
    }

    /**
     * Operations of every implemented feature, pending ones included.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return array_values(array_unique(array_merge([], ...array_column($this->features, 'operations'))));
    }

    /**
     * Operations that must be covered by the tests now.
     *
     * @return list<string>
     */
    public function implementedOperations(): array
    {
        return array_values(array_diff($this->operations(), $this->pendingOperations));
    }

    /**
     * @return list<string>
     */
    public function problemTypes(): array
    {
        return array_values(array_unique(array_merge([], ...array_column($this->features, 'problemTypes'))));
    }

    public function featureOf(string $operationId): ?string
    {
        foreach ($this->features as $name => $feature) {
            if (in_array($operationId, $feature['operations'], true)) {
                return $name;
            }
        }

        return null;
    }
}
