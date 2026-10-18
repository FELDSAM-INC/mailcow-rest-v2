<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T022 (constitution III, R5): no migration may create, alter, drop or rename an upstream table.
 * Every table a migration names must carry the API prefix `restv2_`.
 */
class MigrationPrefixTest extends TestCase
{
    private const PREFIX = 'restv2_';

    public function test_every_migration_names_only_prefixed_tables(): void
    {
        $files = glob(dirname(__DIR__, 3).'/database/migrations/*.php') ?: [];
        $violations = [];

        foreach ($files as $file) {
            foreach ($this->unprefixedTables((string) file_get_contents($file)) as $table) {
                $violations[] = basename($file).": {$table}";
            }
        }

        $this->assertSame([], $violations, 'Migrations may only touch restv2_ tables.');
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('samples')]
    public function test_the_scanner_finds_unprefixed_tables(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->unprefixedTables($source));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function samples(): array
    {
        return [
            'create' => ["Schema::create('mailbox', function () {});", ['mailbox']],
            'table' => ['Schema::table("admin", fn () => null);', ['admin']],
            'drop' => ["Schema::drop('tfa');", ['tfa']],
            'dropIfExists' => ["Schema::dropIfExists('fido2');", ['fido2']],
            'rename' => ["Schema::rename('restv2_a', 'domain');", ['domain']],
            'connection' => ["Schema::connection('mysql')->create('alias', fn () => null);", ['alias']],
            'prefixed' => ["Schema::create('restv2_api_keys', fn () => null); Schema::dropIfExists('restv2_api_keys');", []],
        ];
    }

    /**
     * @return list<string>
     */
    private function unprefixedTables(string $source): array
    {
        $tables = [];

        preg_match_all('/(?:Schema::|->)(?:create|table|drop|dropIfExists)\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
        $tables = [...$tables, ...$matches[1]];

        preg_match_all('/(?:Schema::|->)rename\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $source, $matches);
        $tables = [...$tables, ...$matches[1], ...$matches[2]];

        return array_values(array_unique(array_filter($tables, fn (string $t): bool => ! str_starts_with($t, self::PREFIX))));
    }
}
