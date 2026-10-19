<?php

namespace App\Services\Quarantine;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Checks and previews quarantine notification templates with the legacy notifier's own engine
 * (feature 006 research R7, FR-070, FR-071, SC-011): resources/bin/quarantine-template.py runs
 * Jinja2's SandboxedEnvironment and html2text exactly as quarantine_notify.py does, as
 * `python3 -I -B` (isolated, no bytecode on the read-only root filesystem) with an empty
 * environment, a time limit (`restv2.quarantine.template_timeout`, 5 s → `timeout`) and an output
 * cap (8 MiB → `render`).
 *
 * The check renders validation dataset A (one rejected item, `quarantine_acl` 0) and B (three
 * items, `quarantine_acl` 1); the preview renders B with the chosen `quarantine_acl`. The sample
 * values are fixed (user `user@example.org`, the server's host name, an all-zero `qhash`).
 */
class QuarantineTemplateRenderer
{
    public const OUTPUT_LIMIT = 8388608;

    public const SAMPLE_USERNAME = 'user@example.org';

    public const SAMPLE_CREATED = '2026-01-01 08:00:00';

    public function check(string $template): TemplateCheckResult
    {
        return $this->run($template, $this->datasets());
    }

    public function preview(string $template, bool $quarantineAcl): TemplateCheckResult
    {
        $dataset = $this->datasets()[1];
        $dataset['quarantine_acl'] = $quarantineAcl ? 1 : 0;

        return $this->run($template, [$dataset]);
    }

    /**
     * The validation datasets A and B (research R7).
     *
     * @return list<array{meta: list<array<string, mixed>>, username: string, counter: int, hostname: string, quarantine_acl: int}>
     */
    public function datasets(): array
    {
        $hostname = (string) config('restv2.mailcow_hostname');
        $item = fn (int $n, string $action, float $score): array => [
            'qhash' => str_repeat('0', 64),
            'id' => $n,
            'subject' => 'Sample message '.$n,
            'score' => $score,
            'sender' => 'sender'.$n.'@example.net',
            'created' => self::SAMPLE_CREATED,
            'action' => $action,
        ];

        return [
            ['meta' => [$item(1, 'reject', 7.5)], 'username' => self::SAMPLE_USERNAME, 'counter' => 1, 'hostname' => $hostname, 'quarantine_acl' => 0],
            ['meta' => [$item(1, 'reject', 7.5), $item(2, 'add header', 16.2), $item(3, 'rewrite subject', 9.1)], 'username' => self::SAMPLE_USERNAME, 'counter' => 3, 'hostname' => $hostname, 'quarantine_acl' => 1],
        ];
    }

    /**
     * The command line of the renderer.
     *
     * @return list<string>
     */
    public function command(): array
    {
        return [$this->interpreter(), '-I', '-B', resource_path('bin/quarantine-template.py')];
    }

    /**
     * @param  list<array<string, mixed>>  $datasets
     */
    private function run(string $template, array $datasets): TemplateCheckResult
    {
        // An empty environment: every inherited variable is removed (Symfony passes false as unset).
        $environment = array_fill_keys(array_filter(array_keys(array_merge(getenv(), $_ENV)), 'is_string'), false);
        $process = new Process($this->command(), base_path(), $environment);
        $process->setInput((string) json_encode(['template' => $template, 'datasets' => $datasets], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $process->setTimeout(max(1, (int) config('restv2.quarantine.template_timeout', 5)));
        $limit = max(1, (int) config('restv2.quarantine.output_limit', self::OUTPUT_LIMIT));
        $output = '';
        $tooLarge = false;

        try {
            $process->start();
            $process->wait(function (string $type, string $buffer) use (&$output, &$tooLarge, $limit, $process): void {
                if ($type === Process::OUT) {
                    $output .= $buffer;

                    if (strlen($output) > $limit && ! $tooLarge) {
                        $tooLarge = true;
                        $process->stop(0);
                    }
                }
            });
        } catch (ProcessTimedOutException) {
            return TemplateCheckResult::failed('timeout', null, 'The template did not finish rendering within the time limit.');
        } catch (Throwable $e) {
            if ($tooLarge) {
                return TemplateCheckResult::failed('render', null, 'The rendered template is larger than the output limit.');
            }

            throw new TemplateRendererUnavailable('The template renderer could not run: '.$e->getMessage(), 0, $e);
        }

        if ($tooLarge) {
            return TemplateCheckResult::failed('render', null, 'The rendered template is larger than the output limit.');
        }

        $result = json_decode($output, true);

        if (! is_array($result) || ! is_bool($result['ok'] ?? null)) {
            throw new TemplateRendererUnavailable('The template renderer gave no answer: '.mb_substr($process->getErrorOutput(), 0, 500));
        }

        if ($result['ok'] === false) {
            return TemplateCheckResult::failed(
                in_array($result['reason'] ?? null, ['syntax', 'security', 'render'], true) ? (string) $result['reason'] : 'render',
                is_int($result['line'] ?? null) ? $result['line'] : null,
                is_string($result['message'] ?? null) ? $result['message'] : null,
            );
        }

        $renders = [];

        foreach ((array) ($result['renders'] ?? []) as $render) {
            $renders[] = ['html' => (string) ($render['html'] ?? ''), 'text' => (string) ($render['text'] ?? '')];
        }

        return new TemplateCheckResult(true, $renders);
    }

    /**
     * The configured interpreter (`restv2.quarantine.python`, `RESTV2_PYTHON`) as an absolute path,
     * so that it is found without an environment.
     */
    private function interpreter(): string
    {
        $configured = (string) config('restv2.quarantine.python', 'python3');

        if (str_contains($configured, '/')) {
            return is_executable($configured) ? $configured : throw new TemplateRendererUnavailable("The template renderer's interpreter {$configured} was not found.");
        }

        return (new ExecutableFinder)->find($configured) ?? throw new TemplateRendererUnavailable("The template renderer's interpreter {$configured} was not found.");
    }
}
