<?php

namespace App\Support\Regex;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Compiles ban rules in Python `re`, the regular-expression dialect of mailcow's ban service
 * (feature 011 research R20, FR-050): PCRE and Python differ (`(?<name>…)`, `\K`, possessive
 * quantifiers), and a rule without a group stops the ban service when it matches, so only Python
 * decides. Runs resources/bin/validate-python-regex.py with `python3 -I -S -B` (5 second timeout).
 * A missing interpreter, a timeout or an unexpected answer is a server fault.
 */
class PythonRegexValidator
{
    /**
     * @param  list<string>  $patterns
     * @return list<array{ok: bool, groups: int, error: string|null}>
     *
     * @throws RuntimeException
     */
    public function validate(array $patterns): array
    {
        $process = new Process([(string) config('restv2.monitoring.python', 'python3'), '-I', '-S', '-B', resource_path('bin/validate-python-regex.py')]);
        $process->setInput((string) json_encode($patterns, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $process->setTimeout(5.0);

        try {
            $process->run();
        } catch (Throwable $e) {
            throw new RuntimeException('The Python rule check could not run: '.$e->getMessage(), 0, $e);
        }

        $results = json_decode($process->getOutput(), true);

        if (! $process->isSuccessful() || ! is_array($results) || count($results) !== count($patterns)) {
            throw new RuntimeException('The Python rule check failed: '.trim(substr($process->getErrorOutput(), 0, 300)));
        }

        return array_values(array_map(fn (mixed $r): array => [
            'ok' => is_array($r) && ($r['ok'] ?? false) === true,
            'groups' => is_array($r) ? (int) ($r['groups'] ?? 0) : 0,
            'error' => is_array($r) && is_string($r['error'] ?? null) ? $r['error'] : null,
        ], $results));
    }
}
