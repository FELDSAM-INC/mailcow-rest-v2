<?php

namespace Tests\Unit\Accounts;

use App\Rules\AccountUsername;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feature 007 T006 (FR-014, research R4): usernames of administrators and domain administrators
 * are trimmed and lower-cased, then checked for length, characters and the reserved name.
 */
class AccountUsernameRuleTest extends TestCase
{
    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    private function violation(mixed $value): ?array
    {
        $rule = new AccountUsername;
        $rule->validate('username', $value, function (): void {});

        return $rule->violationFor('username');
    }

    public function test_the_value_is_trimmed_and_lower_cased(): void
    {
        $this->assertSame('r1', AccountUsername::normalize('  R1 '));
        $this->assertSame('ops.1-x_y', AccountUsername::normalize('Ops.1-X_y'));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    #[DataProvider('invalid')]
    public function test_invalid_usernames_are_refused_with_their_rule_code(mixed $value, string $code, array $params): void
    {
        $violation = $this->violation($value);

        $this->assertNotNull($violation);
        $this->assertSame($code, $violation['code']);
        $this->assertSame($params, $violation['params']);
    }

    /**
     * @return array<string, array{mixed, string, array<string, mixed>}>
     */
    public static function invalid(): array
    {
        return [
            'empty' => ['', 'required', []],
            'blanks' => ['   ', 'required', []],
            '256 characters' => [str_repeat('a', 256), 'tooLong', ['max' => 255]],
            'a space' => ['a b', 'invalidFormat', ['format' => 'accountUsername']],
            'a non-ASCII letter' => ['bö', 'invalidFormat', ['format' => 'accountUsername']],
            'dots only' => ['...', 'invalidFormat', ['format' => 'accountUsername']],
            'punctuation only' => ['-_.', 'invalidFormat', ['format' => 'accountUsername']],
            'an at sign' => ['bob@example.org', 'invalidFormat', ['format' => 'accountUsername']],
            'API' => ['API', 'reserved', ['reserved' => ['api']]],
            'api with blanks' => [' api ', 'reserved', ['reserved' => ['api']]],
            'not a string' => [12, 'invalidType', ['expected' => 'string']],
        ];
    }

    #[DataProvider('valid')]
    public function test_valid_usernames_pass(string $value): void
    {
        $this->assertNull($this->violation($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valid(): array
    {
        return [
            'letters, digits and punctuation' => ['ops.1-x_y'],
            'upper-case' => ['R1'],
            '255 characters' => [str_repeat('a', 255)],
            'apis' => ['apis'],
            'bulk-actions' => ['bulk-actions'],
        ];
    }
}
