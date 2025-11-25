<?php

return [
    // Default Snowflake connection and mode
    'default' => env('SNOWFLAKE_ENV', 'plaw'),
    'mode' => env('SNOWFLAKE_MODE', 'production'), // 'production' or 'sandbox'

    // Production environments
    'production' => [
        // PLAW Production-like AWS environment
        'plaw' => [
            'account' => '',
            'user' => '',
            'database' => '',
            'schema' => 'READER',
            'warehouse' => '',
            'role' => '',
            'private_key' => <<<'KEY'
KEY, // add the private key between the keys handler
            'private_key_passphrase' => '',
        ],

        // LDR Leader environment
        'ldr' => [
            'account' => '',
            'user' => '',
            'database' => '',
            'schema' => '',
            'warehouse' => '',
            'role' => '',
            'private_key' => <<<'KEY'
KEY, // add the private key between the keys handler
            'private_key_passphrase' => '',
        ],

        // LT Local testing environment
        'lt' => [
            'account' => '',
            'user' => '',
            'database' => '',
            'schema' => '',
            'warehouse' => '',
            'role' => '',
            'private_key' => <<<'KEY'
KEY, // add the private key between the keys handler
            'private_key_passphrase' => '',
        ],
    ],

    // SQL Server connection (use .env / host app config; no hard-coded creds)
    'sql_server' => [
        'sql_server_connection' => [
            'dsn' => env('CMD_REPORTS_SQL_DSN'),
            'host' => env('CMD_DB_HOST'),
            'port' => env('CMD_DB_PORT', 1433),
            'database' => env('CMD_DB_DATABASE'),
            'username' => env('CMD_DB_USERNAME'),
            'password' => env('CMD_DB_PASSWORD'),
            'encrypt' => env('CMD_DB_ENCRYPT'),
            'trust_server_certificate' => env('DB_CMD_TRUST_CERT', false),
            'timeout' => (int) env('CMD_REPORTS_SQL_TIMEOUT', 30),
        ],
    ],
];
