<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/** Explicit opt-in outbox only; installing this table never sends or imports data. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (!in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== ''
            || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('SMS import evidence requires its own unprefixed SQL transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            if (!$schema->hasTable('TblSMSRuns') || !$schema->hasColumns('TblSMSRuns', [
                'Request_ID', 'Status', 'Selection_Policy', 'Approved_By', 'Approved_At', 'Run_Options_JSON', 'Artifact_Manifest_JSON',
            ]) || $schema->hasTable('TblSMSDebtPleteImports')) {
                throw new RuntimeException('SMS import evidence requires reviewed approval tables and an absent outbox.');
            }
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->selectFromWriteConnection("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name IN ('TblSMSRuns','TblSMSDebtPleteImports')");
                if (count($objects) !== 1 || $objects[0]->schema_name !== 'dbo' || $objects[0]->name !== 'TblSMSRuns') {
                    throw new RuntimeException('SMS import evidence requires an unambiguous dbo approval table.');
                }
            }
            $schema->create('TblSMSDebtPleteImports', function (Blueprint $table): void {
                $table->string('Request_ID', 36)->primary();
                $table->string('Run_Sha256', 64);
                $table->longText('Destination_JSON');
                $table->string('Destination_Sha256', 64);
                $table->longText('Plan_JSON');
                $table->string('Plan_Sha256', 64);
                $table->string('State', 24);
                $table->longText('Remote_Receipt_JSON')->nullable();
                $table->string('Remote_Receipt_Sha256', 64)->nullable();
                $table->string('Lease_Token', 64)->nullable();
                $table->bigInteger('Lease_Version')->default(0);
                $table->dateTime('Lease_Until', 6)->nullable();
                $table->string('Created_By', 255);
                $table->dateTime('Created_At', 6);
                $table->dateTime('Updated_At', 6);
                $table->dateTime('Completed_At', 6)->nullable();
                $table->string('Error_Code', 64)->nullable();
                $table->index(['State', 'Lease_Until', 'Request_ID'], 'sms_debtplete_import_recovery');
            });
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain SMS import evidence; roll back application code instead.');
    }
};
