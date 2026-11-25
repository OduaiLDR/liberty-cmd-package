<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('prospect_list_export_files', function (Blueprint $table) {
            $table->id()->startingValue(1_000_000_000);
            $table->string('export_key', 80);
            $table->unsignedInteger('part_number');
            $table->string('mailer_id', 100)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->timestamp('created_at');
            $table->unique(['export_key', 'part_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospect_list_export_files');
    }
};
