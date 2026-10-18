<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-owned audit log (FR-046, FR-048, R22, data-model). Never an upstream table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restv2_audit_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->dateTime('occurred_at', 6)->index();
            $table->string('event_type', 64);
            $table->string('outcome', 16);
            $table->string('actor_username', 255)->nullable()->index();
            $table->string('actor_role', 16)->nullable();
            $table->char('actor_api_key_id', 26)->nullable()->index();
            $table->string('target_type', 32)->nullable();
            $table->string('target_id', 255)->nullable();
            $table->string('target_name', 255)->nullable();
            $table->string('client_address', 45)->index();
            $table->string('user_agent', 512)->nullable();
            $table->string('auth_method', 32)->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->char('request_id', 32);
            $table->json('details')->default('{}');

            $table->index(['event_type', 'occurred_at']);
            // History filters (docs/conventions.md): targetType and targetId.
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restv2_audit_events');
    }
};
