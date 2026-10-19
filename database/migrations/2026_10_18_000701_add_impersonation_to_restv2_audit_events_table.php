<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 007 (FR-067, FR-069, research R22): the account that was impersonated when a record was
 * written, by any feature, and the index of the `impersonatedAccount` filter of the audit log.
 * Records outside impersonations, and every record written before this feature, keep NULL.
 * API-owned table only (constitution III).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restv2_audit_events', function (Blueprint $table): void {
            $table->string('impersonated_username', 255)->nullable();
            // `mailboxUser` or `domainAdmin` (contract AuditImpersonatedAccount.role).
            $table->string('impersonated_role', 16)->nullable();
            $table->index(['impersonated_username', 'occurred_at'], 'restv2_audit_events_impersonated_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::table('restv2_audit_events', function (Blueprint $table): void {
            $table->dropIndex('restv2_audit_events_impersonated_occurred_index');
            $table->dropColumn(['impersonated_username', 'impersonated_role']);
        });
    }
};
