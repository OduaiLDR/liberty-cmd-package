<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncCalls extends Command
{
    protected $signature = 'Sync:calls';

    protected $description = 'Recalculate marketing drop call counts: TblContacts.Campaign -> TblMarketing.Calls.';

    public function handle(): int
    {
        $this->info('[INFO] SyncCalls: starting.');
        Log::info('SyncCalls command started.');

        try {
            $this->info('[DEBUG] Initializing LDR SQL Server connection...');
            $sqlServer = $this->initializeSqlServerConnector();
            $this->info('[DEBUG] LDR SQL Server OK.');
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncCalls: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $marketingCount = $this->countMarketingRows($sqlServer);
            $this->info("[INFO] TblMarketing rows to update: {$marketingCount}");

            if ($marketingCount === 0) {
                $this->warn('[WARN] TblMarketing is empty. Nothing to do.');
                Log::info('SyncCalls: no marketing rows.');
                return Command::SUCCESS;
            }

            $updated = $this->updateCallCounts($sqlServer);
            $this->info("[INFO] Updated {$updated} TblMarketing rows.");

            Log::info('SyncCalls command finished.', [
                'marketing_rows' => $marketingCount,
                'updated' => $updated,
            ]);
        } catch (\Throwable $e) {
            $this->error('SyncCalls failed: ' . $e->getMessage());
            Log::error('SyncCalls: exception', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->info('[SUCCESS] SyncCalls completed successfully!');
        return Command::SUCCESS;
    }

    private function countMarketingRows(DBConnector $connector): int
    {
        $result = $connector->querySqlServer('SELECT COUNT(*) AS cnt FROM TblMarketing');
        $data = $result['data'] ?? [];
        if (empty($data)) {
            return 0;
        }
        $row = $data[0];
        return (int) ($row['cnt'] ?? 0);
    }

    private function updateCallCounts(DBConnector $connector): int
    {
        $sql = <<<SQL
UPDATE m
SET m.Calls = ISNULL(c.cnt, 0),
    m.Update_Date = GETDATE()
FROM TblMarketing m
LEFT JOIN (
    SELECT Campaign, COUNT(*) AS cnt
    FROM TblContacts
    WHERE Campaign IS NOT NULL
    GROUP BY Campaign
) c ON c.Campaign = m.Drop_Name;
SQL;

        $result = $connector->querySqlServer($sql);
        return $this->extractAffected($result);
    }

    private function extractAffected($result): int
    {
        if (!is_array($result)) {
            return 0;
        }
        foreach (['rowCount', 'affected_rows', 'row_count'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key])) {
                return (int) $result[$key];
            }
        }
        return 0;
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        $candidates = ['ldr', 'plaw', 'production', 'sandbox'];
        $errors = [];

        foreach ($candidates as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();
                return $connector;
            } catch (\Throwable $e) {
                $errors[] = "{$env}: {$e->getMessage()}";
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: ' . implode('; ', $errors));
    }
}
