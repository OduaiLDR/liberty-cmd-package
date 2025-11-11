<?php

namespace Cmd\Reports\Services;

use GuzzleHttp\Client;
use Firebase\JWT\JWT;
use Exception;

/**
 * Snowflake JWT Connector
 * 
 * Provides JWT-based authentication and query execution for Snowflake
 */
class SnowflakeConnector
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

    public function __construct(array $config)
    {
        $this->account = $config['account'];
        $this->user = strtoupper($config['user']);
        $this->database = $config['database'] ?? 'DPP_DATA';
        $this->schema = $config['schema'] ?? 'READER';
        $this->warehouse = $config['warehouse'] ?? 'DEFAULT_WH';
        $this->role = $config['role'] ?? 'ACCOUNTADMIN';
        $this->privateKey = $config['private_key'];
        $this->privateKeyPassphrase = $config['private_key_passphrase'];

        $this->client = new Client([
            'timeout' => 300,
            'verify' => true,
        ]);
    }

    /**
     * Create connector from environment configuration
     */
    public static function fromEnvironment(string $env): self
    {
        $config = self::loadConfiguration();
        
        if (!isset($config['environments'][$env])) {
            throw new Exception("Unknown environment: {$env}");
        }

        return new self($config['environments'][$env]);
    }

    /**
     * Locate configuration from Laravel config or package stub
     */
    private static function loadConfiguration(): array
    {
        if (function_exists('config')) {
            $snowflakeConfig = config('snowflake');
            if (is_array($snowflakeConfig) && !empty($snowflakeConfig)) {
                return $snowflakeConfig;
            }

            // legacy key support
            $legacyConfig = config('reports');
            if (is_array($legacyConfig) && !empty($legacyConfig)) {
                return $legacyConfig;
            }
        }

        if (function_exists('base_path')) {
            $publishedConfig = base_path('config/snowflake.php');
            if (is_file($publishedConfig)) {
                return require $publishedConfig;
            }
        }

        // Fallback to package stub next to this src/ tree
        $packageConfig = __DIR__ . '/../../config/snowflake.php';
        if (is_file($packageConfig)) {
            return require $packageConfig;
        }

        throw new Exception('Snowflake configuration file could not be located. '
            . 'Publish the config via php artisan vendor:publish --tag=reports-config or ensure config/snowflake.php exists.');
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
        $this->tokenExpiry = time() + 3600; // Token valid for 1 hour

        return $this->accessToken;
    }

    /**
     * Generate JWT token for authentication
     */
    private function generateJWT(): string
    {
        $now = time();
        $payload = [
            'iss' => $this->getQualifiedUsername(),
            'sub' => $this->getQualifiedUsername(),
            'iat' => $now,
            'exp' => $now + 3600, // 1 hour expiration
        ];

        $privateKey = openssl_pkey_get_private($this->privateKey, $this->privateKeyPassphrase);
        if (!$privateKey) {
            throw new Exception('Failed to load private key: ' . openssl_error_string());
        }

        $publicKey = openssl_pkey_get_details($privateKey);
        $publicKeyPem = $publicKey['key'];

        // Calculate SHA256 fingerprint
        $publicKeyDer = $this->pemToDer($publicKeyPem);
        $sha256Fingerprint = hash('sha256', $publicKeyDer, true);
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        return JWT::encode($payload, $privateKey, 'RS256', null, $header);
    }

    /**
     * Exchange JWT for OAuth access token
     */
    private function exchangeJWTForToken(string $jwt): string
    {
        $url = sprintf(
            'https://%s.snowflakecomputing.com/oauth/token',
            $this->account
        );

        $response = $this->client->post($url, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        if (!isset($data['access_token'])) {
            throw new Exception('Failed to obtain access token from Snowflake');
        }

        return $data['access_token'];
    }

    /**
     * Execute a SQL query
     */
    public function query(string $sql, array $bindings = []): array
    {
        $token = $this->getAccessToken();
        $url = sprintf(
            'https://%s.snowflakecomputing.com/api/v2/statements',
            $this->account
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

        $response = $this->client->post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Snowflake-Authorization-Token-Type' => 'KEYPAIR_JWT',
            ],
            'json' => $requestBody,
        ]);

        $result = json_decode($response->getBody()->getContents(), true);

        if (!isset($result['resultSetMetaData'])) {
            throw new Exception('Invalid query response from Snowflake');
        }

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

    /**
     * Get qualified username (ACCOUNT.USER)
     */
    private function getQualifiedUsername(): string
    {
        return strtoupper($this->account . '.' . $this->user);
    }

    /**
     * Convert PEM to DER format
     */
    private function pemToDer(string $pem): string
    {
        $lines = explode("\n", $pem);
        $der = '';
        foreach ($lines as $line) {
            if (strpos($line, '-----') === false) {
                $der .= $line;
            }
        }
        return base64_decode($der);
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
}
