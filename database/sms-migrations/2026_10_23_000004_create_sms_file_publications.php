<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/** Separate opt-in publication evidence; never shares or changes an existing campaign. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true) || $db->getTablePrefix() !== ''
            || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('SMS publication evidence requires an unprefixed connection and its own transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            if (! $schema->hasTable('TblSMSRuns') || ! $schema->hasColumns('TblSMSRuns', [
                'Request_ID', 'Status', 'Selection_Policy', 'Approved_By', 'Approved_At', 'Run_Options_JSON', 'Artifact_Manifest_JSON',
            ]) || $schema->hasTable('TblSMSFilePublications') || $schema->hasTable('TblSMSFilePublicationParts')) {
                throw new RuntimeException('Apply the reviewed SMS approval schema before installing new publication evidence.');
            }
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->selectFromWriteConnection("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name IN ('TblSMSRuns','TblSMSFilePublications','TblSMSFilePublicationParts')");
                if (count($objects) !== 1 || $objects[0]->schema_name !== 'dbo' || $objects[0]->name !== 'TblSMSRuns') {
                    throw new RuntimeException('SMS publication evidence requires an unambiguous dbo run table and absent publication tables.');
                }
            }
            $schema->create('TblSMSFilePublications', function (Blueprint $table): void {
                $table->string('Request_ID', 36)->primary();
                $table->string('Run_Sha256', 64);
                $table->longText('Plan_JSON');
                $table->string('Plan_Sha256', 64);
                $table->string('State', 16);
                $table->string('Created_By', 255);
                $table->dateTime('Created_At');
                $table->dateTime('Completed_At')->nullable();
                $table->string('Error_Code', 64)->nullable();
            });
            $schema->create('TblSMSFilePublicationParts', function (Blueprint $table): void {
                $table->string('Request_ID', 36);
                $table->integer('Part_Number');
                $table->string('State', 16);
                $table->dateTime('Attempted_At')->nullable();
                $table->dateTime('Completed_At')->nullable();
                $table->longText('Receipt_JSON')->nullable();
                $table->string('Receipt_Sha256', 64)->nullable();
                $table->string('Error_Code', 64)->nullable();
                $table->primary(['Request_ID', 'Part_Number']);
            });
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain SMS publication evidence; roll back application code instead.');
    }
};
