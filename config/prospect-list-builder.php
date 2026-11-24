<?php

return [
    'token' => env('PROSPECT_LIST_BUILDER_TOKEN'),
    'disk' => env('PROSPECT_LIST_BUILDER_DISK', 's3'),
    'dob_column' => env('PROSPECT_LIST_BUILDER_TU_DOB_COLUMN', 'dob'),
    'stamp_exports' => (bool) env('PROSPECT_LIST_BUILDER_STAMP_EXPORTS', false),
    'allow_exclusion_overrides' => (bool) env('PROSPECT_LIST_BUILDER_ALLOW_EXCLUSION_OVERRIDES', false),
    'tcpa_scrub' => [
        'enabled' => (bool) env('PROSPECT_LIST_BUILDER_TCPA_SCRUB_ENABLED', false),
        'username' => env('TCPA_LITIGATOR_API_USERNAME'),
        'password' => env('TCPA_LITIGATOR_API_PASSWORD'),
    ],
];
