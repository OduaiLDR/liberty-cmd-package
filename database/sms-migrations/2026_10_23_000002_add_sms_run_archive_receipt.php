<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Additive opt-in archive evidence. Never rewrites an existing run or recipient. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== '' || $db->transactionLevel() !== 0) {
            throw new RuntimeException('SMS archive receipts require an unprefixed connection and their own transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            if (! $schema->hasTable('TblSMSRuns') || ! $schema->hasColumns('TblSMSRuns', ['Request_ID', 'Status', 'Approved_At', 'Run_Options_JSON', 'Artifact_Manifest_JSON'])
                || $schema->hasColumn('TblSMSRuns', 'Archive_Receipt_JSON')) throw new RuntimeException('Apply the reviewed versioned SMS schema before the new archive receipt migration.');
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->selectFromWriteConnection("SELECT s.name AS schema_name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name='TblSMSRuns'");
                if (count($objects) !== 1 || $objects[0]->schema_name !== 'dbo') throw new RuntimeException('SMS archive receipts require the unambiguous dbo run table.');
                $db->statement('ALTER TABLE [dbo].[TblSMSRuns] ADD [Archive_Receipt_JSON] nvarchar(max) NULL');
            } else {
                $db->statement('ALTER TABLE "main"."TblSMSRuns" ADD COLUMN "Archive_Receipt_JSON" text NULL');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain SMS archive evidence; roll back application code instead.');
    }
};
