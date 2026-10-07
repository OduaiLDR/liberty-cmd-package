<?php

return [
    'bucket' => env('CMD_SMS_EXPORT_BUCKET'),
    'prefix' => env('CMD_SMS_EXPORT_PREFIX', 'sms-exports'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-2'),
];
