<?php

use Cmd\Reports\Services\SmsProspectBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Additive only. Publish/apply explicitly after backup checks; no prospect rows are changed. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';

    // The standalone installer owns the atomic transaction and rejects nesting.
    public $withinTransaction = false;

    public function up(): void
    {
        SmsProspectBackfill::install(DB::connection($this->getConnection())->getPdo());
    }

    public function down(): void
    {
        throw new RuntimeException('Retain the backfill checkpoint and row provenance. Roll back code, not evidence.');
    }
};
