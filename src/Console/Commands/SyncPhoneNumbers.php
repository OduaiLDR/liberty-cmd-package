<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SmsWorkflowLock;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class SyncPhoneNumbers extends Command
{
    protected $signature = 'Sync:phone-numbers
        {--batch-size=1000 : Number of rows per INSERT (SQL Server maximum)}
        {--dry-run : Read and normalize the source data without changing SQL Server}';

    protected $description = 'Sync LT phone numbers from Snowflake CONTACTS into LDR SQL Server TblPhoneNumbers.';

    private const SOURCE = 'DP_LT';
    private const SNOWFLAKE_ENV = 'lt';

    /** Set once TblPhoneNumbers has the contact's creation date (the SMS prospects migration adds it). */
    protected bool $withCreatedDate = false;

    public function handle(): int
    {
        if ($this->option('dry-run')) return $this->syncNow();
        try {
            return SmsWorkflowLock::run('contact-sync', fn (): int => $this->syncNow());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->warn($error->getMessage());
            return Command::FAILURE;
        }
    }

    protected function syncNow(): int
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
                $sqlServer = $this->initializeSqlServerConnection();
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

            $this->withCreatedDate = DB::connection('sqlsrv')->getSchemaBuilder()->hasColumn('TblPhoneNumbers', 'Created_Date');
            [$deleted, $inserted, $cleaned] = $this->replacePhonesFromFile($sqlServer, $stagePath, $batchSize);
            $this->info("[INFO] Replaced {$deleted} existing phone rows with {$inserted} rows.");
            $flagged = $this->flagContactedProspects();
            if ($flagged !== null) $this->info("[INFO] Marked {$flagged} SMS prospects as contacted.");
            Log::info('SyncPhoneNumbers command finished.', [
                'source' => self::SOURCE,
                'deleted' => $deleted,
                'inserted' => $inserted,
                'cleaned' => $cleaned,
                'prospects_contacted' => $flagged,
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

    /** @return array{int, int, int} */
    protected function replacePhonesFromFile(PDO $connection, string $stagePath, int $batchSize): array
    {
        $durable = SmsAvailabilityRefresh::durableEnabled();
        if ($durable && (DB::connection('sqlsrv')->getPdo() !== $connection || DB::connection('sqlsrv')->transactionLevel() !== 0)) {
            throw new \LogicException('Native phone replacement must use the sqlsrv PDO outside Laravel transactions.');
        }
        $begun = $connection->beginTransaction();
        if ($durable && ! $begun) throw new \RuntimeException('SQL Server phone replacement transaction could not start.');
        try {
            $deleted = $this->deleteExistingPhones($connection);
            $inserted = $this->insertPhonesFromFile($connection, $stagePath, $batchSize);
            $cleaned = $this->cleanupEmptyPhones($connection);
            $changed = $deleted > 0 || $inserted > 0 || $cleaned > 0;
            if ($durable && $changed) SmsAvailabilityRefresh::beforeNativeCommit(DB::connection('sqlsrv'), $connection);
            $committed = $connection->commit();
            if ($durable && ! $committed) throw new \RuntimeException('SQL Server phone replacement commit failed.');
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        // Eligibility reads this table directly; request before later flagging can fail.
        // Keep postcommit dispatch outside the rollback handler.
        if ($durable) {
            if ($changed) SmsAvailabilityRefresh::afterNativeCommit();
        } else {
            SmsAvailabilityRefresh::afterCommit(DB::connection('sqlsrv'));
        }
        return [$deleted, $inserted, $cleaned];
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

        $staged = false;
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
            $staged = true;
        } finally {
            fclose($handle);
            if (! $staged) @unlink($stagePath);
        }

        return [$stagePath, $sourceCount, $normalizedCount];
    }

    private function fetchPhonePage(DBConnector $snowflake, int $limit, int $offset): array
    {
        // Query only one page so DBConnector never has to hold the full result set.
        $sql = "
            SELECT c.ID::VARCHAR AS ID, phone_values.VALUE::STRING AS PHONE,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.CREATED)::DATE, 'YYYY-MM-DD') AS CREATED_DATE
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
            $created = (string) ($row['CREATED_DATE'] ?? '');
            $normalized[] = [
                'phone' => str_pad($digits, 10, '0', STR_PAD_LEFT),
                'cid'   => $this->contactId($row['ID'] ?? null),
                'created' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $created) ? $created : null,
            ];
        }
        return $normalized;
    }

    /** Snowflake NUMBER(38,0) does not fit a PHP integer; retain its original decimal text. */
    private function contactId(mixed $id): ?string
    {
        if ($id === null) return null;
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/\A-?[0-9]{1,38}\z/D', (string) $id)) {
            throw new \RuntimeException('Unexpected contact ID format; the phone snapshot was not replaced.');
        }
        return (string) $id;
    }

    private function deleteExistingPhones(PDO $connection): int
    {
        $source = $this->esc(self::SOURCE);
        $sql = "DELETE FROM TblPhoneNumbers WHERE Source = '{$source}'";
        return $this->executeSqlServerWrite($connection, $sql, 'delete existing phones');
    }

    private function insertPhonesFromFile(PDO $connection, string $stagePath, int $batchSize): int
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
                $totalInserted += $this->insertPhoneBatch($connection, $batch, $batchNumber);
                $batch = [];
            }

            if ($batch !== []) {
                $batchNumber++;
                $totalInserted += $this->insertPhoneBatch($connection, $batch, $batchNumber);
            }
        } finally {
            fclose($handle);
        }

        return $totalInserted;
    }

    private function insertPhoneBatch(PDO $connection, array $chunk, int $batchNumber): int
    {
        $sourceEsc = $this->esc(self::SOURCE);
        $values = [];

        foreach ($chunk as $item) {
            $phoneEsc = $this->esc($item['phone']);
            $contactId = $this->contactId($item['cid'] ?? null);
            $cid = $contactId !== null ? "'{$contactId}'" : 'NULL';
            if (! $this->withCreatedDate) {
                $values[] = "('{$phoneEsc}', '{$sourceEsc}', {$cid})";
                continue;
            }
            $created = isset($item['created']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $item['created']) ? "'{$item['created']}'" : 'NULL';
            $values[] = "('{$phoneEsc}', '{$sourceEsc}', {$cid}, {$created})";
        }

        $columns = $this->withCreatedDate ? 'Phone, Source, CID, Created_Date' : 'Phone, Source, CID';
        $sql = "INSERT INTO TblPhoneNumbers ({$columns}) VALUES " . implode(', ', $values);
        $affected = $this->executeSqlServerWrite($connection, $sql, 'insert phone batch ' . $batchNumber);
        $inserted = $affected > 0 ? $affected : count($chunk);

        $this->info(sprintf('[INFO] Batch %d: inserted %d rows.', $batchNumber, $inserted));
        return $inserted;
    }

    /**
     * SMS prospects whose phone is now a contact are flagged with the contact's creation date
     * (Jacob, 14 Oct). Null until the SMS prospects tables exist.
     */
    protected function flagContactedProspects(): ?int
    {
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('TblProspects')) return null;

        return app(SmsProspectExportRepository::class)->markContacted();
    }

    private function cleanupEmptyPhones(PDO $connection): int
    {
        $source = $this->esc(self::SOURCE);
        $sql = "DELETE FROM TblPhoneNumbers WHERE Source = '{$source}' AND Phone = ''";
        return $this->executeSqlServerWrite($connection, $sql, 'cleanup empty phones');
    }

    private function executeSqlServerWrite(PDO $connection, string $sql, string $operation): int
    {
        $affected = $connection->exec($sql);
        if ($affected === false) {
            throw new \RuntimeException("SQL Server {$operation} failed.");
        }
        return $affected;
    }

    protected function initializeSqlServerConnection(): PDO
    {
        // SMS eligibility reads sqlsrv; suppression writes must use that same connection.
        $connection = DB::connection('sqlsrv');
        if ($connection->getDriverName() !== 'sqlsrv') {
            throw new \RuntimeException('The SMS suppression target must be the sqlsrv connection.');
        }
        return $connection->getPdo();
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
