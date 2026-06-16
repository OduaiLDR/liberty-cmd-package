<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pmod_requests', function (Blueprint $table): void {
            if (!Schema::hasColumn('pmod_requests', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pmod_requests', function (Blueprint $table): void {
            $table->dropColumn('contact_name');
        });
    }
};
