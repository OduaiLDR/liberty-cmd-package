<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/** Explicit opt-in evidence only. Does not enqueue or send historical campaigns. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== ''
            || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('SMS email evidence requires an unprefixed connection and its own transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            if (! $schema->hasTable('TblSMSRuns') || ! $schema->hasColumns('TblSMSRuns', [
                'Request_ID', 'Status', 'Selection_Policy', 'Approved_At', 'Approved_By', 'Run_Options_JSON', 'Artifact_Manifest_JSON',
            ]) || $schema->hasTable('TblSMSEmailOutbox')) {
                throw new RuntimeException('Apply the reviewed SMS approval/file-options schema before installing a new email outbox.');
            }
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->selectFromWriteConnection("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name IN ('TblSMSRuns','TblSMSEmailOutbox')");
                if (count($objects) !== 1 || $objects[0]->schema_name !== 'dbo' || $objects[0]->name !== 'TblSMSRuns') {
                    throw new RuntimeException('SMS email evidence requires an unambiguous dbo run table and an absent outbox.');
                }
            }
            $schema->create('TblSMSEmailOutbox', function (Blueprint $table): void {
                $table->string('Request_ID', 36)->primary();
                $table->string('Run_Sha256', 64);
                $table->longText('Payload_JSON');
                $table->string('Payload_Sha256', 64);
                $table->string('State', 16);
                $table->string('Created_By', 255);
                $table->dateTime('Created_At');
                $table->dateTime('Attempted_At')->nullable();
                $table->dateTime('Accepted_At')->nullable();
                $table->string('Error_Code', 64)->nullable();
            });
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain SMS email acceptance/uncertainty evidence; roll back application code instead.');
    }
};
