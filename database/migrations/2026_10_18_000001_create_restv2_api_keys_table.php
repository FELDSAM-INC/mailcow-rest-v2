<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-owned keys for automation clients (FR-032–FR-040, R19, data-model). Never an upstream table;
 * legacy's `api` table is not touched (FR-041).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restv2_api_keys', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name', 255);
            // The first 12 characters of the secret, for recognising the key.
            $table->string('prefix', 16);
            // Hex SHA-256 of the secret; the secret itself is never stored.
            $table->char('secret_hash', 64)->unique();
            $table->string('owner_username', 255)->index();
            // `ro` or `rw`.
            $table->string('access_level', 8);
            $table->json('allowed_networks')->default('[]');
            $table->dateTime('expires_at')->nullable();
            $table->string('created_by', 255);
            $table->dateTime('last_used_at')->nullable();
            $table->string('last_used_from', 45)->nullable();
            $table->dateTime('revoked_at')->nullable()->index();
            $table->string('revoked_by', 255)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restv2_api_keys');
    }
};
