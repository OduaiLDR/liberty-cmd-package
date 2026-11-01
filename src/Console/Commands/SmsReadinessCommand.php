<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\SmsReleaseReadiness;
use Cmd\Reports\Services\SmsTestEnvironmentProbe;
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
        $testEnvironment = (new SmsTestEnvironmentProbe)->report();
        $this->line('SMS authenticated test environment: '.json_encode($testEnvironment, JSON_THROW_ON_ERROR));
        foreach ($testEnvironment['blockers'] as $blocker) $this->warn($blocker['message']);
        foreach (['TblProspects', 'TblProspectsExport', 'TblSMSHistory', 'TblSMSRuns', 'TblSMSAttemptAudit', 'TblSMSSuppressions', 'TblSMSEnrichmentBatches', 'TblSMSAvailabilitySummary', 'TblSMSResponseAttribution'] as $name) {
            $count = $db->selectOne('SELECT SUM(row_count) AS Rows FROM sys.dm_db_partition_stats WHERE object_id = OBJECT_ID(?) AND index_id IN (0,1)', ['dbo.'.$name]);
            $this->line($name.': '.($schema->hasTable($name) ? number_format((int) ($count->Rows ?? 0)).' rows (metadata)' : 'not installed'));
        }
        $has = static fn (string $table, array $columns): bool => $schema->hasTable($table) && $schema->hasColumns($table, $columns);
        $recordSchema = $has('TblProspects', \Cmd\Reports\Services\SmsProspectSchema::recordColumns())
            && $has('TblSMSRuns', ['Selection_Policy', 'Never_Texted', 'Previously_Texted', 'Approved_At', 'Approved_By', 'File_Cleanup_Path'])
            && $has('TblProspectsExport', ['Was_Previously_Texted', 'Last_SMS_Date_At_Selection'])
            && $has('TblSMSHistory', ['Was_Previously_Texted', 'Last_SMS_Date_At_Selection'])
            && $has('TblSMSResponseAttribution', ['Prospect_PK', 'Request_ID', 'Contact_At_UTC', 'Contacted_Date', 'Created_At']);
        if (\Cmd\Reports\Services\SmsProspectSchema::responseEnabled()) {
            try { \Cmd\Reports\Services\SmsProspectSchema::assertResponseReady($db); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException) { $recordSchema=false; }
        }
        $fileSchema = $has('TblSMSRuns', ['Run_Options_JSON', 'Artifact_Manifest_JSON']);
        $durableSummarySchema = $has('TblSMSAvailabilitySummary', [
            'scope', 'revision', 'published_revision', 'eligible', 'never_texted', 'texted_before', 'counted_at', 'latest_data_date',
        ]);
        $tuDateSchema = $durableSummarySchema && $has('TblSMSAvailabilitySummary', ['tu_date_verified']);
        $contactSchema = $recordSchema && $has('TblProspects', ['Contacted_Date'])
            && $has('TblSMSRuns', ['Approved_At_Precision'])
            && $has('TblSMSResponseAttribution', ['Contact_At_UTC_Exact', 'Source_System', 'Source_Contact_ID', 'Source_Contract_SHA256', 'Source_Candidate_Count']);
        foreach (['TblSMSHistory', 'TblProspectsExport'] as $table) {
            if (! $schema->hasTable($table)) {
                $contactSchema = false;
                continue;
            }
            $matches = array_filter($schema->getIndexes($table), static fn (array $index): bool =>
                strtolower($index['name']) === strtolower('IX_'.$table.'_Contact_Attribution')
                && array_map('strtolower', $index['columns']) === ['prospect_pk', 'phone', 'request_id']);
            $contactSchema = $contactSchema && count($matches) === 1;
        }
        $snapshot = $db->selectOne('SELECT snapshot_isolation_state_desc AS Snapshot_Isolation FROM sys.databases WHERE database_id = DB_ID()');
        $release = SmsReleaseReadiness::report([
            'mailer_recency_date' => config('sms-exports.mailer_recency_date', 'Send_Date'),
            'single_phone_csv' => config('sms-exports.single_phone_csv', false),
            'snapshot_isolation_enabled' => strtoupper((string) ($snapshot->Snapshot_Isolation ?? '')) === 'ON',
            'prospect_enrichment_enabled' => config('sms-exports.prospect_enrichment_enabled', false),
            'durable_availability_enabled' => config('sms-exports.durable_availability_enabled', false),
            'record_workflow_enabled' => config('sms-exports.record_workflow_enabled', false),
            'run_file_options_enabled' => config('sms-exports.run_file_options_enabled', false),
            'tier_selection_enabled' => config('sms-exports.tier_selection_enabled', false),
            'tu_completion_date_enabled' => config('sms-exports.tu_completion_date_enabled', false),
            'contact_attribution_enabled' => config('sms-exports.contact_attribution_enabled', false),
            'contact_source_system' => config('sms-exports.contact_source_system'),
            'contact_source_contract_sha256' => config('sms-exports.contact_source_contract_sha256'),
            'run_archive_enabled' => config('sms-exports.run_archive_enabled', false),
            'run_archive_policy_sha256' => config('sms-exports.run_archive_policy_sha256'),
            'email_handoff_enabled' => config('sms-exports.email_handoff_enabled', false),
            'email_report_name' => config('sms-exports.email_report_name', 'SMS Export'),
            'email_report_company' => config('sms-exports.email_report_company'),
            'email_report_source' => config('sms-exports.email_report_source', 'plaw'),
            'email_allowed_link_hosts' => config('sms-exports.email_allowed_link_hosts', []),
            'file_publication_enabled' => config('sms-exports.file_publication_enabled', false),
            'email_sender_configured' => config('sms-exports.email_handoff_enabled', false) === true
                && \Cmd\Reports\Services\GraphMailboxClient::isConfigured('GRAPH_LT'),
            'run_archive_provider_fields_present' => count(array_filter(array_map(
                static fn (string $field) => config('sms-exports.run_archive_'.$field),
                ['bucket', 'prefix', 'region', 'expected_owner', 'encryption']
            ), static fn ($value): bool => is_string($value) && trim($value) !== '')) === 5,
        ], [
            'durable_summary' => $durableSummarySchema,
            'record_workflow' => $recordSchema,
            'file_options' => $fileSchema,
            'tu_date_proof' => $tuDateSchema,
            'contact_attribution' => $contactSchema,
            'archive_receipt' => $fileSchema && $has('TblSMSRuns', ['Archive_Receipt_JSON']),
            'email_outbox' => $has('TblSMSEmailOutbox', ['Request_ID', 'Run_Sha256', 'Payload_JSON', 'Payload_Sha256',
                'State', 'Created_By', 'Created_At', 'Attempted_At', 'Accepted_At', 'Error_Code']),
            'file_publication' => $has('TblSMSFilePublications', ['Request_ID', 'Run_Sha256', 'Plan_JSON', 'Plan_Sha256', 'State', 'Created_By', 'Created_At', 'Completed_At', 'Error_Code'])
                && $has('TblSMSFilePublicationParts', ['Request_ID', 'Part_Number', 'State', 'Attempted_At', 'Completed_At', 'Receipt_JSON', 'Receipt_Sha256', 'Error_Code']),
        ], class_exists(\ZipArchive::class) && method_exists(\ZipArchive::class, 'setMtimeName'));
        $this->line('SMS delivery policy: '.json_encode($release['delivery_policy'], JSON_THROW_ON_ERROR));
        $this->line('SMS feature gates: '.json_encode($release['feature_gates'], JSON_THROW_ON_ERROR));
        $this->line('SMS controlled core production test: '.json_encode($release['controlled_core_test'], JSON_THROW_ON_ERROR));
        $this->line('SMS artifact storage: '.json_encode($release['artifact_storage'], JSON_THROW_ON_ERROR));
        $this->line('SMS email handoff: '.json_encode($release['email_handoff'], JSON_THROW_ON_ERROR));
        if ($release['email_handoff']['enabled'] && ! $release['email_handoff']['local_prerequisites_present']) {
            $this->warn('SMS email is enabled without the outbox schema, explicit table scope, allowed link hosts or Lending Tower sender configuration.');
        }
        $this->line('External release acceptance still required: '.json_encode($release['external_release_acceptance'], JSON_THROW_ON_ERROR));
        $archive = $release['artifact_storage'];
        if ($archive['archive_enabled'] && (! $archive['archive_receipt_schema_ready']
            || ! $archive['archive_policy_hash_configured'] || ! $archive['archive_provider_fields_present'])) {
            $this->warn('SMS archival is enabled without its explicit receipt schema, policy hash or provider fields.');
        }
        if ($release['delivery_policy']['mailer_clock'] === 'UNCONFIRMED') {
            $this->warn('New debt lookup is blocked by an obsolete mailer-clock override; Jacob confirmed TblMarketing.Send_Date.');
        }
        foreach ($release['feature_gates'] as $name => $gate) {
            if ($gate['enabled'] && ! $gate['configuration_schema_ready']) {
                $this->warn($name.' is enabled without all required local configuration/schema prerequisites. Disable it until the coordinated release is complete.');
            }
        }
        $coreTest = $release['controlled_core_test'];
        if (! $coreTest['core_configuration_schema_ready']) {
            $this->warn('The four core SMS workflow gates are not ready together with the required schema/runtime prerequisites.');
        }
        if (! $coreTest['external_integrations_disabled']) {
            $this->warn('A controlled core test is not isolated: disable unconfigured TU enrichment/date, contact attribution, archive, publication and email integrations.');
        }
        $this->line('Durable SMS summary: '.json_encode([
            'enabled' => (bool) config('sms-exports.durable_availability_enabled', false),
            'snapshot_isolation' => $snapshot->Snapshot_Isolation ?? 'unknown',
        ], JSON_THROW_ON_ERROR));
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
