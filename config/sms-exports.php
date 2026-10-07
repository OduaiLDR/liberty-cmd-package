<?php

$dedicatedBucket = env('CMD_SMS_EXPORT_BUCKET');

return [
    // CMD runner already configures an S3 disk for exports. Reuse it
    // unless operations deliberately chooses a dedicated SMS bucket.
    'bucket' => $dedicatedBucket ?: config('filesystems.disks.s3.bucket'),
    'prefix' => env('CMD_SMS_EXPORT_PREFIX', 'sms-exports'),
    // A dedicated bucket needs an explicit region; do not silently use EE's.
    'region' => $dedicatedBucket
        ? env('CMD_SMS_EXPORT_REGION')
        : (config('filesystems.disks.s3.region') ?: env('AWS_DEFAULT_REGION', 'us-east-2')),
];
