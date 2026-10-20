<?php

namespace Cmd\Reports\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** SELECT/metadata only. Safe diagnostic before approving a live migration or campaign. */
class SmsReadinessCommand extends Command
{
    protected $signature = 'sms:readiness {--request-id= : Include the state of one run, without recipient data}';
    protected $description = 'Read-only SMS schema, repair, backup and run checks. Changes nothing.';

    public function handle(): int
    {
        $db = DB::connection('sqlsrv');
        if ($db->getDriverName() !== 'sqlsrv') { $this->error('This diagnostic requires SQL Server.'); return self::FAILURE; }
        $this->info('READ ONLY: no data or schema will be changed.');
        $this->line(json_encode($db->selectOne('SELECT @@SERVERNAME AS Server_Name, DB_NAME() AS Database_Name'), JSON_THROW_ON_ERROR));
        $schema = $db->getSchemaBuilder();
        foreach (['TblProspects', 'TblProspectsExport', 'TblSMSHistory', 'TblSMSRuns', 'TblSMSAttemptAudit', 'TblSMSSuppressions', 'TblSMSEnrichmentBatches'] as $name) {
            $count = $db->selectOne('SELECT SUM(row_count) AS Rows FROM sys.dm_db_partition_stats WHERE object_id = OBJECT_ID(?) AND index_id IN (0,1)', ['dbo.'.$name]);
            $this->line($name.': '.($schema->hasTable($name) ? number_format((int) ($count->Rows ?? 0)).' rows (metadata)' : 'not installed'));
        }
        if ($schema->hasTable('tu_repair_20261014.Control')) {
            $repair = $db->selectOne(<<<'SQL'
SELECT c.Status AS Repair_Status,
    CASE WHEN OBJECT_ID(N'dbo.TblMailersUniqueEnriched') = c.ShadowObjectID THEN 1 ELSE 0 END AS Correct_Table_Is_Live,
    (SELECT SUM(row_count) FROM sys.dm_db_partition_stats WHERE object_id = c.ShadowObjectID AND index_id IN (0,1)) AS Live_Rows,
    (SELECT SUM(OutputRows) FROM tu_repair_20261014.Buckets) AS Verified_Rows,
    (SELECT SUM(CONVERT(bigint, Imported)) FROM dbo.TblEnrichedDropNames) AS Summary_Rows,
    (SELECT SUM(SyntheticRowsRemaining) FROM tu_repair_20261014.Buckets) AS Synthetic_Rows,
    (SELECT COUNT(*) FROM sys.indexes WHERE object_id = c.ShadowObjectID AND index_id > 0 AND is_disabled = 0) AS Enabled_Indexes,
    c.PostPublishWrites
FROM tu_repair_20261014.Control c WHERE c.ID = 1
SQL);
            $this->line('Repair: '.json_encode($repair, JSON_THROW_ON_ERROR));
        }
        try {
            $backups = $db->select('SELECT TOP (5) backup_type, backup_start_date, backup_finish_date FROM sys.dm_database_backups ORDER BY backup_finish_date DESC');
            $this->line('Recent Azure backups: '.json_encode($backups, JSON_THROW_ON_ERROR));
        } catch (Throwable) { $this->warn('Backup metadata is not available to this login. Verify the Azure restore window separately before any live writes.'); }
        $id = trim((string) $this->option('request-id'));
        if ($id !== '') {
            if (! \Illuminate\Support\Str::isUuid($id)) { $this->error('Use a UUID request ID.'); return self::FAILURE; }
            $run = $db->table('TblSMSRuns')->where('Request_ID', $id)->first(['Request_ID', 'Status', 'Selected', 'Created_At', 'Sent_At']);
            $this->line('Run: '.json_encode($run, JSON_THROW_ON_ERROR));
            if ($run && $run->Status === 'sent' && (! $schema->hasTable('TblSMSAttemptAudit') || ! $db->table('TblSMSAttemptAudit')->where('Request_ID', $id)->exists())) {
                $this->warn('This send has NO before-value audit. If unused, do not decrement/reset dates blindly. Reconcile affected prospects from a pre-send restore point.');
            }
        }
        $this->line('Source indexes: '.json_encode($db->select("SELECT OBJECT_NAME(i.object_id) AS Table_Name, i.name, i.is_unique, i.has_filter FROM sys.indexes i WHERE i.object_id IN (OBJECT_ID('dbo.TblMailersUniqueEnriched'),OBJECT_ID('dbo.TblTUData'),OBJECT_ID('dbo.TblProspects')) AND i.index_id > 0 AND i.is_disabled = 0 ORDER BY Table_Name, i.name"), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
