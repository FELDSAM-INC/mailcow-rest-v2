<?php

namespace App\Support\Sieve;

use App\Services\Settings\SettingsLimits;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/** Runs the unmodified upstream parser in a child with bounded time, memory, input and output. */
final class SieveValidator
{
    public const TIMEOUT_SECONDS = 1.0;

    public const LIMIT_ERROR = 'Sieve validation exceeded its resource limits.';

    private const OUTPUT_LIMIT = 16384;

    /** The legacy parser's message, a resource-limit refusal, or null for a valid script. */
    public function error(string $script): ?string
    {
        if (strlen($script) > SettingsLimits::SIEVE_SCRIPT_MAX_BYTES) {
            return self::LIMIT_ERROR;
        }

        $php = (new PhpExecutableFinder)->find(false);
        if ($php === false) {
            throw new RuntimeException('The PHP interpreter for Sieve validation was not found.');
        }
        $root = dirname(__DIR__, 3);
        $environment = array_fill_keys(array_filter(array_keys(array_merge(getenv(), $_ENV, $_SERVER)), 'is_string'), false);
        $process = new Process([
            $php, '-d', 'memory_limit=64M', '-d', 'max_execution_time=1',
            '-d', 'display_errors=0', '-d', 'log_errors=0',
            $root.'/resources/bin/validate-sieve.php',
        ], $root, $environment);
        $process->setInput($script);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $output = '';
        $bytes = 0;
        $tooLarge = false;

        try {
            $process->run(function (string $type, string $buffer) use (&$output, &$bytes, &$tooLarge, $process): void {
                $bytes += strlen($buffer);
                if ($bytes > self::OUTPUT_LIMIT) {
                    $tooLarge = true;
                    $process->stop(0);

                    return;
                }
                if ($type === Process::OUT) {
                    $output .= $buffer;
                }
                $process->clearOutput();
                $process->clearErrorOutput();
            });
        } catch (ProcessTimedOutException) {
            return self::LIMIT_ERROR;
        }

        if ($tooLarge || ! $process->isSuccessful()) {
            return self::LIMIT_ERROR;
        }
        $result = json_decode($output, true);
        if (! is_array($result) || ! array_key_exists('error', $result)
            || ($result['error'] !== null && ! is_string($result['error']))) {
            throw new RuntimeException('The Sieve validator returned an invalid response.');
        }

        return $result['error'];
    }

    /**
     * The params of a `sieveSyntax` violation: the message and, when the message names it, the
     * line (`line 3: …`).
     *
     * @return array{message: string, line?: int}
     */
    public static function violationParams(string $message): array
    {
        return preg_match('/^line (\d+):/', $message, $matches) === 1
            ? ['message' => $message, 'line' => (int) $matches[1]]
            : ['message' => $message];
    }
}
