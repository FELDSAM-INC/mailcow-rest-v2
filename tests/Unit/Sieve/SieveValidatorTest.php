<?php

namespace Tests\Unit\Sieve;

use App\Support\Sieve\SieveValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * T103 (feature 005 FR-049, research R13): Sieve scripts are checked by legacy's own parser, copied
 * unmodified from the upstream tag, so the API accepts and refuses exactly what legacy does, with
 * the parser's message.
 */
class SieveValidatorTest extends TestCase
{
    private const PARSER = __DIR__.'/../../../resources/legacy/sieve';

    /**
     * @return array<string, array{string}>
     */
    public static function validScripts(): array
    {
        return [
            'keep' => ['keep;'],
            'fileinto' => ["require \"fileinto\";\nif header :contains \"subject\" \"[SPAM]\" {\n  fileinto \"Junk\";\n  stop;\n}"],
            'vacation' => ["require [\"vacation\"];\nvacation :days 7 :subject \"Away\" \"I am away.\";"],
            'regex and variables' => ["require [\"regex\", \"variables\", \"fileinto\"];\nif header :regex \"subject\" \"^(.*)$\" { set \"s\" \"\${1}\"; fileinto \"INBOX\"; }"],
            'crlf' => ["require \"fileinto\";\r\nfileinto \"Archive\";\r\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidScripts(): array
    {
        return [
            'unknown command' => ['fileinto2 "x";'],
            'missing require' => ['fileinto "Junk";'],
            'unclosed block' => ["if true {\n  stop;\n"],
            'missing semicolon' => ['require "fileinto"; fileinto "a"'],
            'unknown test' => ['if foo { stop; }'],
            'third line' => ["require \"fileinto\";\nkeep;\nfileinto;"],
            'unterminated string' => ['keep "abc'],
        ];
    }

    #[DataProvider('validScripts')]
    public function test_valid_scripts_pass(string $script): void
    {
        $this->assertNull((new SieveValidator)->error($script));
    }

    #[DataProvider('invalidScripts')]
    public function test_invalid_scripts_carry_legacys_message(string $script): void
    {
        $message = (new SieveValidator)->error($script);

        $this->assertIsString($message);
        $this->assertSame($this->legacyMessage($script), $message);
    }

    public function test_the_line_of_a_message(): void
    {
        $this->assertSame(['message' => 'line 3: unknown command fileinto2', 'line' => 3], SieveValidator::violationParams('line 3: unknown command fileinto2'));
        $this->assertSame(['message' => 'something else'], SieveValidator::violationParams('something else'));
    }

    public function test_the_parser_is_the_unmodified_copy_of_the_tag(): void
    {
        $origin = (string) file_get_contents(self::PARSER.'/ORIGIN');
        $this->assertStringContainsString('at tag 2026-09a', $origin);
        preg_match_all('/^([0-9a-f]{64})  (\S+)$/m', $origin, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        $listed = [];

        foreach ($matches as [, $hash, $file]) {
            $listed[] = $file;
            $this->assertSame($hash, hash_file('sha256', self::PARSER.'/'.$file), "{$file} differs from the tag");
        }

        $present = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PARSER, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $relative = substr((string) $file, strlen(self::PARSER) + 1);

            if ($relative !== 'ORIGIN') {
                $present[] = $relative;
            }
        }

        sort($listed);
        sort($present);
        $this->assertSame($listed, $present);
    }

    /** The message of the parser loaded as legacy loads it (prerequisites.inc.php), in a subprocess. */
    private function legacyMessage(string $script): ?string
    {
        $code = <<<'PHP'
            error_reporting(0);
            require $argv[1].'/SieveParser.php';
            try {
                (new \Sieve\SieveParser())->parse(stream_get_contents(STDIN));
                echo json_encode(null);
            } catch (Exception $e) {
                echo json_encode($e->getMessage());
            }
            PHP;

        $process = new Process([PHP_BINARY, '-r', $code, '--', realpath(self::PARSER)]);
        $process->setInput($script);
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    }
}
