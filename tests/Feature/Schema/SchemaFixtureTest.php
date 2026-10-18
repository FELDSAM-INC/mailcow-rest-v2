<?php

namespace Tests\Feature\Schema;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * T023 (constitution I, III, R2): the tests run on mailcow's real upstream schema, loaded from the
 * fixture, plus the API's own restv2_ tables.
 */
class SchemaFixtureTest extends TestCase
{
    /** API-owned tables created so far (later phases add restv2_api_keys and restv2_preferences). */
    private const API_TABLES = ['restv2_migrations', 'restv2_audit_events'];

    public function test_the_schema_version_is_supported(): void
    {
        $version = DB::table('versions')->where('application', 'db_schema')->value('version');

        $this->assertContains($version, config('restv2.supported_schema_versions'));
    }

    public function test_the_upstream_schema_has_54_tables_and_6_views(): void
    {
        $objects = collect(DB::select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'));
        $upstream = $objects->reject(fn (object $o): bool => str_starts_with($o->name, 'restv2_'));

        $this->assertCount(54, $upstream->where('type', 'BASE TABLE'));
        $this->assertCount(6, $upstream->where('type', 'VIEW'));
    }

    public function test_the_api_tables_exist(): void
    {
        foreach (self::API_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} is missing");
        }
    }
}
