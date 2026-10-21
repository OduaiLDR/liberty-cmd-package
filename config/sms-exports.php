<?php

$dedicatedBucket = env('CMD_SMS_EXPORT_BUCKET');
$privateRoot = config('filesystems.disks.local.root');

return [
    // Keep SMS files on CMD runner's existing private persistent storage.
    'driver' => env('CMD_SMS_EXPORT_DRIVER', 'local'),
    'local_root' => $privateRoot ? rtrim((string) $privateRoot, '/\\').'/sms-exports' : null,
    // Default to one CSV; enable only when the recipient needs capped parts.
    'split_csv' => env('CMD_SMS_EXPORT_SPLIT_CSV', false),
    // Enable only after BOTH the package's enrichment ledger and CMD intake-job migration
    // have been applied. This routes the existing daily TU command into the new workflow.
    'prospect_enrichment_enabled' => env('CMD_SMS_PROSPECT_ENRICHMENT_ENABLED', false),
    'queue' => env('CMD_SMS_QUEUE', 'tu-export'),
    // Opt in only after the additive summary migration, all eligibility writers and
    // the refresh scheduler have been deployed together. No live rollout is implicit.
    'durable_availability_enabled' => env('CMD_SMS_DURABLE_AVAILABILITY_ENABLED', false),
    // Coordinated rollout only; schema and database-stored summaries are prerequisites.
    'record_workflow_enabled' => env('CMD_SMS_RECORD_WORKFLOW_ENABLED', false),
    'demo' => env('CMD_SMS_DEMO', false),
    // CMD runner already configures an S3 disk for exports. Reuse it
    // unless operations deliberately chooses a dedicated SMS bucket.
    'bucket' => $dedicatedBucket ?: config('filesystems.disks.s3.bucket'),
    'prefix' => env('CMD_SMS_EXPORT_PREFIX', 'sms-exports'),
    // A dedicated bucket needs an explicit region; do not silently use EE's.
    'region' => $dedicatedBucket
        ? env('CMD_SMS_EXPORT_REGION')
        : (config('filesystems.disks.s3.region') ?: env('AWS_DEFAULT_REGION', 'us-east-2')),
    // Debt used when neither TblTUData nor TblMailers has one, by tier (Jacob, 14 Oct 2026).
    'tier_debts' => [
        'T1' => 10000, 'T2' => 13500, 'T3' => 17000, 'T4' => 22500, 'T5' => 30500,
        'T6' => 42500, 'T7' => 57500, 'T8' => 72500, 'T9' => 85000,
    ],
];
