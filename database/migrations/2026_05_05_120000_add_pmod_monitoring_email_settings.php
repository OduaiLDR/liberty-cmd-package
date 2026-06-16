<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pmod_requests')) {
            Schema::table('pmod_requests', function (Blueprint $table): void {
                if (!Schema::hasColumn('pmod_requests', 'result_type')) {
                    $table->string('result_type', 64)->nullable()->index()->after('status');
                }
                if (!Schema::hasColumn('pmod_requests', 'failure_type')) {
                    $table->string('failure_type', 64)->nullable()->index()->after('result_type');
                }
                if (!Schema::hasColumn('pmod_requests', 'notification_status')) {
                    $table->string('notification_status', 32)->nullable()->index()->after('failure_type');
                }
                if (!Schema::hasColumn('pmod_requests', 'notification_sent_at')) {
                    $table->timestamp('notification_sent_at')->nullable()->index()->after('notification_status');
                }
                if (!Schema::hasColumn('pmod_requests', 'notification_error')) {
                    $table->text('notification_error')->nullable()->after('notification_sent_at');
                }
            });
        }

        if (!Schema::hasTable('pmod_email_settings')) {
            Schema::create('pmod_email_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('action_type', 128)->index();
                $table->string('status', 32)->index();
                $table->string('failure_type', 64)->nullable()->index();
                $table->text('to_emails')->nullable();
                $table->text('cc_emails')->nullable();
                $table->text('bcc_emails')->nullable();
                $table->boolean('high_priority')->default(false)->index();
                $table->boolean('enabled')->default(true)->index();
                $table->timestamps();
                $table->unique(['action_type', 'status', 'failure_type'], 'pmod_email_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pmod_email_settings');

        if (Schema::hasTable('pmod_requests')) {
            Schema::table('pmod_requests', function (Blueprint $table): void {
                foreach (['notification_error', 'notification_sent_at', 'notification_status', 'failure_type', 'result_type'] as $column) {
                    if (Schema::hasColumn('pmod_requests', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
