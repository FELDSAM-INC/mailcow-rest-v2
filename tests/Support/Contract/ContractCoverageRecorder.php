<?php

namespace Tests\Support\Contract;

/**
 * Operations the test run answered with a documented 2xx or 3xx status, collected by
 * OpenApiContract::shared() and written by ContractCoverageExtension when the run finishes.
 */
final class ContractCoverageRecorder
{
    /** @var array<string, true> */
    private static array $operations = [];

    public static function record(string $operationId): void
    {
        self::$operations[$operationId] = true;
    }

    /**
     * @return list<string>
     */
    public static function operations(): array
    {
        $operations = array_keys(self::$operations);
        sort($operations);

        return $operations;
    }
}
