<?php

use Cmd\Reports\Services\SmsSelectionCancellation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/** Explicit new coordination table only. Never imports or changes SMS source/history data. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $db = SmsSelectionCancellation::storeConnection();
        if (! in_array($db->getDriverName(), ['mysql', 'sqlite'], true)
            || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('Selection cancellation requires its independent queue database.');
        }
        if ($db->getSchemaBuilder()->hasTable(SmsSelectionCancellation::TABLE)) {
            throw new RuntimeException('The selection cancellation table already exists; preserve its receipts.');
        }
        $db->getSchemaBuilder()->create(SmsSelectionCancellation::TABLE, function (Blueprint $table): void {
            $table->uuid('request_id')->primary();
            $table->string('created_by', 255);
            $table->char('options_sha256', 64);
            $table->string('state', 16);
            $table->string('requested_by', 255)->nullable();
            $table->dateTime('requested_at', 6)->nullable();
            $table->dateTime('job_enqueued_at', 6)->nullable();
            $table->dateTime('finished_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain cancellation receipts and recovery work; disable new requests instead.');
    }
};
