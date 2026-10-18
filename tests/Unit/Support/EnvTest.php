<?php

namespace Tests\Unit\Support;

use App\Support\Config\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Config\TemporaryEnvironment;

/**
 * Settings read through Env::get treat an empty or whitespace-only value as unset
 * (docs/conventions.md "Configuration"): mailcow's compose override passes optional settings as
 * `NAME=${NAME:-}`, which sets them to the empty string, and Laravel's env() would return that
 * instead of the default.
 */
class EnvTest extends TestCase
{
    use TemporaryEnvironment;

    private const NAME = 'RESTV2_ENV_HELPER_TEST';

    /**
     * @return array<string, array{string|null}>
     */
    public static function unsetValues(): array
    {
        return [
            'not set' => [null],
            'empty' => [''],
            'spaces' => ['   '],
            'tab and newline' => ["\t\n"],
        ];
    }

    #[DataProvider('unsetValues')]
    public function test_an_unset_empty_or_blank_value_gives_the_default(?string $value): void
    {
        $this->setEnvironment([self::NAME => $value]);

        $this->assertSame(100, Env::get(self::NAME, 100));
        $this->assertSame('{}', Env::get(self::NAME, '{}'));
        $this->assertNull(Env::get(self::NAME));
        $this->assertSame('lazy', Env::get(self::NAME, fn (): string => 'lazy'));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function realValues(): array
    {
        return [
            'number' => ['50', '50'],
            'zero is a value' => ['0', '0'],
            'text' => ['MAILCOWUI', 'MAILCOWUI'],
            'surrounding spaces are kept' => [' a b ', ' a b '],
            // Laravel's conversions still apply.
            'true' => ['true', true],
            'false' => ['false', false],
            'quoted' => ['"quoted"', 'quoted'],
        ];
    }

    #[DataProvider('realValues')]
    public function test_a_real_value_wins_over_the_default(string $value, mixed $expected): void
    {
        $this->setEnvironment([self::NAME => $value]);

        $this->assertSame($expected, Env::get(self::NAME, 'default'));
    }
}
