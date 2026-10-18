<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 002 (FR-058, research D16): the history of one object, newest first, is read with the
 * target filters; the index covers the order too and replaces 001's two-column target index.
 * API-owned table only (constitution III).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restv2_audit_events', function (Blueprint $table): void {
            $table->index(['target_type', 'target_id', 'occurred_at'], 'restv2_audit_events_target_occurred_index');
            $table->dropIndex(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::table('restv2_audit_events', function (Blueprint $table): void {
            $table->index(['target_type', 'target_id']);
            $table->dropIndex('restv2_audit_events_target_occurred_index');
        });
    }
};
