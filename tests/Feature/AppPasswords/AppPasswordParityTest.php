<?php

namespace Tests\Feature\AppPasswords;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T065 (feature 005 SC-009): an app password created through the API leaves the row legacy
 * app_passwd('add') writes for the same input, apart from the ID, the hash and the timestamps
 * (tests/fixtures/legacy/app_passwd_add_expected.php).
 */
class AppPasswordParityTest extends TestCase
{
    use SelfServiceTestHelpers;

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function cases(): array
    {
        /** @var array<string, array{api: array<string, mixed>, row: array<string, mixed>}> $fixture */
        $fixture = require __DIR__.'/../../fixtures/legacy/app_passwd_add_expected.php';

        return array_map(fn (array $case): array => [$case['api'], $case['row']], $fixture);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('cases')]
    public function test_the_row_equals_legacys(array $body, array $expected): void
    {
        $this->asSelfServiceUser();

        $id = (int) $this->postJson('/api/v2/mailboxes/anna@example.org/app-passwords', $body, $this->csrf())->assertCreated()->json('data.id');

        $row = (array) DB::table('app_passwd')->where('id', $id)->first();
        unset($row['id'], $row['password'], $row['created'], $row['modified']);
        $this->assertEquals($expected, array_map(fn (mixed $v): mixed => is_numeric($v) ? (int) $v : $v, $row));
    }
}
