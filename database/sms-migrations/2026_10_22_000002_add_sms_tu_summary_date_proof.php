<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Mark only snapshots that actually read the approved TU completion contract. */
return new class extends Migration
{
    protected $connection = 'sqlsrv';
    public $withinTransaction = false;

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        if (! in_array($db->getDriverName(), ['sqlsrv', 'sqlite'], true)
            || $db->getTablePrefix() !== '' || $db->transactionLevel() !== 0) {
            throw new RuntimeException('SMS TU date-proof migration requires its own unprefixed SQL Server/SQLite transaction.');
        }
        $db->transaction(function () use ($db): void {
            $schema = $db->getSchemaBuilder();
            if (! $schema->hasTable('TblSMSAvailabilitySummary') || ! $schema->hasColumns('TblSMSAvailabilitySummary', [
                'scope', 'revision', 'published_revision', 'eligible', 'never_texted', 'texted_before', 'counted_at', 'latest_data_date',
            ])) throw new RuntimeException('Apply the reviewed durable SMS summary schema first.');
            if ($schema->hasColumn('TblSMSAvailabilitySummary', 'tu_date_verified')) {
                throw new RuntimeException('SMS TU date-proof schema already exists.');
            }
            if ($db->getDriverName() === 'sqlsrv') {
                $objects = $db->selectFromWriteConnection("SELECT s.name AS schema_name,t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id=t.schema_id WHERE t.name='TblSMSAvailabilitySummary'");
                if (count($objects) !== 1 || $objects[0]->schema_name !== 'dbo') {
                    throw new RuntimeException('SMS summary must be the unambiguous dbo table.');
                }
                $db->statement('ALTER TABLE [dbo].[TblSMSAvailabilitySummary] ADD [tu_date_verified] bit NOT NULL DEFAULT (0)');
            } else {
                $db->statement('ALTER TABLE "main"."TblSMSAvailabilitySummary" ADD COLUMN "tu_date_verified" integer NOT NULL DEFAULT 0');
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Retain SMS summary date proof; roll back application code, not this evidence.');
    }
};
