<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-owned mass mail jobs and their recipients (feature 013 data-model.md section 4, research
 * R17). Never an upstream table. `running_slot` is 1 while a job runs and NULL otherwise, under a
 * unique index, so that the database itself allows one running job only (FR-075). Recipients are
 * inserted when the job is created, which fixes the selection; their state machine gives
 * at-most-once delivery (FR-079).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restv2_mass_mail_jobs', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('status', 16);
            $table->unsignedTinyInteger('running_slot')->nullable()->unique();
            $table->string('sender', 255);
            $table->string('subject', 255);
            $table->mediumText('text_body');
            $table->mediumText('html_body')->nullable();
            $table->json('selection');
            $table->unsignedInteger('recipient_count');
            $table->string('created_by', 255);
            $table->char('created_request_id', 32);
            $table->string('created_client_address', 45);
            $table->dateTime('created_at', 6);
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->string('cancelled_by', 255)->nullable();

            $table->index(['status', 'created_at']);
        });

        Schema::create('restv2_mass_mail_recipients', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('job_id', 26);
            $table->string('address', 255);
            $table->string('outcome', 16)->default('pending');
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_reason', 1024)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at', 6)->nullable();
            $table->dateTime('last_attempt_at', 6)->nullable();

            $table->unique(['job_id', 'address']);
            // The runner claims the next pending recipient by address: this index serves that
            // order, so a claim stops at the first due row instead of sorting the whole job.
            $table->index(['job_id', 'outcome', 'address']);
            $table->index(['job_id', 'outcome', 'next_attempt_at']);
            $table->foreign('job_id')->references('id')->on('restv2_mass_mail_jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restv2_mass_mail_recipients');
        Schema::dropIfExists('restv2_mass_mail_jobs');
    }
};
