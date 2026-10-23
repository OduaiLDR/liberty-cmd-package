<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/**
 * Pure release-gate summary for the SMS interface.
 *
 * This deliberately separates code/config/schema readiness from the external
 * approvals and production evidence that a local diagnostic cannot establish.
 */
final class SmsReleaseReadiness
{
    /**
     * @param array<string,mixed> $settings
     * @param array<string,bool> $schema
     * @return array<string,mixed>
     */
    public static function report(array $settings, array $schema, bool $zipAvailable): array
    {
        $mailerClock = (string) ($settings['mailer_recency_date'] ?? 'Send_Date');
        $contractHash = trim((string) ($settings['contact_source_contract_sha256'] ?? ''));
        $sourceSystem = trim((string) ($settings['contact_source_system'] ?? ''));
        $flags = [
            'durable_summary' => (bool) ($settings['durable_availability_enabled'] ?? false),
            'record_workflow' => (bool) ($settings['record_workflow_enabled'] ?? false),
            'versioned_file_options' => (bool) ($settings['run_file_options_enabled'] ?? false),
            'source_tier_selection' => (bool) ($settings['tier_selection_enabled'] ?? false),
            'completed_tu_date' => (bool) ($settings['tu_completion_date_enabled'] ?? false),
            'contact_attribution' => (bool) ($settings['contact_attribution_enabled'] ?? false),
        ];

        $durableReady = $flags['durable_summary'] && (bool) ($schema['durable_summary'] ?? false);
        $recordReady = $flags['record_workflow'] && (bool) ($schema['record_workflow'] ?? false) && $durableReady;
        $fileReady = $flags['versioned_file_options'] && (bool) ($schema['file_options'] ?? false)
            && $recordReady && $zipAvailable;
        $sourceConfigured = (bool) preg_match('/^[a-z][a-z0-9._-]{0,39}$/D', $sourceSystem);
        $hashConfigured = (bool) preg_match('/^[a-f0-9]{64}$/D', $contractHash);
        $emailFields = true;
        foreach (['email_report_name', 'email_report_company'] as $field) {
            $value = $settings[$field] ?? null;
            $emailFields = $emailFields && is_string($value) && $value !== '' && trim($value) === $value
                && strlen($value) <= 128 && ! preg_match('/[\x00-\x1f\x7f]/', $value);
        }
        $emailFields = $emailFields && in_array($settings['email_report_source'] ?? 'plaw', ['ldr', 'plaw'], true);
        $emailHosts = $settings['email_allowed_link_hosts'] ?? [];
        $emailHostsPresent = is_array($emailHosts) && array_is_list($emailHosts) && $emailHosts !== [];
        if ($emailHostsPresent) foreach ($emailHosts as $host) {
            $emailHostsPresent = $emailHostsPresent && is_string($host) && strlen($host) <= 253
                && ! filter_var($host, FILTER_VALIDATE_IP)
                && ! str_ends_with(strtolower($host), '.localhost')
                && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])$/iD', $host) === 1;
        }
        $gates = [
            'durable_summary' => [
                'enabled' => $flags['durable_summary'],
                'schema_ready' => (bool) ($schema['durable_summary'] ?? false),
                'configuration_schema_ready' => $durableReady,
            ],
            'record_workflow' => [
                'enabled' => $flags['record_workflow'],
                'schema_ready' => (bool) ($schema['record_workflow'] ?? false),
                'durable_summary_ready' => $durableReady,
                'configuration_schema_ready' => $recordReady,
            ],
            'versioned_file_options' => [
                'enabled' => $flags['versioned_file_options'],
                'schema_ready' => (bool) ($schema['file_options'] ?? false),
                'record_workflow_ready' => $recordReady,
                'zip_ready' => $zipAvailable,
                'configuration_schema_ready' => $fileReady,
            ],
            'source_tier_selection' => [
                'enabled' => $flags['source_tier_selection'],
                'versioned_file_options_ready' => $fileReady,
                'snapshot_isolation_ready' => ($settings['snapshot_isolation_enabled'] ?? false) === true,
                'production_plan_scale_acceptance' => false,
                'configuration_schema_ready' => $flags['source_tier_selection'] && $fileReady
                    && ($settings['snapshot_isolation_enabled'] ?? false) === true,
            ],
            'completed_tu_date' => [
                'enabled' => $flags['completed_tu_date'],
                'schema_ready' => (bool) ($schema['tu_date_proof'] ?? false),
                'durable_summary_ready' => $durableReady,
                'source_completion_contract_accepted' => false,
                'configuration_schema_ready' => $flags['completed_tu_date']
                    && (bool) ($schema['tu_date_proof'] ?? false) && $durableReady,
            ],
            'contact_attribution' => [
                'enabled' => $flags['contact_attribution'],
                'schema_ready' => (bool) ($schema['contact_attribution'] ?? false),
                'record_workflow_ready' => $recordReady,
                'source_system_configured' => $sourceConfigured,
                'contract_hash_configured' => $hashConfigured,
                'source_matching_coverage_accepted' => false,
                'configuration_schema_ready' => $flags['contact_attribution']
                    && (bool) ($schema['contact_attribution'] ?? false) && $recordReady
                    && $sourceConfigured && $hashConfigured,
            ],
        ];
        $coreGateNames = ['durable_summary', 'record_workflow', 'versioned_file_options', 'source_tier_selection'];
        $coreConfigurationSchemaReady = $mailerClock === 'Send_Date';
        foreach ($coreGateNames as $gateName) {
            $coreConfigurationSchemaReady = $coreConfigurationSchemaReady
                && $gates[$gateName]['configuration_schema_ready'];
        }
        $optionalIntegrationsDisabled = [
            'prospect_enrichment' => ! (bool) ($settings['prospect_enrichment_enabled'] ?? false),
            'completed_tu_date' => ! $flags['completed_tu_date'],
            'contact_attribution' => ! $flags['contact_attribution'],
            'archive' => ! (bool) ($settings['run_archive_enabled'] ?? false),
            'file_publication' => ! (bool) ($settings['file_publication_enabled'] ?? false),
            'email_handoff' => ($settings['email_handoff_enabled'] ?? false) !== true,
        ];
        $externalIntegrationsDisabled = ! in_array(false, $optionalIntegrationsDisabled, true);

        return [
            'delivery_policy' => [
                'prospect_debt_tier' => 'source-only, never stored on TblProspects',
                'mailer_clock' => $mailerClock === 'Send_Date' ? 'TblMarketing.Send_Date' : 'UNCONFIRMED',
                'source_priority' => 'latest TU; only without a TU match, latest positive-debt mailer; otherwise latest mailer tier value',
                'debt_rounding' => 'up to whole 1000',
                'planned_send_date' => 'following Monday by default; editable today or future California date, frozen for new planned-date runs; legacy dates unchanged',
                'approval_date' => 'actual approval day, America/Los_Angeles',
                'sms_ids' => 'assigned only after approval is requested for new approval-ID runs; pending preparation allocates none',
                'pending_filename' => 'SMS Export - [Send Date] - ID Pending - Part x of n.csv',
                'approved_filename' => 'SMS Export - [Send Date] - [Full batch SMS range] - Part x of n.csv',
                'legacy_csv_columns' => (bool) ($settings['single_phone_csv'] ?? false) ? 5 : 9,
                'versioned_csv_columns' => 4,
            ],
            'feature_gates' => $gates,
            'controlled_core_test' => [
                'environment' => 'production',
                'core_gate_names' => $coreGateNames,
                'core_configuration_schema_ready' => $coreConfigurationSchemaReady,
                'optional_integrations_disabled' => $optionalIntegrationsDisabled,
                'external_integrations_disabled' => $externalIntegrationsDisabled,
                'local_configuration_isolated' => $coreConfigurationSchemaReady && $externalIntegrationsDisabled,
                'first_safe_scope' => 'pending-only: generate, download/replay, verify allowed and denied real roles, discard, then prove zero history/attempt/date mutations',
                'approval_or_send_tracking_change' => 'not covered; requires separately approved bounded records, quantity, accounts and actions',
                'production_evidence_verified' => false,
                'real_campaign_permitted' => false,
                'real_campaign_prerequisites' => [
                    'replacement-data reconciliation accepted',
                    'already-used 3M batch registered exactly once',
                ],
            ],
            'email_handoff' => [
                'enabled' => ($settings['email_handoff_enabled'] ?? false) === true,
                'outbox_schema_ready' => (bool) ($schema['email_outbox'] ?? false),
                'recipient_source' => 'dbo.TblReports; strict report/company, no extra environment recipients',
                'scope_fields_present' => $emailFields,
                'allowed_link_hosts_present' => $emailHostsPresent,
                'sender_configuration_present' => ($settings['email_sender_configured'] ?? false) === true,
                'local_prerequisites_present' => ($settings['email_handoff_enabled'] ?? false) === true
                    && (bool) ($schema['email_outbox'] ?? false) && $emailFields && $emailHostsPresent
                    && ($settings['email_sender_configured'] ?? false) === true,
                'actual_recipient_rows_verified' => false,
                'automatic_link_publisher' => 'explicit_opt_in_command',
                'file_publication_enabled' => ($settings['file_publication_enabled'] ?? false) === true,
                'file_publication_schema_ready' => (bool) ($schema['file_publication'] ?? false),
                'file_publication_destination_policy_accepted' => false,
                'debtplete_import' => 'not_connected',
                'external_acceptance_verified' => false,
            ],
            'artifact_storage' => [
                'versioned_primary' => 'private local bundle with SHA-256 manifest',
                'archive_integrity_core' => 'exact original manifest and primary, create-only versioned S3 adapter',
                'archive_workflow_wiring' => 'explicit approved-run archive/verify/restore; no automatic timing or deletion',
                'archive_enabled' => (bool) ($settings['run_archive_enabled'] ?? false),
                'archive_receipt_schema_ready' => (bool) ($schema['archive_receipt'] ?? false),
                'archive_policy_hash_configured' => (bool) preg_match('/^[a-f0-9]{64}$/D', (string) ($settings['run_archive_policy_sha256'] ?? '')),
                'archive_provider_fields_present' => (bool) ($settings['run_archive_provider_fields_present'] ?? false),
                'archive_destination_policy' => 'requires external acceptance; configured values are not evidence of approval',
                'retention_policy' => 'requires external acceptance and provider enforcement',
            ],
            'external_release_acceptance' => [
                'replacement_data_reconciliation',
                'production source plans and scale',
                'private archive destination, timing and retention',
                'actual role grants and authenticated sessions',
                'TU completion provenance',
                'original contact event and matching coverage',
                'Upturn handoff and result mappings',
                'Dropbox account, folder, recipient access and sharing expiry',
                'exact-once registration of the already-used 3M batch',
            ],
        ];
    }
}
