<?php

return [
    'token' => env('PROSPECT_LIST_BUILDER_TOKEN'),
    'allowed_tenant_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('PROSPECT_LIST_BUILDER_ALLOWED_TENANT_IDS', 'lendingtower'))),
        static fn (string $tenantId): bool => $tenantId !== '',
    )),
    'disk' => env('PROSPECT_LIST_BUILDER_DISK', 's3'),
    'mailer_offer_origins' => array_map('trim', explode(',', (string) env('PROSPECT_LIST_BUILDER_MAILER_OFFER_ORIGINS', ''))),
    'dob_column' => env('PROSPECT_LIST_BUILDER_TU_DOB_COLUMN', 'dob'),
    'stamp_exports' => (bool) env('PROSPECT_LIST_BUILDER_STAMP_EXPORTS', false),
    'allow_exclusion_overrides' => (bool) env('PROSPECT_LIST_BUILDER_ALLOW_EXCLUSION_OVERRIDES', false),
    'tcpa_scrub' => [
        'enabled' => (bool) env('PROSPECT_LIST_BUILDER_TCPA_SCRUB_ENABLED', false),
        'username' => env('TCPA_LITIGATOR_API_USERNAME'),
        'password' => env('TCPA_LITIGATOR_API_PASSWORD'),
    ],
];
