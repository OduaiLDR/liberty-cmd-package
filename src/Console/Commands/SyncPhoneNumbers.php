<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Repositories\SmsProspectExportRepository;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\SmsWorkflowLock;
use Cmd\Reports\Services\SmsAvailabilityRefresh;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsLegacyDataSourceMap;
use Cmd\Reports\Services\SmsPhoneSnapshotStage;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

class SyncPhoneNumbers extends Command
{
    protected $signature = 'Sync:phone-numbers
        {--source=lt : Company source: lt, ldr, or plaw (default keeps the existing LT schedule)}
        {--batch-size=1000 : Number of rows per INSERT (SQL Server maximum)}
        {--phones-only : Replace phone rows without updating prospect contact flags}
        {--dry-run : Read and normalize the source data without changing SQL Server}';

    protected $description = 'Sync company phone numbers from Snowflake CONTACTS into SQL Server TblPhoneNumbers.';

    private const SOURCE_LABELS = ['lt' => 'DP_LT', 'ldr' => 'DP_LDR', 'plaw' => 'DP_PLAW'];
    protected string $sourceEnvironment = 'lt';

    /** Set once TblPhoneNumbers has the contact's creation date (the SMS prospects migration adds it). */
    protected bool $withCreatedDate = false;
    /** Set at the native commit boundary, before any postcommit refresh can fail. */
    protected bool $phoneSnapshotCommitted = false;

    public function handle(): int
    {
        $this->phoneSnapshotCommitted = false;
        $source = $this->option('source');
        if (! is_string($source) || ! array_key_exists($source, self::SOURCE_LABELS)) {
            $this->error('Choose one company source: lt, ldr, or plaw. No synchronization was started.');
            return Command::FAILURE;
        }
        $this->sourceEnvironment = $source;
        return SmsDebtPleteImportPrivacy::run(function (): int {
            $environment = Env::getRepository();
            $debugWasSet = $environment->has('SNOWFLAKE_DEBUG');
            $previousDebug = $environment->get('SNOWFLAKE_DEBUG');
            try {
                $environment->set('SNOWFLAKE_DEBUG', 'false');
                if (env('SNOWFLAKE_DEBUG', false)) throw new \RuntimeException('Source debug could not be disabled.');
                if ($this->option('dry-run')) return $this->syncNow();
                try {
                    return SmsWorkflowLock::run('contact-sync', fn (): int => $this->syncNow());
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    $this->warn('Contact synchronization could not obtain its workflow lock. Retry after the active SMS operation completes.');
                    return Command::FAILURE;
                }
            } finally {
                // A long-lived worker must retain its prior environment, including an unset key.
                if ($debugWasSet) $environment->set('SNOWFLAKE_DEBUG', $previousDebug);
                else $environment->clear('SNOWFLAKE_DEBUG');
            }
        });
    }

    private function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->sourceEnvironment];
    }

    protected function createSnowflakeConnector(): DBConnector
    {
        return DBConnector::fromEnvironment($this->sourceEnvironment);
    }

    protected function syncNow(): int
    {
        if (! config('sms-exports.wide_phone_contacts_enabled', false)) {
            $this->error('Wide contact phone synchronization is not enabled. No source read or replacement was started.');
            return Command::FAILURE;
        }
        if (! $this->option('dry-run') && ! $this->option('phones-only')) {
            $this->error('Use --phones-only until the reviewed response and enrollment refresh is installed. No synchronization was started.');
            return Command::FAILURE;
        }
        $this->info('[INFO] SyncPhoneNumbers: starting.');
        Log::info('SyncPhoneNumbers command started.', ['source' => $this->sourceLabel()]);

        $batchSize = (int) $this->option('batch-size');
        // SQL Server permits at most 1,000 row constructors in one INSERT.
        $batchSize = min(max(1, $batchSize), 1000);

        $stagePath = null;
        $stage = null;
        $phase = 'connectors';

        try {
            $company = strtoupper($this->sourceEnvironment);
            $this->info("[DEBUG] Initializing {$company} Snowflake connector...");
            $snowflake = $this->createSnowflakeConnector();
            $this->info("[DEBUG] {$company} Snowflake connector OK.");

            $sqlServer = null;
            if ($this->option('dry-run')) {
                $this->warn('[DRY RUN] SQL Server connection and all writes are disabled.');
            } else {
                $this->info('[DEBUG] Initializing LDR SQL Server connection...');
                $sqlServer = $this->initializeSqlServerConnection();
                $this->info('[DEBUG] LDR SQL Server OK.');
            }
        } catch (\Throwable $e) {
            $this->reportFailure($e, $phase, false);
            return Command::FAILURE;
        }

        try {
            if ($this->option('dry-run')) {
                $phase = 'source_summary';
                $summary = $this->fetchDryRunSummary($snowflake);
                $sourceRows = (int) ($summary['SOURCE_ROWS'] ?? 0);
                $normalizedRows = (int) ($summary['NORMALIZED_ROWS'] ?? 0);
                $contacts = (int) ($summary['CONTACTS'] ?? 0);

                $this->info(sprintf(
                    '[DRY RUN] Source rows: %d; contacts: %d; non-empty rows before deduplication: %d; batches of %d.',
                    $sourceRows,
                    $contacts,
                    $normalizedRows,
                    $batchSize
                ));
                $this->warn('[DRY RUN] Counts only; CID/date completeness is validated during full source staging, not by this summary.');
                Log::info('SyncPhoneNumbers dry run finished.', [
                    'source' => $this->sourceLabel(),
                    'source_rows' => $sourceRows,
                    'normalized_rows' => $normalizedRows,
                    'contacts' => $contacts,
                ]);
                return Command::SUCCESS;
            }

            $phase = 'target_schema';
            $this->withCreatedDate = DB::connection('sqlsrv')->getSchemaBuilder()->hasColumn('TblPhoneNumbers', 'Created_Date');
            if (! $this->withCreatedDate) {
                throw new \RuntimeException('Phone synchronization requires the Created_Date target column.');
            }
            foreach (['Data_Source', 'External_ID', 'Status', 'Phone1', 'Phone2', 'Phone3', 'Phone4'] as $column) {
                if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasColumn('TblPhoneNumbers', $column)) {
                    throw new \RuntimeException('Phone synchronization requires the provenance target columns.');
                }
            }
            $this->assertProvenanceStorage();
            $phase = 'source_staging';
            [$stage, $stagePath, $sourceCount, $normalizedCount] = $this->stagePhonesFromSnowflake($snowflake);
            $this->info("[INFO] Fetched {$sourceCount} source phone slots from Snowflake.");
            $this->info("[INFO] Staged {$normalizedCount} company/contact rows with up to four unique phones.");

            if ($normalizedCount === 0) {
                $this->warn('[WARN] No phones to insert.');
                Log::info('SyncPhoneNumbers: no phones to insert.');
                return Command::SUCCESS;
            }

            $phase = 'phone_replacement';
            [$deleted, $inserted, $cleaned] = $this->replacePhonesFromFile($sqlServer, $stagePath, $batchSize);
            $this->info("[INFO] Replaced {$deleted} existing phone rows with {$inserted} rows.");
            $phase = 'contact_flags';
            $flagged = $this->option('phones-only') ? null : $this->flagContactedProspects();
            if ($flagged !== null) $this->info("[INFO] Marked {$flagged} SMS prospects as contacted.");
            Log::info('SyncPhoneNumbers command finished.', [
                'source' => $this->sourceLabel(),
                'deleted' => $deleted,
                'inserted' => $inserted,
                'cleaned' => $cleaned,
                'prospects_contacted' => $flagged,
            ]);
        } catch (\Throwable $e) {
            $this->reportFailure($e, $phase, $this->phoneSnapshotCommitted);
            return Command::FAILURE;
        } finally {
            $stage?->close();
        }

        $this->info('[SUCCESS] SyncPhoneNumbers completed successfully!');
        return Command::SUCCESS;
    }

    private function reportFailure(\Throwable $error, string $phase, bool $snapshotCommitted): void
    {
        $code = (string) $error->getCode();
        $this->error('SyncPhoneNumbers stopped during '.$phase.'. Phone snapshot committed: '.($snapshotCommitted ? 'yes' : 'no').'.');
        Log::error('SyncPhoneNumbers stopped.', ['source' => $this->sourceLabel(), 'phase' => $phase,
            'phone_snapshot_committed' => $snapshotCommitted, 'error_class' => get_class($error),
            'sqlstate' => preg_match('/\A[A-Z0-9]{5}\z/D', $code) ? $code : null]);
    }

    /** @return array{int, int, int} */
    protected function replacePhonesFromFile(PDO $connection, string $stagePath, int $batchSize): array
    {
        if (! config('sms-exports.wide_phone_contacts_enabled', false)) throw new \RuntimeException('Wide contact phone synchronization is not enabled.');
        $this->phoneSnapshotCommitted = false;
        if (! $this->withCreatedDate) {
            throw new \RuntimeException('Phone synchronization requires the Created_Date target column.');
        }
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
            $this->phoneSnapshotCommitted = $committed;
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
        $stage = new SmsPhoneSnapshotStage;
        $staged = false;
        try {
            do {
                $rows = $this->fetchPhonePage($snowflake, $pageSize, $offset);
                $sourceCount += count($rows);
                $normalized = $this->normalizePhones($rows);

                foreach ($normalized as $phone) {
                    $stage->add($this->sourceLabel(), $phone);
                }
                $offset += $pageSize;
            } while (count($rows) === $pageSize);
            $stagePath = $stage->export();
            $staged = true;
        } finally {
            if (! $staged) $stage->close();
        }
        return [$stage, $stagePath, $sourceCount, $stage->count()];
    }

    private function fetchPhonePage(DBConnector $snowflake, int $limit, int $offset): array
    {
        // Query only one page so DBConnector never has to hold the full result set.
        $sql = "
            WITH source_names AS (
                SELECT ID, MIN(NAME) AS NAME, COUNT(DISTINCT TO_BINARY(NAME, 'UTF-8')) AS NAME_VARIANTS
                FROM DATA_SOURCES GROUP BY ID
            ), latest_status AS (
                SELECT CONTACT_ID, STATUS_ID, STAMP,
                    ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY STAMP DESC, ID DESC) AS STATUS_RANK
                FROM CONTACTS_STATUS
                WHERE COALESCE(_FIVETRAN_DELETED, FALSE) = FALSE
            ), status_names AS (
                SELECT ID, MIN(TITLE) AS TITLE,
                    COUNT(DISTINCT TO_BINARY(TITLE, 'UTF-8')) AS TITLE_VARIANTS
                FROM CONTACTS_LEAD_STATUS
                WHERE COALESCE(_FIVETRAN_DELETED, FALSE) = FALSE
                GROUP BY ID
            )
            SELECT c.ID::VARCHAR AS ID, c.TP_ID::VARCHAR AS EXTERNAL_ID,
                c.C_SOURCE::VARCHAR AS DATA_SOURCE_ID,
                ds.NAME AS DATA_SOURCE, COALESCE(ds.NAME_VARIANTS, 0) AS DATA_SOURCE_VARIANTS,
                status_names.TITLE AS STATUS, COALESCE(status_names.TITLE_VARIANTS, 0) AS STATUS_VARIANTS,
                latest_status.STATUS_ID::VARCHAR AS STATUS_ID,
                phone_values.VALUE::STRING AS PHONE, phone_values.INDEX AS PHONE_SLOT,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.CREATED)::DATE, 'YYYY-MM-DD') AS CREATED_DATE
            FROM CONTACTS AS c
            LEFT JOIN source_names AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN latest_status ON latest_status.CONTACT_ID = c.ID AND latest_status.STATUS_RANK = 1
            LEFT JOIN status_names ON status_names.ID = latest_status.STATUS_ID,
                 LATERAL FLATTEN(
                     INPUT => ARRAY_CONSTRUCT(c.PHONE, c.PHONE2, c.PHONE3, c.PHONE4)
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
            $dataSourceVariants = $this->variantCount($row['DATA_SOURCE_VARIANTS'] ?? 0, 'Data Source');
            $statusVariants = $this->variantCount($row['STATUS_VARIANTS'] ?? 0, 'status');
            $status = $this->provenanceText($row['STATUS'] ?? null, 256);
            $statusId = $this->decimalIdentifier($row['STATUS_ID'] ?? null, 'status');
            if (($status === null) !== ($statusVariants === 0) || ($status !== null && $statusId === null)) {
                throw new \RuntimeException('Incomplete current status evidence; the phone snapshot was not replaced.');
            }
            $dataSource = SmsLegacyDataSourceMap::resolve(
                $this->sourceEnvironment,
                $this->decimalIdentifier($row['DATA_SOURCE_ID'] ?? null, 'Data Source'),
                $this->provenanceText($row['DATA_SOURCE'] ?? null, 50),
                $dataSourceVariants
            );
            // Keep historic padding/other lengths; canonicalize the already-supported leading-1 form only.
            $phone = str_pad($digits, 10, '0', STR_PAD_LEFT);
            if (strlen($phone) === 11 && $phone[0] === '1') $phone = substr($phone, 1);
            $normalized[] = [
                'phone' => $phone,
                'cid'   => $this->contactId($row['ID'] ?? null),
                'created' => $this->createdDate($row['CREATED_DATE'] ?? null),
                'data_source' => $dataSource,
                'external_id' => $this->provenanceText($row['EXTERNAL_ID'] ?? null, 256),
                'status' => $status,
                'slot' => $this->phoneSlot($row['PHONE_SLOT'] ?? null),
            ];
        }
        return $normalized;
    }

    private function provenanceText(mixed $value, int $limit): ?string
    {
        if ($value === null) return null;
        if (! is_string($value) || str_contains($value,"\0") || preg_match('//u', $value) !== 1
            || preg_match_all('/./us', $value) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value) > $limit) {
            throw new \RuntimeException('Invalid phone provenance text; the phone snapshot was not replaced.');
        }
        return $value;
    }

    private function variantCount(mixed $value, string $field): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[0-9]+\z/D', (string) $value) !== 1) {
            throw new \RuntimeException("Invalid {$field} variants; the phone snapshot was not replaced.");
        }
        $count = (int) $value;
        if ($count > 1) throw new \RuntimeException("Conflicting {$field} names; the phone snapshot was not replaced.");
        return $count;
    }

    private function decimalIdentifier(mixed $value, string $field): ?string
    {
        if ($value === null) return null;
        if ((! is_int($value) && ! is_string($value)) || preg_match('/\A[0-9]{1,38}\z/D', (string) $value) !== 1) {
            throw new \RuntimeException("Invalid {$field} ID; the phone snapshot was not replaced.");
        }
        return (string) $value;
    }

    private function phoneSlot(mixed $slot): int
    {
        if ((! is_int($slot) && ! is_string($slot)) || ! preg_match('/\A[0-3]\z/D',(string)$slot)) {
            throw new \RuntimeException('Invalid phone slot; the phone snapshot was not replaced.');
        }
        return (int)$slot;
    }

    private function assertProvenanceStorage(): void
    {
        $db = DB::connection('sqlsrv');
        if ($db->getDriverName() !== 'sqlsrv') return; // Synthetic local fixtures only.
        $columns = $db->select("SELECT c.name AS column_name,t.name AS type_name,c.max_length,c.is_nullable,c.collation_name
            FROM sys.columns c JOIN sys.types t ON t.user_type_id=c.user_type_id
            WHERE c.object_id=OBJECT_ID(N'TblPhoneNumbers') AND c.name IN (N'Phone',N'Data_Source',N'External_ID',
                N'Status',
                N'Phone1',N'Phone2',N'Phone3',N'Phone4')");
        $found = [];
        foreach ($columns as $column) $found[$column->column_name] = $column;
        $phoneCollation=$found['Phone']->collation_name??null;
        if(!is_string($phoneCollation)||$phoneCollation==='') throw new \RuntimeException('Wide phone synchronization requires the existing Phone collation.');
        foreach(range(1,4) as $slot) {
            if(($found['Phone'.$slot]->collation_name??null)!==$phoneCollation) throw new \RuntimeException('Wide phone synchronization requires matching phone collations.');
        }
        foreach (['Data_Source'=>100,'External_ID'=>512,'Status'=>512,
            'Phone1'=>100,'Phone2'=>100,'Phone3'=>100,'Phone4'=>100] as $name=>$bytes) {
            $column = $found[$name] ?? null;
            if (! $column || strtolower($column->type_name) !== 'nvarchar' || ! (bool) $column->is_nullable
                || ((int) $column->max_length !== -1 && (int) $column->max_length < $bytes)) {
                throw new \RuntimeException('Phone synchronization requires nullable Unicode provenance storage of sufficient length.');
            }
        }
        $indexes=$db->select("SELECT i.name,i.is_unique,i.has_filter,i.filter_definition,c.name AS column_name,ic.key_ordinal
            FROM sys.indexes i JOIN sys.index_columns ic ON i.object_id=ic.object_id AND i.index_id=ic.index_id
            JOIN sys.columns c ON c.object_id=ic.object_id AND c.column_id=ic.column_id
            WHERE i.object_id=OBJECT_ID(N'TblPhoneNumbers') AND i.is_disabled=0 AND i.is_hypothetical=0 AND ic.key_ordinal>0");
        $grouped=[]; foreach($indexes as $index) $grouped[$index->name][]=$index;
        foreach(['UX_TblPhoneNumbers_Wide_Source_CID'=>['Source','CID'],
            'IX_TblPhoneNumbers_Phone1'=>['Phone1'],'IX_TblPhoneNumbers_Phone2'=>['Phone2'],
            'IX_TblPhoneNumbers_Phone3'=>['Phone3'],'IX_TblPhoneNumbers_Phone4'=>['Phone4']] as $name=>$keys) {
            $rows=$grouped[$name]??[]; usort($rows,fn($a,$b)=>(int)$a->key_ordinal<=>(int)$b->key_ordinal);
            $first=$rows[0]??null; $filter=$first ? strtolower(preg_replace('/[\s\[\]()]/','',$first->filter_definition??'')) : '';
            $expected=str_starts_with($name,'UX_')?'phone1isnotnull':strtolower($keys[0]).'isnotnull';
            if(array_column($rows,'column_name')!==$keys || !$first->has_filter || $filter!==$expected
                || (str_starts_with($name,'UX_') && !$first->is_unique)) throw new \RuntimeException('Wide phone synchronization requires the reviewed contact and phone indexes.');
        }
    }

    /** Snowflake NUMBER(38,0) does not fit a PHP integer; retain its original decimal text. */
    private function contactId(mixed $id): string
    {
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/\A-?[0-9]{1,38}\z/D', (string) $id)) {
            throw new \RuntimeException('Unexpected contact ID format; the phone snapshot was not replaced.');
        }
        return (string) $id;
    }

    private function createdDate(mixed $date): string
    {
        if (! is_string($date) || ! preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $date, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \RuntimeException('Unexpected contact creation date; the phone snapshot was not replaced.');
        }
        return $date;
    }

    private function deleteExistingPhones(PDO $connection): int
    {
        $source = $this->esc($this->sourceLabel());
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

            if (! feof($handle)) throw new \RuntimeException('Unable to complete reading temporary phone staging.');

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
        $sourceEsc = $this->esc($this->sourceLabel());
        $values = [];

        foreach ($chunk as $item) {
            $phones = $item['phones'] ?? [$item['phone']??null,null,null,null];
            if (! is_array($phones) || count($phones)!==4 || !is_string($phones[0]) || $phones[0]===''
                || $phones[0]!==($item['phone']??null) || count(array_filter($phones,fn($p)=>$p!==null))!==count(array_unique(array_filter($phones,fn($p)=>$p!==null)))) {
                throw new \RuntimeException('Invalid wide contact phone staging.');
            }
            foreach($phones as $phone) if($phone!==null && (!is_string($phone)||!preg_match('/\A[0-9]{1,50}\z/D',$phone))) throw new \RuntimeException('Invalid wide contact phone staging.');
            $phoneEsc = $this->esc($phones[0]);
            $contactId = $this->contactId($item['cid'] ?? null);
            $cid = "'{$contactId}'";
            $created = "'".$this->createdDate($item['created'] ?? null)."'";
            $dataSource = $this->provenanceText($item['data_source'] ?? null, 50);
            $externalId = $this->provenanceText($item['external_id'] ?? null, 256);
            $metadata = [
                $dataSource,
                $externalId,
                $this->provenanceText($item['status'] ?? null, 256),
            ];
            $hasMetadata = array_filter($metadata, static fn (?string $value): bool => $value !== null) !== [];
            $unicode = $hasMetadata && DB::connection('sqlsrv')->getDriverName() === 'sqlsrv' ? 'N' : '';
            $metadataValues = array_map(fn (?string $value): string => $value === null ? 'NULL' : $unicode."'".$this->esc($value)."'", $metadata);
            $wideValues=implode(', ',array_map(fn($phone)=>$phone===null?'NULL':"'".$this->esc($phone)."'",$phones));
            $values[] = "('{$phoneEsc}', '{$sourceEsc}', {$cid}, {$created}, ".implode(', ', $metadataValues).", {$wideValues})";
        }

        $columns = 'Phone, Source, CID, Created_Date, Data_Source, External_ID, Status, Phone1, Phone2, Phone3, Phone4';
        $sql = "INSERT INTO TblPhoneNumbers ({$columns}) VALUES " . implode(', ', $values);
        $affected = $this->executeSqlServerWrite($connection, $sql, 'insert phone batch ' . $batchNumber);
        $inserted = $affected > 0 ? $affected : count($chunk);

        $this->info(sprintf('[INFO] Batch %d: inserted %d rows.', $batchNumber, $inserted));
        return $inserted;
    }

    /**
     * Mark matching SMS prospects as contacted using the repository's schema-compatible path.
     * This does not activate separate Contacted_Date attribution. Null until the table exists.
     */
    protected function flagContactedProspects(): ?int
    {
        if (! DB::connection('sqlsrv')->getSchemaBuilder()->hasTable('TblProspects')) return null;

        return app(SmsProspectExportRepository::class)->markContacted();
    }

    private function cleanupEmptyPhones(PDO $connection): int
    {
        $source = $this->esc($this->sourceLabel());
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
