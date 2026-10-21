<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive aggregate ledger; existing prospects, history and source dates are untouched. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('TblSMSAvailabilitySummary', function (Blueprint $t): void {
            $t->char('scope', 64)->primary();
            $t->bigInteger('revision')->default(0);
            $t->bigInteger('published_revision')->default(0);
            $t->bigInteger('count_revision')->nullable();
            $t->string('status', 10)->default('idle'); // idle, queued, running, ready, failed
            $t->uuid('generation')->nullable();
            foreach (['requested_at', 'started_at', 'lease_until', 'retry_at', 'counted_at'] as $column) {
                $t->dateTime($column)->nullable(); // Always UTC, independent of app timezone.
            }
            foreach (['eligible', 'never_texted', 'texted_before'] as $column) {
                $t->bigInteger($column)->nullable();
            }
            // Authoritative source-date contracts are not established yet. Counts never set this.
            $t->date('latest_data_date')->nullable();
            $t->string('error_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('TblSMSAvailabilitySummary');
    }
};
