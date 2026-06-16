<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pmod_requests', function (Blueprint $table): void {
            if (!Schema::hasColumn('pmod_requests', 'result_type')) {
                $table->string('result_type')->nullable()->after('status');
            }
            if (!Schema::hasColumn('pmod_requests', 'failure_type')) {
                $table->string('failure_type')->nullable()->after('result_type');
            }
            if (!Schema::hasColumn('pmod_requests', 'notification_status')) {
                $table->string('notification_status')->nullable()->after('failure_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pmod_requests', function (Blueprint $table): void {
            $table->dropColumn(['result_type', 'failure_type', 'notification_status']);
        });
    }
};
