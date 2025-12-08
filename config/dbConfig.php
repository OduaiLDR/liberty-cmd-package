<?php

return [
    // Default Snowflake connection and mode
    'default' => env('SNOWFLAKE_ENV', 'plaw'),
    'mode' => env('SNOWFLAKE_MODE', 'production'), // 'production' or 'sandbox'

    'production' => [
        'plaw' => [
            'account' => env('SNOWFLAKE_PLAW_ACCOUNT'),
            'user' => env('SNOWFLAKE_PLAW_USER'),
            'database' => env('SNOWFLAKE_PLAW_DATABASE', 'DPP_DATA'),
            'schema' => env('SNOWFLAKE_PLAW_SCHEMA', 'READER'),
            'warehouse' => env('SNOWFLAKE_PLAW_WAREHOUSE', 'DEFAULT_WH'),
            'role' => env('SNOWFLAKE_PLAW_ROLE', 'ACCOUNTADMIN'),
            'private_key' => env('SNOWFLAKE_PLAW_PRIVATE_KEY'),
            'private_key_passphrase' => env('SNOWFLAKE_PLAW_PRIVATE_KEY_PASSPHRASE', ''),
        ],

        'ldr' => [
            'account' => env('SNOWFLAKE_LDR_ACCOUNT'),
            'user' => env('SNOWFLAKE_LDR_USER'),
            'database' => env('SNOWFLAKE_LDR_DATABASE', 'DPP_DATA'),
            'schema' => env('SNOWFLAKE_LDR_SCHEMA', 'READER'),
            'warehouse' => env('SNOWFLAKE_LDR_WAREHOUSE', 'DEFAULT_WH'),
            'role' => env('SNOWFLAKE_LDR_ROLE', 'ACCOUNTADMIN'),
            'private_key' => env('SNOWFLAKE_LDR_PRIVATE_KEY'),
            'private_key_passphrase' => env('SNOWFLAKE_LDR_PRIVATE_KEY_PASSPHRASE', ''),
        ],

        'lt' => [
            'account' => env('SNOWFLAKE_LT_ACCOUNT'),
            'user' => env('SNOWFLAKE_LT_USER'),
            'database' => env('SNOWFLAKE_LT_DATABASE', 'DPP_DATA'),
            'schema' => env('SNOWFLAKE_LT_SCHEMA', 'READER'),
            'warehouse' => env('SNOWFLAKE_LT_WAREHOUSE', 'DEFAULT_WH'),
            'role' => env('SNOWFLAKE_LT_ROLE', 'ACCOUNTADMIN'),
            'private_key' => env('SNOWFLAKE_LT_PRIVATE_KEY'),
            'private_key_passphrase' => env('SNOWFLAKE_LT_PRIVATE_KEY_PASSPHRASE', ''),
        ],
    ],

    'sql_server' => [
        'sql_server_connection' => [
            'dsn' => env('SQLSRV_DSN') ?: sprintf(
                'sqlsrv:Server=%s,%s;Database=%s;TrustServerCertificate=%s',
                env('CMD_DB_HOST', 'localhost'),
                env('CMD_DB_PORT', '1433'),
                env('CMD_DB_DATABASE', 'CMD_DB'),
                filter_var(env('DB_CMD_TRUST_CERT', false), FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'
            ),
            'username' => env('SQLSRV_USERNAME', env('CMD_DB_USERNAME', '')),
            'password' => env('SQLSRV_PASSWORD', env('CMD_DB_PASSWORD', '')),
            'timeout' => (int) env('SQLSRV_TIMEOUT', 30),
        ],
    ],

];
