<?php

namespace Tests\Support\Contract;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit extension (FR-051, research R13). When the run finishes, it writes every contract
 * operation that a test answered with a documented 2xx or 3xx status to
 * build/contract/coverage.json. `php tests/bin/check-contract-coverage.php` then fails for
 * operations that are in scope but were never covered.
 */
final class ContractCoverageExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $output = $parameters->has('output')
            ? $parameters->get('output')
            : dirname(__DIR__, 3).'/build/contract/coverage.json';

        $facade->registerSubscriber(new class($output) implements ExecutionFinishedSubscriber
        {
            public function __construct(private readonly string $output) {}

            public function notify(ExecutionFinished $event): void
            {
                if (! is_dir(dirname($this->output))) {
                    mkdir(dirname($this->output), 0775, true);
                }

                file_put_contents($this->output, json_encode([
                    'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                    'operations' => ContractCoverageRecorder::operations(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            }
        });
    }
}
