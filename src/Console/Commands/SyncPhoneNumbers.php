<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPhoneNumbers extends Command
{
    protected $signature = 'Sync:phone-numbers
        {--batch-size=1000 : Number of rows per INSERT (SQL Server maximum)}
        {--dry-run : Read and normalize the source data without changing SQL Server}';

    protected $description = 'Sync LT phone numbers from Snowflake CONTACTS into LDR SQL Server TblPhoneNumbers.';

    private const SOURCE = 'DP_LT';
    private const SNOWFLAKE_ENV = 'lt';

    public function handle(): int
    {
        $this->info('[INFO] SyncPhoneNumbers: starting.');
        Log::info('SyncPhoneNumbers command started.', ['source' => self::SOURCE]);

        $batchSize = (int) $this->option('batch-size');
        // SQL Server permits at most 1,000 row constructors in one INSERT.
        $batchSize = min(max(1, $batchSize), 1000);

        $stagePath = null;

        try {
            $this->info('[DEBUG] Initializing LT Snowflake connector...');
            $snowflake = DBConnector::fromEnvironment(self::SNOWFLAKE_ENV);
            $this->info('[DEBUG] LT Snowflake connector OK.');

            $sqlServer = null;
            if ($this->option('dry-run')) {
                $this->warn('[DRY RUN] SQL Server connection and all writes are disabled.');
            } else {
                $this->info('[DEBUG] Initializing LDR SQL Server connection...');
                $sqlServer = $this->initializeSqlServerConnector();
                $this->info('[DEBUG] LDR SQL Server OK.');
            }
        } catch (\Throwable $e) {
            $this->error('Failed to initialize connectors: ' . $e->getMessage());
            Log::error('SyncPhoneNumbers: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            if ($this->option('dry-run')) {
                $summary = $this->fetchDryRunSummary($snowflake);
                $sourceRows = (int) ($summary['SOURCE_ROWS'] ?? 0);
                $normalizedRows = (int) ($summary['NORMALIZED_ROWS'] ?? 0);
                $contacts = (int) ($summary['CONTACTS'] ?? 0);

                $this->info(sprintf(
                    '[DRY RUN] Source rows: %d; contacts: %d; normalized rows to insert: %d; batches of %d.',
                    $sourceRows,
                    $contacts,
                    $normalizedRows,
                    $batchSize
                ));
                Log::info('SyncPhoneNumbers dry run finished.', [
                    'source' => self::SOURCE,
                    'source_rows' => $sourceRows,
                    'normalized_rows' => $normalizedRows,
                    'contacts' => $contacts,
                ]);
                return Command::SUCCESS;
            }

            [$stagePath, $sourceCount, $normalizedCount] = $this->stagePhonesFromSnowflake($snowflake);
            $this->info("[INFO] Fetched {$sourceCount} phone rows from Snowflake.");
            $this->info("[INFO] Normalized to {$normalizedCount} non-empty phones (matches VBA, duplicates preserved).");

            if ($normalizedCount === 0) {
                $this->warn('[WARN] No phones to insert.');
                Log::info('SyncPhoneNumbers: no phones to insert.');
                return Command::SUCCESS;
            }

            $deleted = $this->deleteExistingPhones($sqlServer);
            $this->info("[INFO] Deleted {$deleted} existing rows for Source = '" . self::SOURCE . "'.");

            $inserted = $this->insertPhonesFromFile($sqlServer, $stagePath, $batchSize);
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
        } finally {
            if ($stagePath !== null && is_file($stagePath)) {
                @unlink($stagePath);
            }
        }

        $this->info('[SUCCESS] SyncPhoneNumbers completed successfully!');
        return Command::SUCCESS;
    }

    private function stagePhonesFromSnowflake(DBConnector $snowflake): array
    {
        $pageSize = 5000;
        $offset = 0;
        $sourceCount = 0;
        $normalizedCount = 0;
        $stagePath = tempnam(sys_get_temp_dir(), 'sync-phone-');

        if ($stagePath === false) {
            throw new \RuntimeException('Unable to create temporary phone staging file.');
        }

        $handle = fopen($stagePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open temporary phone staging file.');
        }

        try {
            do {
                $rows = $this->fetchPhonePage($snowflake, $pageSize, $offset);
                $sourceCount += count($rows);
                $normalized = $this->normalizePhones($rows);

                foreach ($normalized as $phone) {
                    fwrite($handle, json_encode($phone, JSON_THROW_ON_ERROR) . PHP_EOL);
                }

                $normalizedCount += count($normalized);
                $offset += $pageSize;
            } while (count($rows) === $pageSize);
        } finally {
            fclose($handle);
        }

        return [$stagePath, $sourceCount, $normalizedCount];
    }

    private function fetchPhonePage(DBConnector $snowflake, int $limit, int $offset): array
    {
        // Query only one page so DBConnector never has to hold the full result set.
        $sql = "
            SELECT c.ID, phone_values.VALUE::STRING AS PHONE
            FROM CONTACTS AS c,
                 LATERAL FLATTEN(
                     INPUT => ARRAY_CONSTRUCT_COMPACT(c.PHONE, c.PHONE2, c.PHONE3, c.PHONE4)
                 ) AS phone_values
            ORDER BY c.ID, phone_values.INDEX
            LIMIT {$limit} OFFSET {$offset}
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function fetchDryRunSummary(DBConnector $snowflake): array
    {
        $sql = "
            WITH source_phones AS (
                SELECT
                    c.ID AS CID,
                    phone_values.VALUE::STRING AS RAW_PHONE
                FROM CONTACTS AS c,
                     LATERAL FLATTEN(
                         INPUT => ARRAY_CONSTRUCT_COMPACT(c.PHONE, c.PHONE2, c.PHONE3, c.PHONE4)
                     ) AS phone_values
            ), normalized AS (
                SELECT CID
                FROM source_phones
                WHERE REGEXP_REPLACE(RAW_PHONE, '[^0-9]', '') <> ''
            )
            SELECT
                (SELECT COUNT(*) FROM source_phones) AS SOURCE_ROWS,
                (SELECT COUNT(*) FROM normalized) AS NORMALIZED_ROWS,
                (SELECT COUNT(DISTINCT CID) FROM normalized) AS CONTACTS
        ";

        $result = $snowflake->query($sql);
        return $result['data'][0] ?? [];
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
        $this->assertSqlServerSuccess($result, 'delete existing phones');
        return $this->extractAffected($result);
    }

    private function insertPhonesFromFile(DBConnector $connector, string $stagePath, int $batchSize): int
    {
        $handle = fopen($stagePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to read temporary phone staging file.');
        }

        $totalInserted = 0;
        $batch = [];
        $batchNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $batch[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                if (count($batch) < $batchSize) {
                    continue;
                }

                $batchNumber++;
                $totalInserted += $this->insertPhoneBatch($connector, $batch, $batchNumber);
                $batch = [];
            }

            if ($batch !== []) {
                $batchNumber++;
                $totalInserted += $this->insertPhoneBatch($connector, $batch, $batchNumber);
            }
        } finally {
            fclose($handle);
        }

        return $totalInserted;
    }

    private function insertPhoneBatch(DBConnector $connector, array $chunk, int $batchNumber): int
    {
        $sourceEsc = $this->esc(self::SOURCE);
        $values = [];

        foreach ($chunk as $item) {
            $phoneEsc = $this->esc($item['phone']);
            $cid = $item['cid'] !== null ? (int) $item['cid'] : 'NULL';
            $values[] = "('{$phoneEsc}', '{$sourceEsc}', {$cid})";
        }

        $sql = 'INSERT INTO TblPhoneNumbers (Phone, Source, CID) VALUES ' . implode(', ', $values);
        $result = $connector->querySqlServer($sql);
        $this->assertSqlServerSuccess($result, 'insert phone batch ' . $batchNumber);
        $affected = $this->extractAffected($result);
        $inserted = $affected > 0 ? $affected : count($chunk);

        $this->info(sprintf('[INFO] Batch %d: inserted %d rows.', $batchNumber, $inserted));
        return $inserted;
    }

    private function cleanupEmptyPhones(DBConnector $connector): int
    {
        $source = $this->esc(self::SOURCE);
        $sql = "DELETE FROM TblPhoneNumbers WHERE Source = '{$source}' AND Phone = ''";
        $result = $connector->querySqlServer($sql);
        $this->assertSqlServerSuccess($result, 'cleanup empty phones');
        return $this->extractAffected($result);
    }

    private function assertSqlServerSuccess(array $result, string $operation): void
    {
        if (($result['success'] ?? true) === false) {
            throw new \RuntimeException(sprintf(
                'SQL Server %s failed: %s',
                $operation,
                $result['error'] ?? 'unknown database error'
            ));
        }
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
