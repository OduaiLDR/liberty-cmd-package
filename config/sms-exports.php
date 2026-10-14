<?php

$dedicatedBucket = env('CMD_SMS_EXPORT_BUCKET');
$privateRoot = config('filesystems.disks.local.root');

return [
    // Keep SMS files on CMD runner's existing private persistent storage.
    'driver' => env('CMD_SMS_EXPORT_DRIVER', 'local'),
    'local_root' => $privateRoot ? rtrim((string) $privateRoot, '/\\').'/sms-exports' : null,
    // Default to one CSV; enable only when the recipient needs capped parts.
    'split_csv' => env('CMD_SMS_EXPORT_SPLIT_CSV', false),
    // CMD runner already configures an S3 disk for exports. Reuse it
    // unless operations deliberately chooses a dedicated SMS bucket.
    'bucket' => $dedicatedBucket ?: config('filesystems.disks.s3.bucket'),
    'prefix' => env('CMD_SMS_EXPORT_PREFIX', 'sms-exports'),
    // A dedicated bucket needs an explicit region; do not silently use EE's.
    'region' => $dedicatedBucket
        ? env('CMD_SMS_EXPORT_REGION')
        : (config('filesystems.disks.s3.region') ?: env('AWS_DEFAULT_REGION', 'us-east-2')),
    // Debt used when neither TblTUData nor TblMailers has one, by Debt_Tier (e.g. 'T1' => 10000).
    // Values come from Jacob; until then those records go out with an empty debt load.
    'tier_debts' => [],
];
