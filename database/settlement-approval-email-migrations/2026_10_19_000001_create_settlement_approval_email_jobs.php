<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_approval_email_jobs', function (Blueprint $table): void {
            $table->string('job_key', 64)->primary();
            $table->string('fingerprint', 64);
            $table->string('message_key', 64);
            $table->string('company', 8);
            $table->string('contact_id', 64);
            $table->string('settlement_id', 64);
            $table->string('stage', 32)->default('ready')->index();
            $table->string('result', 16)->nullable();
            $table->string('target_status', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('lease_token', 48)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
            $table->unique(['company', 'settlement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_approval_email_jobs');
    }
};
