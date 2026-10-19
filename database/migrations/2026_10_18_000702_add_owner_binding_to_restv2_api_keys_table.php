<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 007 (FR-034, FR-036, research R10, R11):
 *
 *  - `owner_created_at`: the owner's `admin.created` when the key was created (a raw mailcow
 *    DATETIME, mailcow local time). ApiKeyAuthenticator refuses a key whose owner's current
 *    `created` differs, so that a key never acts for a later account with the same username.
 *    Existing keys are bound to their owner now; a key whose owner no longer exists is bound to a
 *    time no account can have, so that it stays unusable for a later account of that name.
 *  - `revocation_reason`: `manual` (POST /api-keys/{id}/revocation) or `ownerDeleted`.
 *
 * API-owned table only (constitution III).
 */
return new class extends Migration
{
    /** The binding of a key whose owner was already deleted: no account is ever created then. */
    public const NO_OWNER = '1000-01-01 00:00:00';

    public function up(): void
    {
        Schema::table('restv2_api_keys', function (Blueprint $table): void {
            $table->dateTime('owner_created_at')->nullable();
            $table->string('revocation_reason', 16)->nullable();
        });

        $this->backFill();
    }

    /**
     * Binds every unbound key to its owner's current creation time.
     */
    public function backFill(): void
    {
        // mailcow's tables use utf8mb4_general_ci, this API's tables Laravel's utf8mb4_unicode_ci.
        DB::statement('UPDATE `restv2_api_keys` `k` JOIN `admin` `a` ON `a`.`username` = `k`.`owner_username` COLLATE utf8mb4_general_ci
            SET `k`.`owner_created_at` = `a`.`created` WHERE `k`.`owner_created_at` IS NULL');
        DB::table('restv2_api_keys')->whereNull('owner_created_at')->update(['owner_created_at' => self::NO_OWNER]);
        DB::table('restv2_api_keys')->whereNotNull('revoked_at')->whereNull('revocation_reason')->update(['revocation_reason' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('restv2_api_keys', function (Blueprint $table): void {
            $table->dropColumn(['owner_created_at', 'revocation_reason']);
        });
    }
};
