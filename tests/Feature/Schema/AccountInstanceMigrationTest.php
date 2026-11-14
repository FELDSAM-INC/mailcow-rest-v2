<?php

namespace Tests\Feature\Schema;

use App\Auth\AccountKind;
use App\Services\Accounts\AccountInstance;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

class AccountInstanceMigrationTest extends TestCase
{
    use ApiKeys;

    public function test_backfill_binds_valid_existing_keys_without_reviving_replaced_or_missing_owners(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $valid = $this->apiKey('admin');
        $replaced = $this->apiKey('admin');
        $missing = $this->apiKey('gone');
        DB::table('restv2_api_keys')->update(['owner_instance_id' => null]);
        DB::table('restv2_api_keys')->where('id', $replaced['id'])->update(['owner_created_at' => '2000-01-01 00:00:00']);
        $migration = require database_path('migrations/2026_11_14_000001_create_restv2_account_instances.php');

        $migration->backFill();

        $id = DB::table('restv2_api_keys')->where('id', $valid['id'])->value('owner_instance_id');
        $this->assertIsString($id);
        $this->assertTrue(app(AccountInstance::class)->matches(AccountKind::Admin, 'admin', $id));
        $this->assertNull(DB::table('restv2_api_keys')->where('id', $replaced['id'])->value('owner_instance_id'));
        $this->assertNull(DB::table('restv2_api_keys')->where('id', $missing['id'])->value('owner_instance_id'));

        $migration->backFill();
        $this->assertSame($id, DB::table('restv2_api_keys')->where('id', $valid['id'])->value('owner_instance_id'));
    }

    public function test_legacy_rename_preserves_identity_and_delete_removes_it(): void
    {
        $this->mailcow()->admin('old', 'x');
        $instances = app(AccountInstance::class);
        $id = $instances->bind(AccountKind::Admin, 'old');
        $this->assertIsString($id);
        DB::table('admin')->where('username', 'old')->update(['username' => 'new']);
        $this->assertTrue($instances->matches(AccountKind::Admin, 'new', $id));
        $this->assertFalse($instances->matches(AccountKind::Admin, 'old', $id));

        DB::table('admin')->where('username', 'new')->delete();
        $this->assertFalse($instances->matches(AccountKind::Admin, 'new', $id));
        $this->mailcow()->admin('new', 'x');
        $this->assertNotSame($id, $instances->bind(AccountKind::Admin, 'new'));
    }
}
