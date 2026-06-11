<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPhoneNumbers extends Command
{
    protected $signature = 'Sync:phone-numbers {--batch-size=1000 : Number of rows per INSERT}';

    protected $description = 'Sync LT phone numbers from Snowflake CONTACTS into LDR SQL Server TblPhoneNumbers.';

    private const SOURCE = 'LT';
    private const SNOWFLAKE_ENV = 'lt';

    public function handle(): int
    {
        $this->info('[INFO] SyncPhoneNumbers: starting.');
        Log::info('SyncPhoneNumbers command started.', ['source' => self::SOURCE]);

        $batchSize = (int) $this->option('batch-size');
        if ($batchSize <= 0) {
            $batchSize = 1000;
        }

        try {
            $this->info('[DEBUG] Initializing LT Snowflake connector...');
            $snowflake = DBConnector::fromEnvironment(self::SNOWFLAKE_ENV);
            $this->info('[DEBUG] LT Snowflake connector OK.');

            $this->info('[DEBUG] Initializing LDR SQL Server connection...');
            $sqlServer = $this->initializeSqlServerConnector();
            $this->info('[DEBUG] LDR SQL Server OK.');
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('SyncPhoneNumbers: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $deleted = $this->deleteExistingPhones($sqlServer);
            $this->info("[INFO] Deleted {$deleted} existing rows for Source = '" . self::SOURCE . "'.");

            $phones = $this->fetchPhonesFromSnowflake($snowflake);
            $this->info('[INFO] Fetched ' . count($phones) . ' phone rows from Snowflake.');

            $normalized = $this->normalizePhones($phones);
            $this->info('[INFO] Normalized to ' . count($normalized) . ' non-empty phones (matches VBA, duplicates preserved).');

            if (empty($normalized)) {
                $this->warn('[WARN] No phones to insert.');
                Log::info('SyncPhoneNumbers: no phones to insert.');
                return Command::SUCCESS;
            }

            $inserted = $this->insertPhonesInBatches($sqlServer, $normalized, $batchSize);
            $this->info("[INFO] Inserted {$inserted} rows into TblPhoneNumbers.");

            $cleaned = $this->cleanupEmptyPhones($sqlServer);
            if ($cleaned > 0) {
                $this->info("[INFO] Cleanup removed {$cleaned} empty-phone rows.");
            }

            Log::info('SyncPhoneNumbers command finished.', [
                'source' => self::SOURCE,
                'deleted' => $deleted,
                'inserted' => $inserted,
                'cleaned' => $cleaned,
            ]);
        } catch (\Throwable $e) {
            $this->error('SyncPhoneNumbers failed: ' . $e->getMessage());
            Log::error('SyncPhoneNumbers: exception', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->info('[SUCCESS] SyncPhoneNumbers completed successfully!');
        return Command::SUCCESS;
    }

    private function fetchPhonesFromSnowflake(DBConnector $snowflake): array
    {
        $sql = "
            SELECT ID, PHONE AS PHONE FROM CONTACTS WHERE PHONE IS NOT NULL
            UNION ALL
            SELECT ID, PHONE2 AS PHONE FROM CONTACTS WHERE PHONE2 IS NOT NULL
            UNION ALL
            SELECT ID, PHONE3 AS PHONE FROM CONTACTS WHERE PHONE3 IS NOT NULL
            UNION ALL
            SELECT ID, PHONE4 AS PHONE FROM CONTACTS WHERE PHONE4 IS NOT NULL
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function normalizePhones(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $raw = $row['PHONE'] ?? '';
            $digits = preg_replace('/\D/', '', (string) $raw);
            if ($digits === '') {
                continue;
            }
            $normalized[] = [
                'phone' => str_pad($digits, 10, '0', STR_PAD_LEFT),
                'cid'   => isset($row['ID']) ? (int) $row['ID'] : null,
            ];
        }
        return $normalized;
    }

    private function deleteExistingPhones(DBConnector $connector): int
    {
        $source = $this->esc(self::SOURCE);
        $sql = "DELETE FROM TblPhoneNumbers WHERE Source = '{$source}'";
        $result = $connector->querySqlServer($sql);
        return $this->extractAffected($result);
    }

    private function insertPhonesInBatches(DBConnector $connector, array $phones, int $batchSize): int
    {
        $totalInserted = 0;
        $sourceEsc = $this->esc(self::SOURCE);
        $chunks = array_chunk($phones, $batchSize);

        foreach ($chunks as $index => $chunk) {
            $values = [];
            foreach ($chunk as $item) {
                $phoneEsc = $this->esc($item['phone']);
                $cid = $item['cid'] !== null ? (int) $item['cid'] : 'NULL';
                $values[] = "('{$phoneEsc}', '{$sourceEsc}', {$cid})";
            }

            if (empty($values)) {
                continue;
            }

            $sql = 'INSERT INTO TblPhoneNumbers (Phone, Source, CID) VALUES ' . implode(', ', $values);
            $result = $connector->querySqlServer($sql);
            $affected = $this->extractAffected($result);
            $inserted = $affected > 0 ? $affected : count($chunk);
            $totalInserted += $inserted;

            $this->info(sprintf('[INFO] Batch %d: inserted %d rows.', $index + 1, $inserted));
        }

        return $totalInserted;
    }

    private function cleanupEmptyPhones(DBConnector $connector): int
    {
        $sql = "DELETE FROM TblPhoneNumbers WHERE Phone = ''";
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

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
