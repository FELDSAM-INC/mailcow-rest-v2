<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The config files read environment settings through App\Support\Config\Env::get, which treats an
 * empty value as unset (docs/conventions.md "Configuration"). Plain env() stays only for a setting
 * whose empty value means something, listed here with its meaning.
 */
class ConfigEnvironmentReadsTest extends TestCase
{
    /** Setting => what its empty value means. */
    private const EMPTY_IS_MEANINGFUL = [
        'RESTV2_MAILCOW_INC_DIR' => 'mailcow\'s data/web/inc is not mounted: the upstream defaults apply',
    ];

    public function test_config_files_read_the_environment_through_the_helper(): void
    {
        $violations = [];

        foreach (glob(dirname(__DIR__, 3).'/config/*.php') ?: [] as $file) {
            foreach ($this->plainReads((string) file_get_contents($file)) as $name) {
                if (! array_key_exists($name, self::EMPTY_IS_MEANINGFUL)) {
                    $violations[] = basename($file).": {$name}";
                }
            }
        }

        $this->assertSame([], $violations, 'Read settings with App\Support\Config\Env::get, or list the setting here with the meaning of its empty value.');
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('samples')]
    public function test_the_scanner_finds_plain_reads(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->plainReads($source));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function samples(): array
    {
        return [
            'env()' => ["'a' => env('RESTV2_A', 1),", ['RESTV2_A']],
            'cast env()' => ["'a' => (int) env(\"RESTV2_A\"),", ['RESTV2_A']],
            'getenv()' => ["'a' => getenv('TZ'),", ['TZ']],
            '$_ENV' => ["'a' => \$_ENV['TZ'] ?? null,", ['TZ']],
            '$_SERVER' => ["'a' => \$_SERVER['TZ'] ?? null,", ['TZ']],
            'the helper' => ["'a' => Env::get('RESTV2_A', 1),", []],
        ];
    }

    /**
     * @return list<string>
     */
    private function plainReads(string $source): array
    {
        preg_match_all('/(?<![\w:>$\\\\])(?:env|getenv)\s*\(\s*[\'"]([^\'"]+)[\'"]|\$_(?:ENV|SERVER)\s*\[\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);

        // One of the two groups matched; the other is empty.
        return array_map(fn (string $call, string $superglobal): string => $call.$superglobal, $matches[1], $matches[2]);
    }
}
