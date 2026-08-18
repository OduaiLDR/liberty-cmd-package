<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncPhoneNumbers extends Command
{
    protected $signature = 'Sync:phone-numbers
        {--batch-size=2000 : Number of rows per INSERT}
        {--dry-run : Read and normalize the source data without changing SQL Server}';

    protected $description = 'Sync LT phone numbers from Snowflake CONTACTS into LDR SQL Server TblPhoneNumbers.';

    private const SOURCE = 'DP_LT';
    private const SNOWFLAKE_ENV = 'lt';

    public function handle(): int
    {
        $this->info('[INFO] SyncPhoneNumbers: starting.');
        Log::info('SyncPhoneNumbers command started.', ['source' => self::SOURCE]);

        $batchSize = (int) $this->option('batch-size');
        if ($batchSize <= 0) {
            $batchSize = 2000;
        }

        try {
            $this->info('[DEBUG] Initializing LT Snowflake connector...');
            $snowflake = DBConnector::fromEnvironment(self::SNOWFLAKE_ENV);
            $this->info('[DEBUG] LT Snowflake connector OK.');

            $sqlServer = null;
            if ($this->option('dry-run')) {
                $this->warn('[
                 RUN] SQL Server connection and all writes are disabled.');
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

            $phones = $this->fetchPhonesFromSnowflake($snowflake);
            $this->info('[INFO] Fetched ' . count($phones) . ' phone rows from Snowflake.');

            $normalized = $this->normalizePhones($phones);
            $this->info('[INFO] Normalized to ' . count($normalized) . ' non-empty phones (matches VBA, duplicates preserved).');

            if (empty($normalized)) {
                $this->warn('[WARN] No phones to insert.');
                Log::info('SyncPhoneNumbers: no phones to insert.');
                return Command::SUCCESS;
            }

            $deleted = $this->deleteExistingPhones($sqlServer);
            $this->info("[INFO] Deleted {$deleted} existing rows for Source = '" . self::SOURCE . "'.");

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
        // Flatten the four phone columns in one CONTACTS scan. COMPACT removes
        // nulls before flattening but preserves duplicates, matching UNION ALL.
        $sql = "
            SELECT c.ID, phone_values.VALUE::STRING AS PHONE
            FROM CONTACTS AS c,
                 LATERAL FLATTEN(
                     INPUT => ARRAY_CONSTRUCT_COMPACT(c.PHONE, c.PHONE2, c.PHONE3, c.PHONE4)
                 ) AS phone_values
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
            $this->assertSqlServerSuccess($result, 'insert phone batch ' . ($index + 1));
            $affected = $this->extractAffected($result);
            $inserted = $affected > 0 ? $affected : count($chunk);
            $totalInserted += $inserted;

            $this->info(sprintf('[INFO] Batch %d: inserted %d rows.', $index + 1, $inserted));
        }

        return $totalInserted;
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
