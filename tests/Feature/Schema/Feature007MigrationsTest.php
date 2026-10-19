<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature 007 T011 (data-model "API-owned tables"): the impersonation columns of the audit log and
 * the owner binding and revocation reason of API keys, with the back-fill of existing keys.
 */
class Feature007MigrationsTest extends TestCase
{
    /**
     * @return array<string, object>
     */
    private function columns(string $table): array
    {
        return collect(DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]))
            ->keyBy('COLUMN_NAME')->all();
    }

    public function test_the_audit_log_has_the_impersonation_columns(): void
    {
        $columns = $this->columns('restv2_audit_events');

        $this->assertSame('varchar(255)', $columns['impersonated_username']->COLUMN_TYPE);
        $this->assertSame('YES', $columns['impersonated_username']->IS_NULLABLE);
        $this->assertSame('varchar(16)', $columns['impersonated_role']->COLUMN_TYPE);
        $this->assertSame('YES', $columns['impersonated_role']->IS_NULLABLE);

        $index = collect(DB::select("SHOW INDEX FROM restv2_audit_events WHERE Column_name IN ('impersonated_username', 'occurred_at')"))
            ->groupBy('Key_name')
            ->map(fn ($rows) => $rows->sortBy('Seq_in_index')->pluck('Column_name')->all())
            ->filter(fn (array $columns): bool => $columns === ['impersonated_username', 'occurred_at']);
        $this->assertCount(1, $index);
    }

    public function test_api_keys_have_the_owner_binding_and_the_revocation_reason(): void
    {
        $columns = $this->columns('restv2_api_keys');

        $this->assertSame('datetime', $columns['owner_created_at']->COLUMN_TYPE);
        $this->assertSame('YES', $columns['owner_created_at']->IS_NULLABLE);
        $this->assertSame('varchar(16)', $columns['revocation_reason']->COLUMN_TYPE);
        $this->assertSame('YES', $columns['revocation_reason']->IS_NULLABLE);
    }

    public function test_the_back_fill_binds_existing_keys_to_their_owner(): void
    {
        $migration = require base_path('database/migrations/2026_10_18_000702_add_owner_binding_to_restv2_api_keys_table.php');
        $this->mailcow()->admin('admin', 'x', created: '2026-03-04 05:06:07');
        $now = now('UTC')->format('Y-m-d H:i:s');
        $row = ['name' => 'k', 'prefix' => 'mcv2_x', 'access_level' => 'rw', 'allowed_networks' => '[]', 'created_by' => 'admin', 'created_at' => $now, 'updated_at' => $now];
        DB::table('restv2_api_keys')->insert(['id' => '01JAAAAAAAAAAAAAAAAAAAAAAA', 'secret_hash' => str_repeat('a', 64), 'owner_username' => 'admin'] + $row);
        DB::table('restv2_api_keys')->insert(['id' => '01JBBBBBBBBBBBBBBBBBBBBBBB', 'secret_hash' => str_repeat('b', 64), 'owner_username' => 'gone'] + $row);

        $migration->backFill();

        $this->assertSame('2026-03-04 05:06:07', DB::table('restv2_api_keys')->where('id', '01JAAAAAAAAAAAAAAAAAAAAAAA')->value('owner_created_at'));
        // A key whose owner no longer exists can never come back for a later account of that name.
        $this->assertSame('1000-01-01 00:00:00', DB::table('restv2_api_keys')->where('id', '01JBBBBBBBBBBBBBBBBBBBBBBB')->value('owner_created_at'));
    }
}
