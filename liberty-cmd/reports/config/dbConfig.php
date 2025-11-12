<?php

return [
    /*
     * Default environment and mode
     */
    'default' => env('SNOWFLAKE_ENV', 'plaw'),
    'mode' => env('SNOWFLAKE_MODE', 'production'), // 'production' or 'sandbox'

    /*
     * Production Environment Configurations
     */
    'production' => [
        /*
         * PLAW Production Environment
         * Production-like AWS environment
         */
        'plaw' => [
            'account' => 'ACCOUNT_IDENTIFIER',
            'user' => 'USERNAME',
            'database' => 'DATABASE_NAME',
            'schema' => 'SCHEMA_NAME',
            'warehouse' => 'WAREHOUSE_NAME',
            'role' => 'ROLE_NAME',
            'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----',
            'private_key_passphrase' => 'PRIVATE_KEY_PASSPHRASE',
        ],

        /*
         * LDR Environment Configuration
         * Leader environment
         */
        'ldr' => [
            'account' => 'ACCOUNT_IDENTIFIER',
            'user' => 'USERNAME',
            'database' => 'DATABASE_NAME',
            'schema' => 'SCHEMA_NAME',
            'warehouse' => 'WAREHOUSE_NAME',
            'role' => 'ROLE_NAME',
            'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----',
            'private_key_passphrase' => 'PRIVATE_KEY_PASSPHRASE',
        ],

        /*
         * LT Environment Configuration
         * Local testing environment
         */
        'lt' => [
            'account' => 'ACCOUNT_IDENTIFIER',
            'user' => 'USERNAME',
            'database' => 'DATABASE_NAME',
            'schema' => 'SCHEMA_NAME',
            'warehouse' => 'WAREHOUSE_NAME',
            'role' => 'ROLE_NAME',
            'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----',
            'private_key_passphrase' => 'PRIVATE_KEY_PASSPHRASE',
        ],
    ],

    /*
     * Sandbox Environment Configurations
     */
    'sandbox' => [
        /*
         * PLAW Sandbox Environment
         * Production-like testing environment
         */
        'plaw' => [
            'account' => 'ACCOUNT_IDENTIFIER',
            'user' => 'USERNAME',
            'database' => 'DATABASE_NAME',
            'schema' => 'SCHEMA_NAME',
            'warehouse' => 'WAREHOUSE_NAME',
            'role' => 'ROLE_NAME',
            'password' => 'PASSWORD',
            'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----',
            'private_key_passphrase' => 'PRIVATE_KEY_PASSPHRASE',
        ],

        /*
         * LDR Sandbox Environment
         * Development testing environment
         */
        'ldr' => [
            'account' => 'ACCOUNT_IDENTIFIER',
            'user' => 'USERNAME',
            'database' => 'DATABASE_NAME',
            'schema' => 'SCHEMA_NAME',
            'warehouse' => 'WAREHOUSE_NAME',
            'role' => 'ROLE_NAME',
            'password' => 'PASSWORD',
            'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----',
            'private_key_passphrase' => 'PRIVATE_KEY_PASSPHRASE',
        ],

        /*
         * LT Sandbox Environment
         * Local testing environment
         */
        'lt' => [
            'account' => 'ACCOUNT_IDENTIFIER',
            'user' => 'USERNAME',
            'database' => 'DATABASE_NAME',
            'schema' => 'SCHEMA_NAME',
            'warehouse' => 'WAREHOUSE_NAME',
            'role' => 'ROLE_NAME',
            'password' => 'PASSWORD',
            'private_key' => '-----BEGIN PRIVATE KEY-----
YOUR_PRIVATE_KEY
-----END PRIVATE KEY-----',
            'private_key_passphrase' => 'PRIVATE_KEY_PASSPHRASE',
        ],
    ],

    /*
     * SQL Server CMD Database Configuration
     * Used for data synchronization with Snowflake
     */
    'sql_server' => [

        /*
         * Sandbox SQL Server Configuration
         * Same server but different database/table for testing
         */
        'sandbox' => [
            'dsn' => 'DSN_PLACEHOLDER',
            'username' => 'USERNAME',
            'password' => 'PASSWORD',
            'database' => 'DATABASE_NAME',
            'table' => 'TABLE_NAME',
            'timeout' => 30,
        ],
    ],
];
