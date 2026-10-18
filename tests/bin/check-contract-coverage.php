<?php

/*
 * Contract coverage check (FR-051, research R13), run by `composer test` after PHPUnit.
 *
 * Scope: tests/feature-registry.json. Every operation of an implemented feature must appear in
 * build/contract/coverage.json, which ContractCoverageExtension writes (a test answered it with a
 * documented 2xx or 3xx status), unless it is in `pendingOperations`. The check also fails when a
 * registered operation is missing from the contract, and when a pending operation is already
 * covered (remove it from `pendingOperations`). Operations of features that are only planned are
 * out of scope.
 */

use Tests\Support\FeatureRegistry;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

/**
 * @return array<mixed>
 */
function read_json(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "contract coverage: {$path} is missing\n");
        exit(2);
    }

    $data = json_decode((string) file_get_contents($path), true);

    if (! is_array($data)) {
        fwrite(STDERR, "contract coverage: {$path} is not valid JSON\n");
        exit(2);
    }

    return $data;
}

$contract = read_json($root.'/build/contract/openapi.json');
$coverage = read_json($root.'/build/contract/coverage.json');
$registry = FeatureRegistry::load();

$contractIds = [];
foreach ($contract['paths'] ?? [] as $item) {
    foreach (['get', 'put', 'post', 'delete', 'patch', 'options', 'head', 'trace'] as $method) {
        if (isset($item[$method]['operationId'])) {
            $contractIds[] = $item[$method]['operationId'];
        }
    }
}

$covered = $coverage['operations'] ?? [];
$failures = [];
$coveredCount = 0;

foreach ($registry->features as $feature => $definition) {
    foreach ($definition['operations'] as $operationId) {
        if (! in_array($operationId, $contractIds, true)) {
            $failures[] = "{$feature}: {$operationId} is not in the contract";

            continue;
        }

        $isCovered = in_array($operationId, $covered, true);
        $isPending = in_array($operationId, $registry->pendingOperations, true);
        $coveredCount += $isCovered ? 1 : 0;

        if ($isCovered && $isPending) {
            $failures[] = "{$feature}: {$operationId} is covered; remove it from pendingOperations";
        } elseif (! $isCovered && ! $isPending) {
            $failures[] = "{$feature}: {$operationId} was never answered with a documented 2xx or 3xx status";
        }
    }
}

fwrite(STDOUT, sprintf(
    "contract coverage: %d of %d registered operations covered, %d pending\n",
    $coveredCount,
    count($registry->operations()),
    count($registry->pendingOperations),
));

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_map(fn (string $f): string => "  - {$f}", $failures))."\n");
    exit(1);
}
