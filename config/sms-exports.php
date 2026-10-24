<?php

$dedicatedBucket = env('CMD_SMS_EXPORT_BUCKET');
$privateRoot = config('filesystems.disks.local.root');
$dropboxLinkExpiryDays = env('CMD_SMS_DROPBOX_LINK_EXPIRY_DAYS');
if (is_string($dropboxLinkExpiryDays) && preg_match('/^[1-9][0-9]?$/D', $dropboxLinkExpiryDays) === 1) {
    $dropboxLinkExpiryDays = (int) $dropboxLinkExpiryDays;
}

return [
    // Keep SMS files on CMD runner's existing private persistent storage.
    'driver' => env('CMD_SMS_EXPORT_DRIVER', 'local'),
    'local_root' => $privateRoot ? rtrim((string) $privateRoot, '/\\').'/sms-exports' : null,
    // Default to one CSV; enable only when the recipient needs capped parts.
    'split_csv' => env('CMD_SMS_EXPORT_SPLIT_CSV', false),
    // Enable the five-column contract only after the recipient confirms acceptance.
    'single_phone_csv' => env('CMD_SMS_SINGLE_PHONE_CSV', false),
    // Jacob confirmed TU first, then mailers ordered by TblMarketing.Send_Date.
    // Obsolete overrides are rejected by the resolver, never silently substituted.
    'mailer_recency_date' => env('CMD_SMS_MAILER_RECENCY_DATE', 'Send_Date'),
    // Enable only after BOTH the package's enrichment ledger and CMD intake-job migration
    // have been applied. This routes the existing daily TU command into the new workflow.
    'prospect_enrichment_enabled' => env('CMD_SMS_PROSPECT_ENRICHMENT_ENABLED', false),
    'queue' => env('CMD_SMS_QUEUE', 'tu-export'),
    // Opt in only after the additive summary migration, all eligibility writers and
    // the refresh scheduler have been deployed together. No live rollout is implicit.
    'durable_availability_enabled' => env('CMD_SMS_DURABLE_AVAILABILITY_ENABLED', false),
    // Coordinated package/runner rollout only, after TU data-date provenance and
    // completion contracts are approved. Never derive this date from upload/count time.
    'tu_completion_date_enabled' => env('CMD_SMS_TU_COMPLETION_DATE_ENABLED', false),
    // Internal attribution prerequisite only; no source reader/scheduler is installed.
    // Verify original-event semantics/coverage and apply its evidence schema first.
    'contact_attribution_enabled' => env('CMD_SMS_CONTACT_ATTRIBUTION_ENABLED', false),
    'contact_source_system' => env('CMD_SMS_CONTACT_SOURCE_SYSTEM'),
    'contact_source_contract_sha256' => env('CMD_SMS_CONTACT_SOURCE_CONTRACT_SHA256'),
    // Coordinated rollout only; schema and database-stored summaries are prerequisites.
    'record_workflow_enabled' => env('CMD_SMS_RECORD_WORKFLOW_ENABLED', false),
    // Internal historical registration only, after its explicit ledger/readers/UI rollout.
    // This never enables campaign sending, external publication or provider email.
    'external_event_writer_enabled' => env('CMD_SMS_EXTERNAL_EVENT_WRITER_ENABLED', false),
    // New-format creation only, after the explicit file-options migration and coordinated release.
    // Existing versioned runs retain their stored format when this creation switch is off.
    'run_file_options_enabled' => env('CMD_SMS_RUN_FILE_OPTIONS_ENABLED', false),
    // Creation gate only. Source-first tier selection needs production schema/plan/scale acceptance.
    // Existing marked runs keep their frozen selector even when this switch is off.
    'tier_selection_enabled' => env('CMD_SMS_TIER_SELECTION_ENABLED', false),
    // New-run policy only. Keep false until the reviewed seek indexes and bounded
    // performance acceptance are complete; frozen v1 runs remain on v1.
    'tier_selection_windowed_enabled' => env('CMD_SMS_TIER_SELECTION_WINDOWED_ENABLED', false),
    'tier_selection_window_size' => env('CMD_SMS_TIER_SELECTION_WINDOW_SIZE', 5000),
    // Explicit operator archive/verify/restore only. No inherited legacy bucket,
    // chosen timing or deletion policy. Approve the complete contract before enabling.
    'run_archive_enabled' => env('CMD_SMS_RUN_ARCHIVE_ENABLED', false),
    'run_archive_policy_sha256' => env('CMD_SMS_RUN_ARCHIVE_POLICY_SHA256'),
    'run_archive_bucket' => env('CMD_SMS_RUN_ARCHIVE_BUCKET'),
    'run_archive_prefix' => env('CMD_SMS_RUN_ARCHIVE_PREFIX'),
    'run_archive_region' => env('CMD_SMS_RUN_ARCHIVE_REGION'),
    'run_archive_expected_owner' => env('CMD_SMS_RUN_ARCHIVE_EXPECTED_OWNER'),
    'run_archive_encryption' => env('CMD_SMS_RUN_ARCHIVE_ENCRYPTION'),
    'run_archive_kms_key_arn' => env('CMD_SMS_RUN_ARCHIVE_KMS_KEY_ARN'),
    // Email uses the existing dynamic TblReports recipients. Explicit activation,
    // company scope and approved recipient-accessible links are required first.
    'email_handoff_enabled' => env('CMD_SMS_EMAIL_HANDOFF_ENABLED', false),
    'email_report_source' => env('CMD_SMS_EMAIL_REPORT_SOURCE', 'plaw'),
    'email_report_name' => env('CMD_SMS_EMAIL_REPORT_NAME', 'SMS Export'),
    'email_report_company' => env('CMD_SMS_EMAIL_REPORT_COMPANY'),
    'email_allowed_link_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CMD_SMS_EMAIL_ALLOWED_LINK_HOSTS', ''))))),
    // Separate from private archives. No recipient data is published until the
    // approved account, namespace, existing folder and sharing policy are explicit.
    'file_publication_enabled' => env('CMD_SMS_FILE_PUBLICATION_ENABLED', false),
    'dropbox_account_id' => env('CMD_SMS_DROPBOX_ACCOUNT_ID'),
    'dropbox_namespace_id' => env('CMD_SMS_DROPBOX_NAMESPACE_ID'),
    'dropbox_folder_id' => env('CMD_SMS_DROPBOX_FOLDER_ID'),
    'dropbox_folder_path' => env('CMD_SMS_DROPBOX_FOLDER_PATH'),
    'dropbox_policy_sha256' => env('CMD_SMS_DROPBOX_POLICY_SHA256'),
    'dropbox_link_audience' => env('CMD_SMS_DROPBOX_LINK_AUDIENCE'),
    'dropbox_link_expiry_days' => $dropboxLinkExpiryDays,
    // Runtime credentials only. Never include these in publication identities,
    // receipts, queue payloads, command output or browser responses.
    'dropbox_access_token' => env('CMD_SMS_DROPBOX_ACCESS_TOKEN'),
    'dropbox_refresh_token' => env('CMD_SMS_DROPBOX_REFRESH_TOKEN'),
    'dropbox_app_key' => env('CMD_SMS_DROPBOX_APP_KEY'),
    'dropbox_app_secret' => env('CMD_SMS_DROPBOX_APP_SECRET'),
    'demo' => env('CMD_SMS_DEMO', false),
    // CMD runner already configures an S3 disk for exports. Reuse it
    // unless operations deliberately chooses a dedicated SMS bucket.
    'bucket' => $dedicatedBucket ?: config('filesystems.disks.s3.bucket'),
    'prefix' => env('CMD_SMS_EXPORT_PREFIX', 'sms-exports'),
    // A dedicated bucket needs an explicit region; do not silently use EE's.
    'region' => $dedicatedBucket
        ? env('CMD_SMS_EXPORT_REGION')
        : (config('filesystems.disks.s3.region') ?: env('AWS_DEFAULT_REGION', 'us-east-2')),
];
