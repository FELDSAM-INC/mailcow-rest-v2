<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restv2_account_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Match the upstream key types and collation without altering upstream tables.
            $table->string('admin_username', 255)->collation('utf8mb4_general_ci')->nullable()->unique();
            $table->string('mailbox_username', 255)->collation('utf8mb4_general_ci')->nullable()->unique();
            $table->foreign('admin_username')->references('username')->on('admin')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreign('mailbox_username')->references('username')->on('mailbox')->cascadeOnDelete()->cascadeOnUpdate();
        });
        Schema::table('restv2_api_keys', function (Blueprint $table): void {
            $table->uuid('owner_instance_id')->nullable();
        });

        $this->backFill();
    }

    public function backFill(): void
    {
        DB::transaction(function (): void {
            foreach (DB::table('admin')->select(['username', 'created'])->lockForUpdate()->get() as $owner) {
                $keys = DB::table('restv2_api_keys')->where('owner_username', $owner->username)
                    ->where('owner_created_at', $owner->created)->whereNull('owner_instance_id');
                if (! (clone $keys)->exists()) {
                    continue;
                }
                DB::table('restv2_account_instances')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'admin_username' => $owner->username,
                ]);
                $keys->update(['owner_instance_id' => DB::table('restv2_account_instances')
                    ->where('admin_username', $owner->username)->value('id')]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('restv2_api_keys', function (Blueprint $table): void {
            $table->dropColumn('owner_instance_id');
        });
        Schema::dropIfExists('restv2_account_instances');
    }
};
