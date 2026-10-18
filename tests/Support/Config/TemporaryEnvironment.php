<?php

namespace Tests\Support\Config;

use PHPUnit\Framework\Attributes\After;

/**
 * Sets process environment variables for one test, where Docker puts them and Laravel's env
 * repository reads them ($_SERVER, $_ENV and getenv()), and restores them after the test. A null
 * value unsets the variable.
 */
trait TemporaryEnvironment
{
    /** @var array<string, array{server: array{bool, mixed}, env: array{bool, mixed}, getenv: string|false}> */
    private array $savedEnvironment = [];

    /**
     * @param  array<string, string|null>  $variables
     */
    protected function setEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->savedEnvironment[$name] ??= [
                'server' => [array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null],
                'env' => [array_key_exists($name, $_ENV), $_ENV[$name] ?? null],
                'getenv' => getenv($name),
            ];

            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv("{$name}={$value}");
            }
        }
    }

    #[After]
    protected function restoreEnvironment(): void
    {
        foreach ($this->savedEnvironment as $name => $saved) {
            [$inServer, $server] = $saved['server'];
            [$inEnv, $env] = $saved['env'];

            if ($inServer) {
                $_SERVER[$name] = $server;
            } else {
                unset($_SERVER[$name]);
            }

            if ($inEnv) {
                $_ENV[$name] = $env;
            } else {
                unset($_ENV[$name]);
            }

            putenv($saved['getenv'] === false ? $name : "{$name}={$saved['getenv']}");
        }

        $this->savedEnvironment = [];
    }
}
