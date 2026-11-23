<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** One nullable column: the choice made at Approve to publish and email the files. Never rewrites an existing run. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== '' || $db->transactionLevel() !== 0) {
            throw new RuntimeException('The SMS handoff choice requires an unprefixed connection and its own transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            if (! $schema->hasTable('TblSMSRuns') || ! $schema->hasColumns('TblSMSRuns', ['Request_ID', 'Status', 'Approved_By', 'Approved_At', 'Run_Options_JSON'])
                || $schema->hasColumn('TblSMSRuns', 'Handoff_Requested')) throw new RuntimeException('Apply the reviewed versioned SMS schema before the handoff choice migration.');
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->selectFromWriteConnection("SELECT s.name AS schema_name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name='TblSMSRuns'");
                if (count($objects) !== 1 || $objects[0]->schema_name !== 'dbo') throw new RuntimeException('The SMS handoff choice requires the unambiguous dbo run table.');
                $db->statement('ALTER TABLE [dbo].[TblSMSRuns] ADD [Handoff_Requested] bit NULL');
            } else {
                $db->statement('ALTER TABLE "main"."TblSMSRuns" ADD COLUMN "Handoff_Requested" integer NULL');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain the SMS handoff choice; roll back application code instead.');
    }
};
