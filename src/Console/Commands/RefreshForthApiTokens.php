<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshForthApiTokens extends Command
{
    protected $signature = 'refresh:forth-api-tokens {--test : Test mode - fetch tokens but do not update database}';

    protected $description = 'Refresh Forth API tokens for LT, LDR, and PLAW by fetching new access tokens from Forth API';

    private const FORTH_TOKEN_URL = 'https://api.forthcrm.com/v1/auth/token';

    private array $companies = [
        'LT' => [
            'category' => 'LT',
            'client_secret_env' => 'FORTH_LT_CLIENT_SECRET',
            'key_id_env' => 'FORTH_LT_KEY_ID',
            'pk' => 2,
        ],
        'LDR' => [
            'category' => 'LDR',
            'client_secret_env' => 'FORTH_LDR_CLIENT_SECRET',
            'key_id_env' => 'FORTH_LDR_KEY_ID',
            'pk' => 1,
        ],
        'PLAW' => [
            'category' => 'PLAW',
            'client_secret_env' => 'FORTH_PLAW_CLIENT_SECRET',
            'key_id_env' => 'FORTH_PLAW_KEY_ID',
            'pk' => 3,
        ],
    ];

    public function handle(): int
    {
        $this->info('Forth API Token Refresh: starting.');
        Log::info('RefreshForthApiTokens command started.');

        $testMode = $this->option('test');
        if ($testMode) {
            $this->warn('Running in TEST MODE - tokens will be fetched but NOT saved to database.');
        }

        $connector = null;
        $hadException = false;

        try {
            $connector = DBConnector::fromEnvironment('plaw');
            $connector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connection: ' . $e->getMessage());
            Log::error('RefreshForthApiTokens: SQL Server connection failed.', ['exception' => $e]);
            return Command::FAILURE;
        }

        foreach ($this->companies as $companyName => $config) {
            $this->info("[$companyName] Starting token refresh...");

            try {
                $clientSecret = env($config['client_secret_env']);
                $keyId = env($config['key_id_env']);

                if (empty($clientSecret) || empty($keyId)) {
                    $this->error("[$companyName] Missing environment variables: {$config['client_secret_env']} or {$config['key_id_env']}");
                    $this->warn("[$companyName] Skipping...");
                    $hadException = true;
                    continue;
                }

                $this->info("[$companyName] Fetching new access token from Forth API...");
                $newToken = $this->fetchAccessToken($clientSecret, $keyId, $companyName);

                if ($newToken === null) {
                    $this->error("[$companyName] Failed to fetch access token.");
                    $hadException = true;
                    continue;
                }

                $this->info("[$companyName] Successfully fetched new token: $newToken");

                if ($testMode) {
                    $this->warn("[$companyName] TEST MODE - Skipping database update.");
                    $this->info("[$companyName] Token would be updated in TblAPIKeys (PK={$config['pk']}, Category={$config['category']})");
                } else {
                    $this->info("[$companyName] Updating TblAPIKeys...");
                    $updated = $this->updateApiKey($connector, $config['pk'], $config['category'], $newToken);

                    if ($updated) {
                        $this->info("[$companyName] Successfully updated TblAPIKeys.");
                        Log::info('RefreshForthApiTokens: token updated.', [
                            'company' => $companyName,
                            'category' => $config['category'],
                            'pk' => $config['pk'],
                        ]);
                    } else {
                        $this->error("[$companyName] Failed to update TblAPIKeys.");
                        $hadException = true;
                    }
                }
            } catch (\Throwable $e) {
                $hadException = true;
                $this->error("[$companyName] Token refresh failed: " . $e->getMessage());
                Log::error('RefreshForthApiTokens: exception during token refresh.', [
                    'company' => $companyName,
                    'exception' => $e,
                ]);
            }
        }

        $this->info('Forth API Token Refresh: finished.');
        Log::info('RefreshForthApiTokens command finished.', [
            'status' => $hadException ? 'PARTIAL_FAILURE' : 'SUCCESS',
        ]);

        return $hadException ? Command::FAILURE : Command::SUCCESS;
    }

    protected function fetchAccessToken(string $clientSecret, string $keyId, string $company): ?string
    {
        try {
            $ch = curl_init(self::FORTH_TOKEN_URL);

            $payload = json_encode([
                'client_secret' => $clientSecret,
                'client_id' => $keyId,
            ]);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $this->error("[$company] cURL error: $curlError");
                Log::error('RefreshForthApiTokens: cURL error.', [
                    'company' => $company,
                    'error' => $curlError,
                ]);
                return null;
            }

            if ($httpCode !== 200) {
                $this->error("[$company] HTTP error: $httpCode");
                $this->error("[$company] Response: " . substr($response, 0, 500));
                Log::error('RefreshForthApiTokens: HTTP error.', [
                    'company' => $company,
                    'http_code' => $httpCode,
                    'response' => substr($response, 0, 500),
                ]);
                return null;
            }

            $data = json_decode($response, true);

            // Forth API returns token in response.api_key format
            if (isset($data['response']['api_key'])) {
                return $data['response']['api_key'];
            }

            // Fallback to access_token if format changes
            if (isset($data['access_token'])) {
                return $data['access_token'];
            }

            $this->error("[$company] No api_key or access_token in response.");
            $this->error("[$company] Response: " . substr($response, 0, 500));
            Log::error('RefreshForthApiTokens: No api_key in response.', [
                'company' => $company,
                'response' => substr($response, 0, 500),
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->error("[$company] Exception fetching token: " . $e->getMessage());
            Log::error('RefreshForthApiTokens: exception fetching token.', [
                'company' => $company,
                'exception' => $e,
            ]);
            return null;
        }
    }

    protected function updateApiKey(DBConnector $connector, int $pk, string $category, string $apiKey): bool
    {
        try {
            $currentDate = now()->format('Y-m-d');
            $apiKeyEsc = $this->escapeSqlString($apiKey);
            $categoryEsc = $this->escapeSqlString($category);

            $sql = "UPDATE TblAPIKeys SET API_Key = '{$apiKeyEsc}', Creation_Date = '{$currentDate}' WHERE PK = {$pk} AND Category = '{$categoryEsc}'";

            $result = $connector->querySqlServer($sql);

            if (is_array($result) && isset($result['success']) && $result['success'] === false) {
                $errorMsg = $result['error'] ?? 'Unknown SQL Server error';
                $this->error("Update failed: $errorMsg");
                return false;
            }

            if (is_array($result)) {
                foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
                    if (isset($result[$key]) && is_numeric($result[$key])) {
                        $affected = (int) $result[$key];
                        if ($affected > 0) {
                            return true;
                        }
                    }
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->error("Exception updating TblAPIKeys: " . $e->getMessage());
            Log::error('RefreshForthApiTokens: exception updating TblAPIKeys.', [
                'pk' => $pk,
                'category' => $category,
                'exception' => $e,
            ]);
            return false;
        }
    }

    protected function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
