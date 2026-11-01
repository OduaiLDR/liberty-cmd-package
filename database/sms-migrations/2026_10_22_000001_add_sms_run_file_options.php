<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Additive opt-in storage only. No existing run or recipient is rewritten. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true)
            || $db->getTablePrefix() !== '' || $db->transactionLevel() !== 0) {
            throw new RuntimeException('SMS file-options migration requires an unprefixed SQL Server/SQLite connection and its own transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            if (! $schema->hasTable('TblSMSRuns') || ! $schema->hasColumns('TblSMSRuns', [
                'PK', 'Request_ID', 'Target', 'Status', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path',
            ])) throw new RuntimeException('Apply the existing SMS workflow and record-approval schema first.');
            foreach (['Run_Options_JSON', 'Artifact_Manifest_JSON'] as $column) {
                if ($schema->hasColumn('TblSMSRuns', $column)) {
                    throw new RuntimeException('SMS file-options schema already exists or is partially installed.');
                }
            }
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->selectFromWriteConnection("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name='TblSMSRuns'");
                if (count($objects) !== 1 || $objects[0]->schema_name !== 'dbo') {
                    throw new RuntimeException('SMS run table must be the unambiguous dbo table.');
                }
                $db->statement('ALTER TABLE [dbo].[TblSMSRuns] ADD [Run_Options_JSON] nvarchar(max) NULL, [Artifact_Manifest_JSON] nvarchar(max) NULL');
            } else {
                $db->statement('ALTER TABLE "main"."TblSMSRuns" ADD COLUMN "Run_Options_JSON" text NULL');
                $db->statement('ALTER TABLE "main"."TblSMSRuns" ADD COLUMN "Artifact_Manifest_JSON" text NULL');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain frozen SMS file options and artifact receipts. Roll back application code, not this evidence.');
    }
};
