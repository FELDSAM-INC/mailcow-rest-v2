<?php

namespace Tests\Unit\Quarantine;

use App\Services\Quarantine\QuarantineTemplateRenderer;
use App\Services\Quarantine\TemplateRendererUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 006 T051 (research R7, FR-070, SC-011): the notification template check runs the legacy
 * notifier's engine (Jinja2's SandboxedEnvironment and html2text, in a Python subprocess) with the
 * two validation datasets, and its outcome equals the notifier's for every template fixture
 * (tests/fixtures/quarantine/templates/goldens.json, generated in the tag's Dovecot image).
 */
class QuarantineTemplateRendererTest extends TestCase
{
    /** Exception classes of the goldens => the API's reasons. */
    private const REASONS = ['TemplateSyntaxError' => 'syntax', 'SecurityError' => 'security'];

    /**
     * @return array<string, mixed>
     */
    private static function goldens(): array
    {
        return (array) json_decode((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/quarantine/templates/goldens.json'), true);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function templates(): array
    {
        $names = [];

        foreach (array_keys((array) self::goldens()['templates']) as $name) {
            $names[$name] = [$name];
        }

        return $names;
    }

    private function renderer(): QuarantineTemplateRenderer
    {
        return $this->app->make(QuarantineTemplateRenderer::class);
    }

    public function test_the_validation_datasets_are_the_goldens_datasets(): void
    {
        $this->assertSame(self::goldens()['datasets'], $this->renderer()->datasets());
    }

    #[DataProvider('templates')]
    public function test_the_check_equals_the_notifiers_outcome(string $name): void
    {
        config(['restv2.quarantine.template_timeout' => 2]);
        $golden = self::goldens()['templates'][$name];
        $template = (string) file_get_contents(dirname(__DIR__, 2)."/fixtures/quarantine/templates/{$name}.tpl");

        $result = $this->renderer()->check($template);

        if (($golden['timeout'] ?? false) === true) {
            $this->assertFalse($result->ok);
            $this->assertSame('timeout', $result->reason);

            return;
        }

        if ($golden['parsed'] === false) {
            $this->assertFalse($result->ok);
            $this->assertSame(self::REASONS[$golden['exception']] ?? 'render', $result->reason);
            $this->assertSame($golden['line'], $result->line);

            return;
        }

        $failed = array_values(array_filter($golden['renders'], fn (array $r): bool => $r['rendered'] === false));

        if ($failed !== []) {
            $this->assertFalse($result->ok);
            $this->assertSame(self::REASONS[$failed[0]['exception']] ?? 'render', $result->reason);
            $this->assertSame($failed[0]['line'], $result->line);

            return;
        }

        $this->assertTrue($result->ok, (string) $result->message);
        $this->assertSame(array_column($golden['renders'], 'html'), array_column($result->renders, 'html'));
        $this->assertSame(array_column($golden['renders'], 'text'), array_column($result->renders, 'text'));
    }

    public function test_an_endless_template_times_out(): void
    {
        config(['restv2.quarantine.template_timeout' => 1]);
        $started = microtime(true);

        $result = $this->renderer()->check((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/quarantine/templates/endless.tpl'));

        $this->assertFalse($result->ok);
        $this->assertSame('timeout', $result->reason);
        $this->assertLessThan(3, microtime(true) - $started);
    }

    public function test_the_subprocess_is_isolated(): void
    {
        $command = $this->renderer()->command();

        $this->assertStringEndsWith('python3', $command[0]);
        $this->assertSame(['-I', '-B'], array_slice($command, 1, 2));
        $this->assertStringEndsWith('resources/bin/quarantine-template.py', $command[3]);

        $directory = sys_get_temp_dir().'/restv2-env-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $probe = $directory.'/probe';
        file_put_contents($probe, "#!/bin/sh\ncat > /dev/null\nprintf '{\"ok\": false, \"reason\": \"render\", \"line\": null, \"message\": \"%s|%s|%s|%s\"}' \"\${RESTV2_PROBE_SECRET:-none}\" \"\${HOME:-none}\" \"\${DBPASS:-none}\" \"\$*\"\n");
        chmod($probe, 0755);
        putenv('RESTV2_PROBE_SECRET=s3cret');
        config(['restv2.quarantine.python' => $probe]);

        try {
            $result = $this->renderer()->check('{{ username }}');
        } finally {
            putenv('RESTV2_PROBE_SECRET');
            @unlink($probe);
            @rmdir($directory);
        }

        $this->assertStringStartsWith('none|none|none|-I -B ', (string) $result->message);
    }

    public function test_output_above_the_limit_is_refused_as_a_render_error(): void
    {
        config(['restv2.quarantine.output_limit' => 1000]);

        $result = $this->renderer()->check((string) file_get_contents(dirname(__DIR__, 2).'/fixtures/quarantine/templates/default.tpl'));

        $this->assertFalse($result->ok);
        $this->assertSame('render', $result->reason);
    }

    public function test_a_missing_interpreter_is_an_error(): void
    {
        config(['restv2.quarantine.python' => '/nonexistent/python3']);

        $this->expectException(TemplateRendererUnavailable::class);
        $this->renderer()->check('{{ username }}');
    }

    public function test_the_preview_renders_dataset_b_with_the_chosen_links(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/quarantine/templates/default.tpl');

        $with = $this->renderer()->preview($template, true);
        $without = $this->renderer()->preview($template, false);

        $this->assertTrue($with->ok);
        $this->assertStringContainsString('https://mail.example.org/qhandler/release/'.str_repeat('0', 64), $with->renders[0]['html']);
        $this->assertStringNotContainsString('/qhandler/', $without->renders[0]['html']);
        $this->assertStringContainsString('Sample message 3', $with->renders[0]['text']);
    }
}
