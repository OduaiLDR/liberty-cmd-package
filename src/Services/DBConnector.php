<?php

namespace Cmd\Reports\Services;

use GuzzleHttp\Client;
use Firebase\JWT\JWT;
use Exception;
use PDO;

/**
 * Database Connector
 * 
 * Provides unified access to both Snowflake and SQL Server databases
 * - Snowflake: JWT-based authentication and query execution
 * - SQL Server: PDO-based connection and query execution
 */
class DBConnector
{
    private string $account;
    private string $user;
    private string $database;
    private string $schema;
    private string $warehouse;
    private string $role;
    private string $privateKey;
    private string $privateKeyPassphrase;
    private ?string $accessToken = null;
    private ?int $tokenExpiry = null;
    private Client $client;
    
    // SQL Server connection properties
    private ?PDO $sqlServerConnection = null;
    private ?array $sqlServerConfig = null;

    public function __construct(array $config)
    {
        // Validate required Snowflake configuration
        $this->validateSnowflakeConfig($config);
        
        $this->account = $config['account'];
        $this->user = strtoupper($config['user']);
        $this->database = $config['database'] ?? 'DPP_DATA';
        $this->schema = $config['schema'] ?? 'READER';
        $this->warehouse = $config['warehouse'] ?? 'DEFAULT_WH';
        $this->role = $config['role'] ?? 'ACCOUNTADMIN';
        $this->privateKey = $config['private_key'];
        $this->privateKeyPassphrase = $config['private_key_passphrase'] ?? '';

        $this->client = new Client([
            'timeout' => 300,
            'verify' => true,
        ]);
    }

    /**
     * Validate Snowflake configuration
     */
    private function validateSnowflakeConfig(array $config): void
    {
        $required = ['account', 'user', 'private_key'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($config[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new Exception(
                'Missing required Snowflake configuration: ' . implode(', ', $missing) . '. ' .
                'Please set the appropriate environment variables or update the configuration file.'
            );
        }
    }

    /**
     * Create connector from environment configuration
     */
    public static function fromEnvironment(string $env, string $mode = null): self
    {
        $config = self::loadConfiguration();
        
        // Auto-detect mode based on credentials if not specified
        if (!$mode) {
            // Check if we have snowflake config
            if (isset($config['snowflake'][$env])) {
                $envConfig = $config['snowflake'][$env];
                // Auto-detect: if database name contains SANDBOX, use sandbox mode
                if (isset($envConfig['database']) && stripos($envConfig['database'], 'SANDBOX') !== false) {
                    $mode = 'sandbox';
                } else {
                    $mode = 'production';
                }
            } else {
                $mode = 'production'; // Default
            }
        }
        
        // Check if we have the new configuration structure with mode support
        if (isset($config['snowflake']) && isset($config[$mode])) {
            // New structure with both production and sandbox: config[mode][env]
            if (!isset($config[$mode][$env])) {
                throw new Exception("Unknown environment: {$env} in mode: {$mode}");
            }
            return new self($config[$mode][$env]);
        }
        
        // Check if we have only production snowflake configuration
        if (isset($config['snowflake'])) {
            // New structure: config['snowflake'][env] (production only)
            if (!isset($config['snowflake'][$env])) {
                throw new Exception("Unknown environment: {$env} in snowflake configuration");
            }
            return new self($config['snowflake'][$env]);
        }
        
        // Legacy structure: config[mode][env]
        if (!isset($config[$mode][$env])) {
            throw new Exception("Unknown environment: {$env} in mode: {$mode}");
        }

        return new self($config[$mode][$env]);
    }

    /**
     * Locate configuration from Laravel config or package stub
     */
    private static function loadConfiguration(): array
    {
        if (function_exists('config')) {
            // Try new dbConfig first
            $dbConfig = config('dbConfig');
            if (is_array($dbConfig) && !empty($dbConfig)) {
                return $dbConfig;
            }

            // Legacy database config support
            $databaseConfig = config('database');
            if (is_array($databaseConfig) && !empty($databaseConfig)) {
                return $databaseConfig;
            }

            // Legacy snowflake config support
            $snowflakeConfig = config('snowflake');
            if (is_array($snowflakeConfig) && !empty($snowflakeConfig)) {
                return $snowflakeConfig;
            }

            // Legacy reports config support
            $legacyConfig = config('reports');
            if (is_array($legacyConfig) && !empty($legacyConfig)) {
                return $legacyConfig;
            }
        }

        if (function_exists('base_path')) {
            // Try new dbConfig file
            $publishedConfig = base_path('config/dbConfig.php');
            if (is_file($publishedConfig)) {
                return require $publishedConfig;
            }

            // Legacy database config file
            $legacyDatabaseConfig = base_path('config/database.php');
            if (is_file($legacyDatabaseConfig)) {
                return require $legacyDatabaseConfig;
            }

            // Legacy snowflake config file
            $legacySnowflakeConfig = base_path('config/snowflake.php');
            if (is_file($legacySnowflakeConfig)) {
                return require $legacySnowflakeConfig;
            }
        }

        // Fallback to package stub next to this src/ tree
        $packageConfig = __DIR__ . '/../../config/dbConfig.php';
        if (is_file($packageConfig)) {
            return require $packageConfig;
        }

        // Legacy package configs
        $legacyDatabasePackageConfig = __DIR__ . '/../../config/database.php';
        if (is_file($legacyDatabasePackageConfig)) {
            return require $legacyDatabasePackageConfig;
        }

        $legacySnowflakePackageConfig = __DIR__ . '/../../config/snowflake.php';
        if (is_file($legacySnowflakePackageConfig)) {
            return require $legacySnowflakePackageConfig;
        }

        throw new Exception('Database configuration file could not be located. '
            . 'Publish the config via php artisan vendor:publish --tag=reports-config or ensure config/dbConfig.php exists.');
    }

    /**
     * Get or refresh access token
     */
    private function getAccessToken(): string
    {
        // Return cached token if still valid
        if ($this->accessToken && $this->tokenExpiry && time() < $this->tokenExpiry) {
            return $this->accessToken;
        }

        // Generate new token
        $jwt = $this->generateJWT();
        $this->accessToken = $this->exchangeJWTForToken($jwt);
        $this->tokenExpiry = time() + 3300; // Token valid for 55 minutes (shorter than JWT exp)

        return $this->accessToken;
    }

    /**
     * Generate JWT token for authentication
     */
    private function generateJWT(): string
    {
        $now = time();
        
        // For unencrypted keys, passphrase is not needed
        $privateKey = openssl_pkey_get_private($this->privateKey, $this->privateKeyPassphrase ?: null);
        if (!$privateKey) {
            $error = openssl_error_string();
            $this->debugLog("Private key loading failed: {$error}");
            $this->debugLog("Key starts with: " . substr($this->privateKey, 0, 50));
            throw new Exception('Failed to load private key: ' . $error);
        }
        
        // Validate key type
        $keyDetails = openssl_pkey_get_details($privateKey);
        if ($keyDetails['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new Exception('Private key must be RSA type, got: ' . $keyDetails['type']);
        }
        
        $this->debugLog("Private key loaded successfully. Key size: " . $keyDetails['bits'] . " bits");

        // Get public key details for fingerprint
        $publicKeyDetails = openssl_pkey_get_details($privateKey);
        if (!$publicKeyDetails) {
            throw new Exception('Failed to get public key details: ' . openssl_error_string());
        }

        // Calculate SHA256 fingerprint of public key
        $publicKeyPem = $publicKeyDetails['key'];
        $publicKeyDer = $this->pemToDer($publicKeyPem);
        $sha256Fingerprint = hash('sha256', $publicKeyDer, true);
        $fingerprintB64 = base64_encode($sha256Fingerprint);
        
        // JWT Header - Simple format as per Snowflake docs
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT'
        ];

        // JWT Payload - Snowflake official format
        $qualifiedUser = $this->getQualifiedUsername();
        $audienceUrl = 'https://' . strtolower($this->account) . '.snowflakecomputing.com';
        
        // iss format: account_identifier.user.SHA256:public_key_fingerprint
        $issuer = $qualifiedUser . '.SHA256:' . $fingerprintB64;
        
        $payload = [
            'iss' => $issuer,
            'sub' => $qualifiedUser,
            'aud' => $audienceUrl,
            'iat' => $now,
            'exp' => $now + 3600, // 1 hour expiration
        ];

        $jwt = JWT::encode($payload, $privateKey, 'RS256', null, $header);
        
        // Debug output
        $this->debugLog("Generated JWT for {$this->account}: " . substr($jwt, 0, 100) . "...");
        $this->debugLog("Issuer (iss): {$payload['iss']}");
        $this->debugLog("Subject (sub): {$qualifiedUser}");
        $this->debugLog("Audience (aud): {$audienceUrl}");
        $this->debugLog("Public Key Fingerprint: {$fingerprintB64}");
        $this->debugLog("JWT Header: " . json_encode($header));
        $this->debugLog("JWT Payload: " . json_encode($payload));
        
        return $jwt;
    }

    /**
     * Exchange JWT for OAuth access token
     */
    private function exchangeJWTForToken(string $jwt): string
    {
        $url = sprintf(
            'https://%s.snowflakecomputing.com/oauth/token',
            strtolower($this->account)
        );

        try {
            $this->debugLog("Requesting token from: {$url}");
            $this->debugLog("JWT assertion: " . substr($jwt, 0, 100) . "...");
            
            $response = $this->client->post($url, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
            ]);

            $responseBody = $response->getBody()->getContents();
            $this->debugLog("Token response: " . substr($responseBody, 0, 100) . "...");
            
            // Snowflake returns the JWT token directly, not in JSON format
            if (empty($responseBody)) {
                throw new Exception('Empty response from Snowflake token endpoint');
            }
            
            // Check if it's a JWT (starts with eyJ which is base64 for {"alg":...)
            if (strpos($responseBody, 'eyJ') === 0) {
                return $responseBody; // It's a JWT token
            }
            
            // Try parsing as JSON (fallback)
            $data = json_decode($responseBody, true);
            if (isset($data['access_token'])) {
                return $data['access_token'];
            }
            
            throw new Exception('Unexpected token response format: ' . substr($responseBody, 0, 200));
            
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorResponse = $e->getResponse()->getBody()->getContents();
            $this->debugLog("Token exchange error: " . $errorResponse);
            throw new Exception('JWT token exchange failed: ' . $errorResponse);
        }
    }

    /**
     * Execute a SQL query
     */
    public function query(string $sql, array $bindings = []): array
    {
        $token = $this->getAccessToken();
        $url = sprintf(
            'https://%s.snowflakecomputing.com/api/v2/statements',
            strtolower($this->account)
        );

        $requestBody = [
            'statement' => $sql,
            'timeout' => 300,
            'database' => $this->database,
            'schema' => $this->schema,
            'warehouse' => $this->warehouse,
            'role' => $this->role,
        ];

        if (!empty($bindings)) {
            $requestBody['bindings'] = $bindings;
        }

        $this->debugLog("Making API request with token: " . substr($token, 0, 50) . "...");
        
        $response = $this->client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Snowflake-Authorization-Token-Type' => 'OAUTH',
            ],
            'json' => $requestBody,
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        // Some long‑running statements may return a status URL first; poll until results are ready.
        if (!isset($result['resultSetMetaData']) && isset($result['statementStatusUrl'])) {
            $statusUrl = $result['statementStatusUrl'];

            for ($i = 0; $i < 60; $i++) {
                // Small delay between polls
                sleep(1);

                $statusResponse = $this->client->get($statusUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                        'X-Snowflake-Authorization-Token-Type' => 'OAUTH',
                    ],
                ]);

                $statusBody = $statusResponse->getBody()->getContents();
                $status = json_decode($statusBody, true);

                if (isset($status['resultSetMetaData'])) {
                    $result = $status;
                    break;
                }

                if (isset($status['status']) && in_array($status['status'], ['FAILED', 'ABORTED'], true)) {
                    $message = $status['errorMessage'] ?? 'Snowflake query failed with status ' . $status['status'];
                    throw new Exception($message);
                }
            }
        }

        if (!isset($result['resultSetMetaData'])) {
            throw new Exception('Invalid query response from Snowflake');
        }

        // Handle pagination fully
        $allData = $result['data'] ?? [];
        $rowCount = count($allData);

        $this->debugLog('Initial Snowflake rowCount: ' . ($result['rowCount'] ?? 'n/a'));
        $this->debugLog('Initial nextRowUrl: ' . ($result['nextRowUrl'] ?? 'null'));

        $nextRowUrl = $result['nextRowUrl'] ?? null;
        while ($nextRowUrl) {
            // nextRowUrl can be relative; prepend account host if needed
            if (!str_starts_with($nextRowUrl, 'http')) {
                $nextRowUrl = 'https://' . strtolower($this->account) . '.snowflakecomputing.com' . $nextRowUrl;
            }

            $this->debugLog('Fetching nextRowUrl: ' . $nextRowUrl);

            $pageResponse = $this->client->get($nextRowUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'X-Snowflake-Authorization-Token-Type' => 'OAUTH',
                ],
            ]);

            $pageBody = $pageResponse->getBody()->getContents();
            $page = json_decode($pageBody, true);

            if (isset($page['data']) && is_array($page['data'])) {
                $allData = array_merge($allData, $page['data']);
                $rowCount += count($page['data']);
            }

            $nextRowUrl = $page['nextRowUrl'] ?? null;
        }

        $result['data'] = $allData;
        $result['rowCount'] = $rowCount;

        return $this->formatResult($result);
    }

    /**
     * Format Snowflake result into associative array
     */
    private function formatResult(array $result): array
    {
        $columns = array_column($result['resultSetMetaData']['rowType'], 'name');
        $rows = [];

        if (isset($result['data'])) {
            foreach ($result['data'] as $row) {
                $rows[] = array_combine($columns, $row);
            }
        }

        return [
            'data' => $rows,
            'rowCount' => $result['rowCount'] ?? count($rows),
            'columns' => $columns,
        ];
    }

    private function debugLog(string $message): void
    {
        if (function_exists('env')) {
            $enabled = env('SNOWFLAKE_DEBUG', false);
        } else {
            $enabled = false;
        }

        if ($enabled) {
            error_log($message);
        }
    }

    /**
     * Get qualified username (ACCOUNT.USER)
     */
    private function getQualifiedUsername(): string
    {
        // Extract account locator (remove region if present)
        $accountLocator = explode('.', $this->account)[0];
        return strtoupper($accountLocator . '.' . $this->user);
    }

    /**
     * Convert PEM to DER format
     */
    private function pemToDer(string $pem): string
    {
        // Remove PEM headers/footers and whitespace
        $pem = preg_replace('/-----[^-]+-----/', '', $pem);
        $pem = preg_replace('/\s+/', '', $pem);
        
        return base64_decode($pem);
    }

    /**
     * Test connection
     */
    public function testConnection(): array
    {
        try {
            $result = $this->query('SELECT CURRENT_VERSION() as version, CURRENT_USER() as user, CURRENT_ROLE() as role');
            return [
                'success' => true,
                'data' => $result['data'][0] ?? [],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get connection info
     */
    public function getConnectionInfo(): array
    {
        return [
            'account' => $this->account,
            'user' => $this->user,
            'database' => $this->database,
            'schema' => $this->schema,
            'warehouse' => $this->warehouse,
            'role' => $this->role,
        ];
    }

    /**
     * Get public key for Snowflake registration
     */
    public function getPublicKeyForRegistration(): array
    {
        $privateKey = openssl_pkey_get_private($this->privateKey, $this->privateKeyPassphrase ?: null);
        if (!$privateKey) {
            throw new Exception('Failed to load private key: ' . openssl_error_string());
        }

        $publicKeyDetails = openssl_pkey_get_details($privateKey);
        $publicKeyPem = $publicKeyDetails['key'];
        
        // Remove headers and format for Snowflake
        $publicKeyClean = preg_replace('/-----[^-]+-----/', '', $publicKeyPem);
        $publicKeyClean = preg_replace('/\s+/', '', $publicKeyClean);
        
        // Calculate fingerprint
        $publicKeyDer = $this->pemToDer($publicKeyPem);
        $sha256Fingerprint = hash('sha256', $publicKeyDer, true);
        $fingerprintB64 = base64_encode($sha256Fingerprint);
        
        return [
            'public_key_pem' => $publicKeyPem,
            'public_key_clean' => $publicKeyClean,
            'fingerprint' => $fingerprintB64,
        ];
    }

    /**
     * Initialize SQL Server connection
     */
    public function initializeSqlServer(?string $mode = null): void
    {
        $config = self::loadConfiguration();
        
        // Check if we have the new configuration structure
        if (isset($config['sql_server'])) {
            // Try to find SQL Server config - check multiple possible keys
            if (isset($config['sql_server']['sql_server_connection'])) {
                // Generic key - use this regardless of mode
                $this->sqlServerConfig = $config['sql_server']['sql_server_connection'];
            } elseif ($mode && isset($config['sql_server'][$mode])) {
                // Mode-specific key (if mode is provided)
                $this->sqlServerConfig = $config['sql_server'][$mode];
            } elseif (isset($config['sql_server']['sandbox'])) {
                // Default to sandbox if mode not found
                $this->sqlServerConfig = $config['sql_server']['sandbox'];
            } elseif (isset($config['sql_server']['production'])) {
                // Try production as fallback
                $this->sqlServerConfig = $config['sql_server']['production'];
            } else {
                throw new Exception("SQL Server configuration not found. Looking for key: sql_server_connection, sandbox, or production");
            }
        } else {
            // Legacy structure
            if (!isset($config['sql_server'][$mode])) {
                throw new Exception("SQL Server configuration not found for mode: {$mode}");
            }
            $this->sqlServerConfig = $config['sql_server'][$mode];
        }
        
        $this->validateSqlServerConfig($this->sqlServerConfig);
    }

    /**
     * Validate SQL Server configuration
     */
    private function validateSqlServerConfig(array $config): void
    {
        $required = ['dsn', 'username', 'password'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($config[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            throw new Exception(
                'Missing required SQL Server configuration: ' . implode(', ', $missing) . '. ' .
                'Please set the appropriate environment variables or update the configuration file.'
            );
        }
    }

    /**
     * Get SQL Server connection
     */
    public function getSqlServerConnection(): PDO
    {
        if ($this->sqlServerConnection === null) {
            if ($this->sqlServerConfig === null) {
                throw new Exception('SQL Server not initialized. Call initializeSqlServer() first.');
            }

            // Build DSN with database included for Azure SQL compatibility
            $dsn = $this->sqlServerConfig['dsn'];
            if (isset($this->sqlServerConfig['database']) && !empty($this->sqlServerConfig['database'])) {
                // Add database to DSN if not already present
                if (strpos($dsn, 'Database=') === false) {
                    $dsn .= ';Database=' . $this->sqlServerConfig['database'];
                }
            }

            $this->sqlServerConnection = new PDO(
                $dsn,
                $this->sqlServerConfig['username'],
                $this->sqlServerConfig['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }

        return $this->sqlServerConnection;
    }

    /**
     * Test SQL Server connection
     */
    public function testSqlServerConnection(): array
    {
        try {
            $pdo = $this->getSqlServerConnection();
            
            $stmt = $pdo->query("SELECT @@SERVERNAME as server_name, DB_NAME() as current_db, GETDATE() as server_time");
            $result = $stmt->fetch();

            return [
                'success' => true,
                'data' => [
                    'server' => $result['server_name'],
                    'database' => $result['current_db'],
                    'time' => is_object($result['server_time']) ? $result['server_time']->format('Y-m-d H:i:s') : $result['server_time'],
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute SQL Server query
     */
    public function querySqlServer(string $sql, array $params = []): array
    {
        try {
            $pdo = $this->getSqlServerConnection();

            $trimmedSql = ltrim($sql);
            $isSelect = stripos($trimmedSql, 'SELECT') === 0;

            if ($isSelect) {
                if (empty($params)) {
                    $stmt = $pdo->query($sql);
                } else {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }

                $data = $stmt->fetchAll();

                return [
                    'success' => true,
                    'data' => $data,
                    'row_count' => count($data),
                ];
            }

            if (empty($params)) {
                $affected = $pdo->exec($sql);
            } else {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $affected = $stmt->rowCount();
            }

            return [
                'success' => true,
                'data' => [],
                'row_count' => $affected === false ? 0 : $affected,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * Test both Snowflake and SQL Server connections
     */
    public function testBothConnections(string $mode = 'production'): array
    {
        $results = [
            'snowflake' => $this->testConnection(),
            'sql_server' => null
        ];

        // Initialize and test SQL Server
        try {
            $this->initializeSqlServer($mode);
            $results['sql_server'] = $this->testSqlServerConnection();
        } catch (Exception $e) {
            $results['sql_server'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }

        $results['both_ready'] = $results['snowflake']['success'] && $results['sql_server']['success'];

        return $results;
    }

    /**
     * Get SQL Server configuration info
     */
    public function getSqlServerInfo(): array
    {
        if ($this->sqlServerConfig === null) {
            return ['error' => 'SQL Server not initialized'];
        }

        return [
            'database' => $this->sqlServerConfig['database'],
            'table' => $this->sqlServerConfig['table'],
            'timeout' => $this->sqlServerConfig['timeout'],
        ];
    }
}
