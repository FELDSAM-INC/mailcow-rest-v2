<?php

namespace Tests\Support\Monitoring;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The recorded payloads of tests/fixtures/monitoring (feature 011 T005, T007): dockerapi answers,
 * `postqueue -j` and `postcat` output, Rspamd controller answers and GitHub releases.
 */
final class MonitoringFixtures
{
    public const DIR = __DIR__.'/../../fixtures/monitoring';

    /** The planted environment values of containers.json that must never leave the API. */
    public const PLANTED_SECRETS = ['planted-secret', 'planted-secret-root', 'planted-secret-redis', 'planted-secret-api-key', 'planted-secret-health'];

    /**
     * @return array<string, mixed>
     */
    public static function containers(): array
    {
        return self::json('containers.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function hostStats(): array
    {
        return self::json('host-stats.json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function stats(string $name): array
    {
        return array_values(self::json($name));
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        $data = json_decode(self::text($name), true);

        if (! is_array($data)) {
            throw new RuntimeException("The fixture {$name} is not a JSON document.");
        }

        return $data;
    }

    public static function text(string $name): string
    {
        $text = @file_get_contents(self::DIR.'/'.$name);

        if ($text === false) {
            throw new RuntimeException("The fixture {$name} does not exist.");
        }

        return $text;
    }

    /** The first line of a `df` fixture, as dockerapi's JSON string answer carries it. */
    public static function df(string $name): string
    {
        return rtrim(self::text($name), "\n");
    }

    /** `postqueue -j` output with $count generated messages (make-large-queue.php). */
    public static function largeQueue(int $count = 12000): string
    {
        $process = new Process([(new PhpExecutableFinder)->find(false) ?: 'php', self::DIR.'/make-large-queue.php', (string) $count]);
        $process->mustRun();

        return $process->getOutput();
    }

    /** The container ID of a service of the recorded project. */
    public static function containerId(string $service, string $project = 'mailcowdockerized'): string
    {
        return hash('sha256', $project.'/'.$service);
    }
}
