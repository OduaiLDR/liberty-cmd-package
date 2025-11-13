<?php

return [
    /*
     * Default connections to use
     */
    'default' => ['plaw', 'ldr', 'lt'],

    /*
     * Snowflake Database Connections
     * Add any Snowflake connection credentials here
     */
    'snowflake' => [
        /*
         * PLAW Connection
         * Production-like AWS environment
         */
        'plaw' => [
            'account' => '',
            'user' => '',
            'database' => '',
            'schema' => '',
            'warehouse' => '',
            'role' => '',
            'private_key' => '',
            'private_key_passphrase' => '',
        ],

        /*
         * LDR Connection
         * Leader environment
         */
        'ldr' => [
            'account' => '',
            'user' => '',
            'database' => '',
            'schema' => '',
            'warehouse' => '',
            'role' => '',
            'private_key' => '',
            'private_key_passphrase' => '',
        ],

        /*
         * LT Connection
         * Local testing environment
         */
        'lt' => [
            'account' => '',
            'user' => '',
            'database' => '',
            'schema' => '',
            'warehouse' => '',
            'role' => '',
            'private_key' => "",
            'private_key_passphrase' => '',
        ],
    ],

    /*
     * SQL Server Database Connections
     */
    'sql_server' => [
        /*
         * Sandbox SQL Server Connection
         */
        'sql_server_connection' => [
            'dsn' => '',
            'username' => '',
            'password' => '',
            'database' => '',
            'timeout' => 30,
        ],
    ],
];