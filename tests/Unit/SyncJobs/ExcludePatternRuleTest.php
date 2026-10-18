<?php

namespace Tests\Unit\SyncJobs;

use App\Rules\SyncJobExcludePattern;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 008 T032 (research R6, US2 AS-6): the exclusion pattern must compile as a PCRE pattern
 * between `/` delimiters, as legacy checks it, so an unescaped `/` is refused. A refusal is
 * `invalidRegex`.
 */
class ExcludePatternRuleTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function patterns(): array
    {
        return [
            'default' => ['(?i)spam|(?i)junk', true],
            'empty' => ['', true],
            'escaped slash' => ['^INBOX\/Junk$', true],
            'alternatives' => ['Trash|Spam', true],
            'unclosed group' => ['(', false],
            'unclosed class' => ['[a-', false],
            'unescaped slash' => ['INBOX/Junk', false],
            'bad quantifier' => ['*Junk', false],
        ];
    }

    #[DataProvider('patterns')]
    public function test_the_pattern_is_checked_like_legacy(string $pattern, bool $valid): void
    {
        $rule = new SyncJobExcludePattern;

        $this->assertSame($valid, Validator::make(['exclude' => $pattern], ['exclude' => [$rule]])->passes());
        set_error_handler(static fn (): bool => true);
        $legacy = preg_match('/'.$pattern.'/', '') !== false;
        restore_error_handler();
        $this->assertSame($valid, $legacy);

        if (! $valid) {
            $this->assertSame('invalidRegex', $rule->violationFor('exclude')['code'] ?? null);
        }
    }
}
