<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-owned per-account preferences (FR-030, R21, data-model). Never an upstream table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restv2_preferences', function (Blueprint $table): void {
            $table->id();
            // `admin` for administrators and domain administrators (table `admin`), `mailbox` for
            // mailbox users.
            $table->string('account_kind', 16);
            $table->string('username', 255);
            $table->string('language', 16)->nullable();
            $table->string('theme', 8)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['account_kind', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restv2_preferences');
    }
};
