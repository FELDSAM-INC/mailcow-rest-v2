<?php

namespace Tests\Unit\Support;

use App\Support\Rspamd\UclStructureChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Feature 013 T045 (research R11, FR-051, SC-006): an Rspamd settings rule must be structurally
 * closed, so that one rule cannot break the settings map that Rspamd loads for all rules. Only
 * structure outside comments, quoted strings and heredocs is checked; Rspamd decides the rest.
 */
class UclStructureCheckerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function presets(): array
    {
        $cases = [];

        foreach (glob(__DIR__.'/../../fixtures/mailcow-inc/presets/rspamd/*.yml') ?: [] as $path) {
            $preset = Yaml::parseFile($path);
            $cases[basename($path)] = [(string) $preset['content']];
        }

        return $cases;
    }

    #[DataProvider('presets')]
    public function test_every_upstream_preset_passes(string $content): void
    {
        $this->assertNull((new UclStructureChecker)->check($content));
    }

    /**
     * @return array<string, array{string, array{construct: string, line: int}|null}>
     */
    public static function cases(): array
    {
        return [
            'unclosed brace' => ["priority = 10;\napply \"default\" {\n  MAILCOW_WHITE = -9999.0;\n", ['construct' => 'unclosedBrace', 'line' => 2]],
            'closer without opener' => ["priority = 10;\n}\n", ['construct' => 'unexpectedBrace', 'line' => 2]],
            'wrong nesting' => ["symbols [\n  \"A\"\n}\n", ['construct' => 'unexpectedBrace', 'line' => 3]],
            'bracket without opener' => ["x = 1;\n]\n", ['construct' => 'unexpectedBracket', 'line' => 2]],
            'unclosed bracket' => ["symbols [\n  \"A\"\n", ['construct' => 'unclosedBracket', 'line' => 1]],
            'unclosed string' => ["rcpt = \"/.*@example.org$/;\n", ['construct' => 'unclosedString', 'line' => 1]],
            'unclosed single quote' => ["rcpt = '/x/;\n", ['construct' => 'unclosedString', 'line' => 1]],
            'braces inside strings' => ["from = \"/^((?!.*@domain\\.tld).)*\$/\";\nx = \"{[\";\n", null],
            'escaped quotes' => ["x = \"a \\\" { b\";\n", null],
            'hash comment' => ["# apply {\nx = 1;\n", null],
            'trailing comment' => ["x = 1; # }\n", null],
            'block comment' => ["/* apply {\n [ */\nx = 1;\n", null],
            'heredoc' => ["text = <<EOD\n{ [ \" unbalanced\nEOD\ny = 1;\n", null],
            'balanced nesting' => ["apply \"default\" { actions { reject = 20; } symbols [ \"A\", \"B\" ] }\n", null],
            'single quoted' => ["x = 'a \" {';\n", null],
        ];
    }

    /**
     * @param  array{construct: string, line: int}|null  $expected
     */
    #[DataProvider('cases')]
    public function test_cases(string $content, ?array $expected): void
    {
        $this->assertSame($expected, (new UclStructureChecker)->check($content));
    }
}
