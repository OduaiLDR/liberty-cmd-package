<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsReleaseReadiness;

function smsReadinessSettings(array $overrides = []): array
{
    return $overrides + [
        'mailer_recency_date' => 'Send_Date',
        'single_phone_csv' => false,
        'snapshot_isolation_enabled' => true,
        'prospect_enrichment_enabled' => false,
        'durable_availability_enabled' => false,
        'record_workflow_enabled' => false,
        'run_file_options_enabled' => false,
        'tier_selection_enabled' => false,
        'tu_completion_date_enabled' => false,
        'contact_attribution_enabled' => false,
        'contact_source_system' => null,
        'contact_source_contract_sha256' => null,
        'run_archive_enabled' => false,
        'email_handoff_enabled' => false,
        'file_publication_enabled' => false,
    ];
}

function smsReadinessSchema(array $overrides = []): array
{
    return $overrides + [
        'durable_summary' => false,
        'record_workflow' => false,
        'file_options' => false,
        'tu_date_proof' => false,
        'contact_attribution' => false,
    ];
}

test('readiness distinguishes the new four-column contract from retained legacy formats', function (): void {
    $report = SmsReleaseReadiness::report(smsReadinessSettings(), smsReadinessSchema(), false);

    expect($report['delivery_policy'])->toMatchArray([
        'mailer_clock' => 'TblMarketing.Send_Date',
        'planned_send_date' => 'following Monday by default; editable today or future California date, frozen for new planned-date runs; legacy dates unchanged',
        'approval_date' => 'actual approval day, America/Los_Angeles',
        'sms_ids' => 'assigned only after approval is requested for new approval-ID runs; pending preparation allocates none',
        'pending_filename' => 'SMS Export - [Send Date] - ID Pending - Part x of n.csv',
        'approved_filename' => 'SMS Export - [Send Date] - [Full batch SMS range] - Part x of n.csv',
        'legacy_csv_columns' => 9,
        'versioned_csv_columns' => 4,
    ])->and($report['artifact_storage'])->toBe([
        'versioned_primary' => 'private local bundle with SHA-256 manifest',
        'archive_integrity_core' => 'exact original manifest and primary, create-only versioned S3 adapter',
        'archive_workflow_wiring' => 'explicit approved-run archive/verify/restore; no automatic timing or deletion',
        'archive_enabled' => false,
        'archive_receipt_schema_ready' => false,
        'archive_policy_hash_configured' => false,
        'archive_provider_fields_present' => false,
        'archive_destination_policy' => 'requires external acceptance; configured values are not evidence of approval',
        'retention_policy' => 'requires external acceptance and provider enforcement',
    ]);

    $five = SmsReleaseReadiness::report(smsReadinessSettings(['single_phone_csv' => true]), smsReadinessSchema(), false);
    expect($five['delivery_policy']['legacy_csv_columns'])->toBe(5)
        ->and($five['delivery_policy']['versioned_csv_columns'])->toBe(4);
});

test('enabled local features fail closed until their schema and runtime prerequisites exist', function (): void {
    $settings = smsReadinessSettings([
        'durable_availability_enabled' => true,
        'record_workflow_enabled' => true,
        'run_file_options_enabled' => true,
        'tier_selection_enabled' => true,
        'tu_completion_date_enabled' => true,
        'contact_attribution_enabled' => true,
    ]);
    $gates = SmsReleaseReadiness::report($settings, smsReadinessSchema(), false)['feature_gates'];

    foreach ($gates as $gate) expect($gate['configuration_schema_ready'])->toBeFalse();
    expect($gates['versioned_file_options']['zip_ready'])->toBeFalse()
        ->and($gates['source_tier_selection']['versioned_file_options_ready'])->toBeFalse()
        ->and($gates['contact_attribution']['source_system_configured'])->toBeFalse()
        ->and($gates['contact_attribution']['contract_hash_configured'])->toBeFalse();
});

test('matching flags and schema pass local gates without claiming external release acceptance', function (): void {
    $settings = smsReadinessSettings([
        'durable_availability_enabled' => true,
        'record_workflow_enabled' => true,
        'run_file_options_enabled' => true,
        'tier_selection_enabled' => true,
        'tu_completion_date_enabled' => true,
        'contact_attribution_enabled' => true,
        'contact_source_system' => 'lt',
        'contact_source_contract_sha256' => str_repeat('a', 64),
    ]);
    $report = SmsReleaseReadiness::report($settings, smsReadinessSchema([
        'durable_summary' => true,
        'record_workflow' => true,
        'file_options' => true,
        'tu_date_proof' => true,
        'contact_attribution' => true,
    ]), true);

    foreach ($report['feature_gates'] as $gate) expect($gate['configuration_schema_ready'])->toBeTrue();
    expect($report['feature_gates']['source_tier_selection']['production_plan_scale_acceptance'])->toBeFalse()
        ->and($report['feature_gates']['completed_tu_date']['source_completion_contract_accepted'])->toBeFalse()
        ->and($report['feature_gates']['contact_attribution']['source_matching_coverage_accepted'])->toBeFalse()
        ->and($report['external_release_acceptance'])->toContain(
            'private archive destination, timing and retention',
            'exact-once registration of the already-used 3M batch'
        );
});

test('controlled core test stays pending-only and isolated from optional integrations', function (): void {
    $settings = smsReadinessSettings([
        'durable_availability_enabled' => true,
        'record_workflow_enabled' => true,
        'run_file_options_enabled' => true,
        'tier_selection_enabled' => true,
    ]);
    $schema = smsReadinessSchema([
        'durable_summary' => true,
        'record_workflow' => true,
        'file_options' => true,
    ]);

    $core = SmsReleaseReadiness::report($settings, $schema, true)['controlled_core_test'];

    expect($core['environment'])->toBe('production')
        ->and($core['core_gate_names'])->toBe([
            'durable_summary',
            'record_workflow',
            'versioned_file_options',
            'source_tier_selection',
        ])
        ->and($core['core_configuration_schema_ready'])->toBeTrue()
        ->and($core['optional_integrations_disabled'])->toBe([
            'prospect_enrichment' => true,
            'completed_tu_date' => true,
            'contact_attribution' => true,
            'archive' => true,
            'file_publication' => true,
            'email_handoff' => true,
        ])
        ->and($core['external_integrations_disabled'])->toBeTrue()
        ->and($core['local_configuration_isolated'])->toBeTrue()
        ->and($core['first_safe_scope'])->toStartWith('pending-only:')
        ->and($core['production_evidence_verified'])->toBeFalse()
        ->and($core['real_campaign_permitted'])->toBeFalse()
        ->and($core['real_campaign_prerequisites'])->toBe([
            'replacement-data reconciliation accepted',
            'already-used 3M batch registered exactly once',
        ]);
});

test('controlled core test fails closed on a missing core prerequisite or enabled optional integration', function (): void {
    $settings = smsReadinessSettings([
        'durable_availability_enabled' => true,
        'record_workflow_enabled' => true,
        'run_file_options_enabled' => true,
        'tier_selection_enabled' => true,
    ]);
    $schema = smsReadinessSchema([
        'durable_summary' => true,
        'record_workflow' => true,
        'file_options' => true,
    ]);

    $missingZip = SmsReleaseReadiness::report($settings, $schema, false)['controlled_core_test'];
    expect($missingZip['core_configuration_schema_ready'])->toBeFalse()
        ->and($missingZip['local_configuration_isolated'])->toBeFalse();

    $enabledEmail = SmsReleaseReadiness::report(
        ['email_handoff_enabled' => true] + $settings,
        $schema,
        true
    )['controlled_core_test'];
    expect($enabledEmail['optional_integrations_disabled']['email_handoff'])->toBeFalse()
        ->and($enabledEmail['external_integrations_disabled'])->toBeFalse()
        ->and($enabledEmail['local_configuration_isolated'])->toBeFalse();

    $obsoleteClock = SmsReleaseReadiness::report(
        ['mailer_recency_date' => 'PK'] + $settings,
        $schema,
        true
    )['controlled_core_test'];
    expect($obsoleteClock['core_configuration_schema_ready'])->toBeFalse();

    $snapshotOff = SmsReleaseReadiness::report(
        ['snapshot_isolation_enabled' => false] + $settings,
        $schema,
        true
    );
    expect($snapshotOff['feature_gates']['source_tier_selection']['snapshot_isolation_ready'])->toBeFalse()
        ->and($snapshotOff['feature_gates']['source_tier_selection']['configuration_schema_ready'])->toBeFalse()
        ->and($snapshotOff['controlled_core_test']['core_configuration_schema_ready'])->toBeFalse()
        ->and($snapshotOff['controlled_core_test']['local_configuration_isolated'])->toBeFalse();
});

test('obsolete mailer clocks and malformed contact hashes never pass readiness', function (): void {
    $report = SmsReleaseReadiness::report(smsReadinessSettings([
        'mailer_recency_date' => 'PK',
        'contact_attribution_enabled' => true,
        'contact_source_system' => 'lt',
        'contact_source_contract_sha256' => str_repeat('A', 64),
    ]), smsReadinessSchema(['contact_attribution' => true]), true);

    expect($report['delivery_policy']['mailer_clock'])->toBe('UNCONFIRMED')
        ->and($report['feature_gates']['contact_attribution']['contract_hash_configured'])->toBeFalse()
        ->and($report['feature_gates']['contact_attribution']['configuration_schema_ready'])->toBeFalse();
});

test('email readiness reports local prerequisites without claiming recipient link or import acceptance', function (): void {
    $settings = ['email_handoff_enabled' => true, 'email_report_name' => 'SMS Export',
        'email_report_company' => 'FIXTURE', 'email_report_source' => 'plaw',
        'email_allowed_link_hosts' => ['files.example.com'], 'email_sender_configured' => true];
    $email = SmsReleaseReadiness::report($settings, ['email_outbox' => true], true)['email_handoff'];
    expect($email['local_prerequisites_present'])->toBeTrue()
        ->and($email['actual_recipient_rows_verified'])->toBeFalse()
        ->and($email['external_acceptance_verified'])->toBeFalse()
        ->and($email['automatic_link_publisher'])->toBe('explicit_opt_in_command')
        ->and($email['file_publication_enabled'])->toBeFalse()
        ->and($email['file_publication_schema_ready'])->toBeFalse()
        ->and($email['file_publication_destination_policy_accepted'])->toBeFalse()
        ->and($email['debtplete_import'])->toBe('not_connected');
    foreach ([['email_handoff_enabled' => 'true'], ['email_report_company' => null], ['email_report_source' => 'unknown'],
        ['email_allowed_link_hosts' => []], ['email_allowed_link_hosts' => ['https://files.example.com']],
        ['email_allowed_link_hosts' => ['127.0.0.1']], ['email_sender_configured' => false]] as $change) {
        expect(SmsReleaseReadiness::report($change + $settings, ['email_outbox' => true], true)['email_handoff']['local_prerequisites_present'])->toBeFalse();
    }
    expect(SmsReleaseReadiness::report($settings, [], true)['email_handoff']['local_prerequisites_present'])->toBeFalse();
});
