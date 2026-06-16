<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pmod_requests')) {
            return;
        }

        Schema::create('pmod_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('tenant_id', 32)->nullable()->index();
            $table->string('customer_id', 64)->nullable()->index();
            $table->string('action')->nullable()->index();
            $table->string('action_type')->nullable()->index();
            $table->string('status', 32)->default('received')->index();
            $table->boolean('dry_run')->default(false)->index();
            $table->string('requested_by')->nullable();
            $table->json('payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('alerted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pmod_requests');
    }
};
