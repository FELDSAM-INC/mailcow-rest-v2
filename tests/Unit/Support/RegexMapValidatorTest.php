<?php

namespace Tests\Unit\Support;

use App\Support\Rspamd\RegexMapValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 013 T046 (research R13, FR-057, SC-006): every line of a global regex map is empty, a
 * comment, or `/pattern/` with at most one flag `i`, `m` or `s`, whose pattern contains no further
 * `/` and compiles; the first failing line is reported, 1-based, with its reason.
 */
class RegexMapValidatorTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function shippedMaps(): array
    {
        $cases = [];

        foreach (glob(__DIR__.'/../../fixtures/mailcow/rspamd-custom/*.map') ?: [] as $path) {
            $cases[basename($path)] = [(string) file_get_contents($path)];
        }

        return $cases;
    }

    #[DataProvider('shippedMaps')]
    public function test_every_shipped_map_passes(string $content): void
    {
        $this->assertNull((new RegexMapValidator)->check($content));
    }

    /**
     * @return array<string, array{string, array{line: int, reason: string}|null}>
     */
    public static function cases(): array
    {
        return [
            'the example of AS-3.4' => ["# comment\n/.*@spam\\.example$/i\n\n", null],
            'crlf' => ["# comment\r\n/a/\r\n/b/m\r\n", null],
            'unclosed class' => ['/[unclosed/i', ['line' => 1, 'reason' => 'doesNotCompile']],
            'no slashes' => ["# ok\nspam.example", ['line' => 2, 'reason' => 'notSlashDelimited']],
            'two flags' => ["/x/i\n/x/im", ['line' => 2, 'reason' => 'tooManyFlags']],
            'inner slash' => ['/a/b/', ['line' => 1, 'reason' => 'innerSlash']],
            'escaped inner slash' => ['/http:\\/\\/x/', ['line' => 1, 'reason' => 'innerSlash']],
            'whitespace only' => ["/a/\n  \n", ['line' => 2, 'reason' => 'notSlashDelimited']],
            'unknown flag' => ['/a/x', ['line' => 1, 'reason' => 'notSlashDelimited']],
            'no closing slash' => ['/abc', ['line' => 1, 'reason' => 'notSlashDelimited']],
            'only a slash' => ['/', ['line' => 1, 'reason' => 'notSlashDelimited']],
            'first failure wins' => ["/ok/\nbad\n/[x/", ['line' => 2, 'reason' => 'notSlashDelimited']],
            'empty map' => ['', null],
        ];
    }

    /**
     * @param  array{line: int, reason: string}|null  $expected
     */
    #[DataProvider('cases')]
    public function test_cases(string $content, ?array $expected): void
    {
        $this->assertSame($expected, (new RegexMapValidator)->check($content));
    }
}
