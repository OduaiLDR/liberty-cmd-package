<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\ContactPhoneSource;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsWorkflowLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SyncPhoneNumbers extends Command
{
    protected $signature = 'Sync:phone-numbers
        {--source=all : all, LDR, PLAW or LT}
        {--batch-size=1000 : Number of rows per INSERT (SQL Server maximum)}
        {--dry-run : Validate source totals without changing SQL Server}
        {--verify-target : With --dry-run, also read destination metadata and source counts}';

    protected $description = 'Sync contact-owned LDR, PLAW and LT phones with source, CID and contact creation date.';

    protected string $activeSource = 'DP_LT';

    /** Set once TblPhoneNumbers has the contact's creation date (the SMS prospects migration adds it). */
    protected bool $withCreatedDate = false;

    public function handle(): int
    {
        try {
            ContactPhoneSource::sources((string) $this->option('source'));
            if ($this->option('verify-target') && ! $this->option('dry-run')) {
                throw new \RuntimeException('--verify-target requires --dry-run.');
            }
        } catch (\RuntimeException $error) {
            $this->error($error->getMessage());

            return Command::FAILURE;
        }
        if ($this->option('dry-run')) {
            return $this->syncNow();
        }
        try {
            return SmsWorkflowLock::run('contact-sync', fn (): int => $this->syncNow());
        } catch (HttpException $error) {
            $this->warn($error->getMessage());

            return Command::FAILURE;
        }
    }

    protected function syncNow(): int
    {
        $snapshots = [];
        try {
            $sources = ContactPhoneSource::sources((string) $this->option('source'));
            $dryRun = (bool) $this->option('dry-run');
            if ($this->option('verify-target') && ! $dryRun) {
                throw new \RuntimeException('--verify-target requires --dry-run.');
            }
            $batchSize = min(max(1, (int) $this->option('batch-size')), 1000);
            $reader = new ContactPhoneSource;
            $this->info('[INFO] SyncPhoneNumbers: '.implode(', ', $sources).'.');
            $sqlServer = null;
            if (! $dryRun || $this->option('verify-target')) {
                $sqlServer = $this->initializeSqlServerConnection();
                if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasColumn('TblPhoneNumbers', 'Created_Date')) {
                    throw new \RuntimeException('TblPhoneNumbers.Created_Date is required. Apply the reviewed SMS migration before syncing; no rows were replaced.');
                }
                $this->withCreatedDate = true;
                if ($dryRun) {
                    $this->previewTarget($sqlServer);
                }
            }
            if ($dryRun) {
                $this->warn('[DRY RUN] SELECT-only validation; no replacement, cleanup, or prospect flagging.');
                foreach ($sources as $environment => $label) {
                    $this->activeSource = $label;
                    $summary = $reader->summary($this->snowflakeConnector($environment));
                    $this->line(json_encode(['source' => $label, 'source_rows' => $summary['SOURCE_ROWS'], 'contacts' => $summary['CONTACTS'], 'would_insert' => $summary['NORMALIZED_ROWS'], 'invalid_contact_ids' => $summary['INVALID_IDS'], 'missing_created' => $summary['MISSING_CREATED']], JSON_THROW_ON_ERROR));
                }

                return Command::SUCCESS;
            }
            // Every requested source must stage and validate before the first DELETE.
            foreach ($sources as $environment => $label) {
                $this->activeSource = $label;
                $snapshots[$environment] = $reader->stage($this->snowflakeConnector($environment));
                $this->info('[INFO] Validated '.$label.': '.$snapshots[$environment]['rows'].' phone rows.');
            }
            foreach ($snapshots as $snapshot) {
                if (! hash_equals($snapshot['sha256'], (string) hash_file('sha256', $snapshot['path']))) {
                    throw new \RuntimeException('Phone staging file changed; replacement refused.');
                }
            }
            foreach ($sources as $environment => $label) {
                $this->activeSource = $label;
                [$deleted, $inserted, $cleaned] = $this->replacePhonesFromFile($sqlServer, $snapshots[$environment]['path'], $batchSize, $snapshots[$environment]['rows']);
                $this->info("[INFO] {$label}: replaced {$deleted} rows with {$inserted}; cleaned {$cleaned}.");
                Log::info('SyncPhoneNumbers source committed.', ['source' => $label, 'deleted' => $deleted, 'inserted' => $inserted]);
            }
            $flagged = $this->flagContactedProspects();
            if ($flagged !== null) {
                $this->info("[INFO] Marked {$flagged} SMS prospects as contacted.");
            }
        } catch (\Throwable $e) {
            $this->error('SyncPhoneNumbers failed: '.$e->getMessage());
            Log::error('SyncPhoneNumbers: exception', ['source' => $this->activeSource, 'exception' => $e]);

            return Command::FAILURE;
        } finally {
            foreach ($snapshots as $snapshot) {
                if (is_file($snapshot['path'])) {
                    unlink($snapshot['path']);
                }
            }
        }

        $this->info('[SUCCESS] SyncPhoneNumbers completed successfully!');

        return Command::SUCCESS;
    }

    protected function snowflakeConnector(string $environment): DBConnector
    {
        return DBConnector::fromEnvironment($environment);
    }

    private function previewTarget(PDO $connection): void
    {
        $rows = $connection->query("SELECT Source,COUNT_BIG(*) AS existing_rows,SUM(CONVERT(bigint,CASE WHEN CID IS NULL OR LTRIM(RTRIM(CONVERT(nvarchar(100),CID)))='' THEN 1 ELSE 0 END)) AS missing_cid,SUM(CONVERT(bigint,CASE WHEN Created_Date IS NULL THEN 1 ELSE 0 END)) AS missing_created FROM dbo.TblPhoneNumbers GROUP BY Source ORDER BY Source")->fetchAll(PDO::FETCH_ASSOC);
        $this->line(json_encode(['destination_before' => $rows, 'legacy_DP' => 'Preserved. Use the separate reviewed backup/legacy-reconciliation script.'], JSON_THROW_ON_ERROR));
    }

    /** @return array{int, int, int} */
    protected function replacePhonesFromFile(PDO $connection, string $stagePath, int $batchSize, ?int $expectedRows = null): array
    {
        $durable = SmsAvailabilityRefresh::durableEnabled();
        if ($durable && (DB::connection('sqlsrv')->getPdo() !== $connection || DB::connection('sqlsrv')->transactionLevel() !== 0)) {
            throw new \LogicException('Native phone replacement must use the sqlsrv PDO outside Laravel transactions.');
        }
        $begun = $connection->beginTransaction();
        if (! $begun) {
            throw new \RuntimeException('SQL Server phone replacement transaction could not start.');
        }
        try {
            $deleted = $this->deleteExistingPhones($connection);
            $inserted = $this->insertPhonesFromFile($connection, $stagePath, $batchSize);
            if ($expectedRows !== null && $inserted !== $expectedRows) {
                throw new \RuntimeException('Staged phone row count differs; transaction rolled back.');
            }
            $cleaned = $this->cleanupEmptyPhones($connection);
            if ($this->withCreatedDate) {
                $source = $this->esc($this->activeSource);
                $actual = $connection->query("SELECT COUNT(*) FROM TblPhoneNumbers WHERE Source = '{$source}'")->fetchColumn();
                if ((int) $actual !== $inserted) {
                    throw new \RuntimeException('Phone replacement row count differs; transaction rolled back.');
                }
            }
            $changed = $deleted > 0 || $inserted > 0 || $cleaned > 0;
            if ($durable && $changed) {
                SmsAvailabilityRefresh::beforeNativeCommit(DB::connection('sqlsrv'), $connection);
            }
            $committed = $connection->commit();
            if (! $committed) {
                throw new \RuntimeException('SQL Server phone replacement commit failed.');
            }
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
        // Eligibility reads this table directly; request before later flagging can fail.
        // Keep postcommit dispatch outside the rollback handler.
        if ($durable) {
            if ($changed) {
                SmsAvailabilityRefresh::afterNativeCommit();
            }
        } else {
            SmsAvailabilityRefresh::afterCommit(DB::connection('sqlsrv'));
        }

        return [$deleted, $inserted, $cleaned];
    }

    private function deleteExistingPhones(PDO $connection): int
    {
        $source = $this->esc($this->activeSource);
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
            if (! feof($handle)) {
                throw new \RuntimeException('Unable to finish reading phone staging file.');
            }
        } finally {
            fclose($handle);
        }

        return $totalInserted;
    }

    private function insertPhoneBatch(PDO $connection, array $chunk, int $batchNumber): int
    {
        $sourceEsc = $this->esc($this->activeSource);
        $values = [];

        foreach ($chunk as $item) {
            $phoneEsc = $this->esc($item['phone']);
            $reader = new ContactPhoneSource;
            $cid = "'".$reader->contactId($item['cid'] ?? null)."'";
            if (! $this->withCreatedDate) {
                $values[] = "('{$phoneEsc}', '{$sourceEsc}', {$cid})";

                continue;
            }
            $validated = $reader->normalize(['ID' => $item['cid'] ?? null, 'CREATED_DATE' => $item['created'] ?? null, 'PHONE' => $item['phone'] ?? null]);
            if ($validated === null || $validated['phone'] !== $item['phone']) {
                throw new \RuntimeException('Invalid staged phone row; transaction rolled back.');
            }
            $created = "'{$validated['created']}'";
            $values[] = "('{$phoneEsc}', '{$sourceEsc}', {$cid}, {$created})";
        }

        $columns = $this->withCreatedDate ? 'Phone, Source, CID, Created_Date' : 'Phone, Source, CID';
        $sql = "INSERT INTO TblPhoneNumbers ({$columns}) VALUES ".implode(', ', $values);
        $affected = $this->executeSqlServerWrite($connection, $sql, 'insert phone batch '.$batchNumber);
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
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('TblProspects')) {
            return null;
        }

        return app(SmsProspectExportRepository::class)->markContacted();
    }

    private function cleanupEmptyPhones(PDO $connection): int
    {
        $source = $this->esc($this->activeSource);
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
