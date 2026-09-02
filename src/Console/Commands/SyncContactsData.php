<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SyncContactsData extends Command
{
    protected $signature = 'Sync:contacts-data
        {--source=   : Run a single source only (LDR, PLAW, or LT)}
        {--full      : Force a full refresh even when a previous sync timestamp exists}
        {--dry-run   : Fetch and report changes without modifying SQL Server or sync watermarks; matching runs as read-only verification}
        {--verify-match : Read-only matching verification only (no Snowflake fetch, no SQL writes)}
        {--no-match  : Skip post-sync table matching (internal flag used by the orchestrator)}';

    protected $description = 'Sync contacts data from Snowflake to SQL Server (TblContactsLDR, TblContactsPLAW, and TblContactsLT)';

    private const PAGE_SIZE = 50000;
    private const MAX_LT_DEBT_AMOUNT = 999999;

    private string $source;
    private int $debtAmountCustomId;
    private int $agentCustomId;
    private string $targetTable;

    /** Matching-step counters reset at the start of each matching run. */
    private int $matchingStepsOk = 0;
    private int $matchingStepsFailed = 0;
    private int $matchingRowsAffected = 0;
    private int $matchingStepNumber = 0;
    private int $matchingStepTotal = 0;

    /** @var list<array{step: string, label: string, error: string}> */
    private array $matchingFailures = [];

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $source = strtoupper((string) $this->option('source'));
        if ($this->option('verify-match')) {
            return $this->runVerifyMatchOnly($source !== '' ? $source : null);
        }

        if ($source !== '') {
            if (!in_array($source, ['LDR', 'PLAW', 'LT'], true)) {
                $this->error("Unknown source '{$source}'. Use LDR, PLAW, or LT.");
                return Command::FAILURE;
            }
            return $this->syncForSource($source);
        }

        // LT holds the primary contact data (TblContacts). It must complete before
        // LDR/PLAW run, because the final matching step joins TblContactsLDR/PLAW
        // back to TblContacts — which only has correct data after LT finishes.

        $php = PHP_BINARY;
        if (str_contains(basename($php), 'fpm')) {
            $cli = trim((string) shell_exec('which php8.3 2>/dev/null || which php8.2 2>/dev/null || which php 2>/dev/null'));
            $php = $cli ?: 'php';
        }
        $artisan  = base_path('artisan');
        $fullFlag = $this->option('full') ? ['--full'] : [];
        $dryRunFlag = $this->option('dry-run') ? ['--dry-run'] : [];

        // ── Step 1: LT ────────────────────────────────────────────────────────
        $this->info("[INFO] Step 1/3: Syncing LT (primary contacts)...");
        $ltPool = Process::pool(function ($pool) use ($php, $artisan, $fullFlag, $dryRunFlag) {
            $pool->as('LT')->timeout(7200)->command(
                array_merge([$php, $artisan, 'Sync:contacts-data', '--source=LT', '--no-match'], $fullFlag, $dryRunFlag)
            );
        })->start(function (string $type, string $output, string $key) {
            foreach (explode("\n", rtrim($output)) as $line) {
                if ($line !== '') $this->line("[{$key}] {$line}");
            }
        });
        $ltProcesses = $ltPool->wait();

        if ($ltProcesses['LT']->exitCode() !== 0) {
            $this->info("\n" . str_repeat('=', 80));
            $this->error('[ERROR] LT failed — aborting LDR/PLAW to avoid incomplete matching.');
            return Command::FAILURE;
        }

        // ── Step 2: LDR + PLAW (parallel) ────────────────────────────────────
        $this->info("[INFO] Step 2/3: Syncing LDR and PLAW in parallel...");
        $pool = Process::pool(function ($pool) use ($php, $artisan, $fullFlag, $dryRunFlag) {
            foreach (['LDR', 'PLAW'] as $src) {
                $pool->as($src)->timeout(7200)->command(
                    array_merge([$php, $artisan, 'Sync:contacts-data', "--source={$src}", '--no-match'], $fullFlag, $dryRunFlag)
                );
            }
        })->start(function (string $type, string $output, string $key) {
            foreach (explode("\n", rtrim($output)) as $line) {
                if ($line !== '') $this->line("[{$key}] {$line}");
            }
        });
        $processes = $pool->wait();

        $allOk = true;
        foreach (['LDR', 'PLAW'] as $src) {
            if ($processes[$src]->exitCode() !== 0) {
                $this->error("[ERROR] {$src} failed (exit code {$processes[$src]->exitCode()}).");
                $allOk = false;
            }
        }
        if (!$allOk) {
            $this->info("\n" . str_repeat('=', 80));
            $this->error('[ERROR] LDR or PLAW failed — skipping final matching.');
            return Command::FAILURE;
        }

        // ── Step 3: Final matching (or read-only preview in dry-run) ─────────────
        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] Step 3/3: Previewing final matching (read-only)...');
            try {
                $connector = DBConnector::fromEnvironment('ldr');
                $connector->initializeSqlServer();
                $this->runFinalMatching($connector);
            } catch (\Throwable $e) {
                $this->error('[DRY RUN] Matching preview failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
            $this->info('[SUCCESS] Dry run completed; no SQL Server changes were made.');
            return Command::SUCCESS;
        }

        $this->info("[INFO] Step 3/3: Running final table matching...");
        try {
            $connector = DBConnector::fromEnvironment('ldr');
            $connector->initializeSqlServer();
            if (!$this->runFinalMatching($connector)) {
                $this->error('[ERROR] Final matching completed with failures — see summary above.');
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('[ERROR] Final matching failed: ' . $e->getMessage());
            Log::error('SyncContactsData: final matching exception', ['exception' => $e]);
            return Command::FAILURE;
        }

        $this->info("\n" . str_repeat('=', 80));
        $this->info('[SUCCESS] All syncs (LT → LDR, PLAW → matching) completed successfully!');
        return Command::SUCCESS;
    }

    private function syncForSource(string $source): int
    {
        $this->source = $source;
        $this->info("[INFO] Sync Contacts Data: starting for {$this->source}.");

        if ($this->source === 'PLAW') {
            $this->debtAmountCustomId = 743019;
            $this->agentCustomId      = 742153;
            $this->targetTable        = 'TblContactsPLAW';
        } elseif ($this->source === 'LT') {
            // LT's Loan Amount Needed field is a numeric string custom field.
            $this->debtAmountCustomId = 595171;
            $this->agentCustomId      = 0;        // agent comes from USERS join, not custom field
            $this->targetTable        = 'TblContacts';
        } else {
            $this->debtAmountCustomId = 745839;
            $this->agentCustomId      = 742152;
            $this->targetTable        = 'TblContactsLDR';
        }

        // Prevent two syncs of the SAME source from running concurrently. Overlapping
        // runs race each other's DELETE+INSERT and silently create duplicate rows
        // (this is how TblContacts accumulated its historical duplicate backlog).
        // The lock is DB-backed (CACHE_STORE=database), so it serializes across the
        // orchestrator's subprocess and any manual/scheduled run on this host. It
        // auto-expires after 2h so a crashed run can never wedge future syncs.
        $lock = Cache::lock("sync-contacts-data:{$this->source}", 7200);
        if (! $lock->get()) {
            $this->warn("[WARN] Another {$this->source} sync is already running; skipping this run to avoid duplicate rows.");
            Log::warning('SyncContactsData: overlapping run skipped', ['source' => $this->source]);
            return Command::SUCCESS;
        }

        try {
            return $this->runSourceSync();
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs the fetch → process → upsert loop for the already-selected source.
     * Split out from syncForSource() so the overlap lock there can wrap the whole
     * run in try/finally without re-indenting this body.
     */
    private function runSourceSync(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('[DRY RUN] No SQL Server writes or watermark updates will be performed.');
        }

        $this->info("[DEBUG] Initializing Snowflake connector...");
        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('SyncContactsData: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }
        $this->info("[DEBUG] Snowflake connector OK.");

        $this->info("[DEBUG] Initializing SQL Server connector...");
        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncContactsData: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }
        $this->info("[DEBUG] SQL Server connector OK.");

        // Determine sync mode.
        // Incremental: fetch only contacts modified since the last successful run,
        //   then DELETE+INSERT per chunk (no truncate — existing unchanged rows stay).
        // Full refresh: truncate the table and re-sync everything since 2021-07-01.
        $lastSyncAt    = $this->option('full') ? null : $this->readLastSyncTime($this->source);
        $isIncremental = $lastSyncAt !== null;

        if ($isIncremental) {
            // Subtract 24 hours as a safety buffer against clock skew / in-flight writes
            // and any latent timezone-conversion edge cases in the Snowflake date filter.
            // insertChunk() is an idempotent DELETE+INSERT, so re-pulling a day of overlap is safe.
            $startDate = date('Y-m-d H:i:s', strtotime($lastSyncAt) - 86400);
            $this->info("[INFO] Incremental mode: fetching contacts modified since {$startDate}.");
        } else {
            $startDate = '2021-07-01';
            $this->info('[INFO] Full refresh mode.' . ($dryRun ? ' Target table will not be truncated.' : ''));
            if (!$dryRun) {
                $this->clearTargetTable($sqlConnector);
            }
        }

        // Record the sync start time before any data is fetched.
        // This timestamp is written to the file only after the entire run succeeds,
        // ensuring a failed/partial run never advances the watermark.
        $syncStartedAt = date('Y-m-d H:i:s');

        $lastId           = 0;
        $seenTpIds        = [];
        $categoryChanges  = [];
        $affiliateChanges = [];
        $totalFetched     = 0;
        $totalInserted    = 0;

        do {
            $chunk     = $this->fetchContactsPage($snowflake, $startDate, $lastId, self::PAGE_SIZE);
            $chunkSize = \count($chunk);

            if ($chunkSize === 0) {
                break;
            }

            $totalFetched += $chunkSize;
            $lastId        = (int) end($chunk)['LLG_ID'];

            $enrollmentData = $this->loadEnrollmentDataFiltered($sqlConnector, $chunk);
            $dropNames      = $this->fetchDropNamesFiltered($sqlConnector, $chunk);

            [$processedChunk, $newCatChanges, $newAffChanges] = $this->processChunk(
                $chunk,
                $dropNames,
                $enrollmentData,
                $seenTpIds
            );

            foreach ($newCatChanges as $c) {
                $categoryChanges[] = $c;
            }
            foreach ($newAffChanges as $c) {
                $affiliateChanges[] = $c;
            }

            if ($dryRun) {
                $totalInserted += count($processedChunk);
            } else {
                try {
                    $totalInserted += $this->insertChunk($sqlConnector, $processedChunk, $isIncremental);
                } catch (\Throwable $e) {
                    $this->error("[ERROR] Insert failed on chunk ending at ID {$lastId}: " . $e->getMessage());
                    Log::error('SyncContactsData: chunk insert failed', [
                        'source'  => $this->source,
                        'last_id' => $lastId,
                        'error'   => $e->getMessage(),
                    ]);
                    return Command::FAILURE;
                }
            }

            unset($chunk, $enrollmentData, $dropNames, $processedChunk, $newCatChanges, $newAffChanges);
            \gc_collect_cycles();

            $this->info("[INFO] Progress: {$totalFetched} fetched, {$totalInserted} upserted...");
        } while ($chunkSize === self::PAGE_SIZE);

        $this->info("[INFO] Completed: {$totalInserted} records upserted into {$this->targetTable}.");
        $this->info("[INFO] Enrollment updates: " . \count($categoryChanges) . " category, " . \count($affiliateChanges) . " affiliate agent");

        if (!$dryRun) {
            $this->applyEnrollmentCategoryUpdates($sqlConnector, $categoryChanges);
            $this->applyEnrollmentAffiliateUpdates($sqlConnector, $affiliateChanges);
        }

        // When called from the orchestrator (no --source flag), matching is deferred
        // to handle() so it runs after ALL sources finish. Skip it here in that case.
        if (!$this->option('no-match')) {
            if ($dryRun) {
                $this->warn('[DRY RUN] Previewing post-sync matching (read-only)...');
            }
            if (!$this->updateRelatedTables($sqlConnector)) {
                $message = $dryRun
                    ? "[DRY RUN] {$this->source} matching preview failed."
                    : "[ERROR] {$this->source} post-sync matching completed with failures.";
                $this->error($message);
                return Command::FAILURE;
            }
        }

        // Persist the watermark only after a fully successful run
        if (!$dryRun) {
            $this->writeLastSyncTime($this->source, $syncStartedAt);
            $this->info("[INFO] Sync watermark saved: {$syncStartedAt}");
        }

        $this->info("[SUCCESS] {$this->source} sync completed successfully!");
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Data fetching
    // -------------------------------------------------------------------------

    /**
     * Fetches one page of contacts from Snowflake using cursor-based pagination on c.ID.
     * Cursor pagination (WHERE c.ID > $lastId) is faster than OFFSET because Snowflake
     * can filter early on the indexed ID column instead of scanning and discarding rows.
     */
    private function fetchContactsPage(
        DBConnector $snowflake,
        string $startDate,
        int $lastId,
        int $limit
    ): array {
        $sql = $this->source === 'LT'
            ? $this->buildLTQuery($startDate, $lastId, $limit)
            : $this->buildStandardQuery($startDate, $lastId, $limit);

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function buildStandardQuery(string $startDate, int $lastId, int $limit): string
    {
        return "
            SELECT
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.CREATED), 'YYYY-MM-DD HH24:MI:SS') AS CREATED,
                NULL AS ASSIGNED_ON,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, c.CREATED)), 'YYYY-MM-DD HH24:MI:SS') AS MODIFIED,
                c.ID AS LLG_ID,
                c.TP_ID AS EXTERNAL_ID,
                ds.NAME AS DATA_SOURCE,
                NULL AS CREATED_BY,
                NULL AS ASSIGNED_TO,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS FULLNAME,
                c.PHONE3 AS CELL_PHONE,
                c.EMAIL,
                c.ADDRESS AS ADDRESS1,
                c.ADDRESS2,
                c.CITY,
                c.STATE,
                c.ZIP,
                cc.TITLE AS STAGE,
                cls.TITLE AS STATUS,
                NULL AS LOAN_AMOUNT_NEEDED,
                cs.TRANSUNION AS CREDIT_SCORE,
                SUBSTRING(cr.METADATA, CHARINDEX('RevolvingCreditUtilization', cr.METADATA) + 29,
                    CHARINDEX('Day30', cr.METADATA) - CHARINDEX('RevolvingCreditUtilization', cr.METADATA) - 32) AS CREDIT_UTILIZATION,
                ep.FEE1,
                c.TP_ID AS TP_ID_COPY,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE), 'YYYY-MM-DD HH24:MI:SS') AS ENROLLED_DATE,
                uf_debt.F_DECIMAL AS DEBT_AMOUNT_CUSTOM,
                ed.TITLE AS PLAN_TITLE,
                uf_agent.F_SHORTSTRING AS AGENT_CUSTOM
            FROM CONTACTS AS c
            LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN CONTACTS_STATUS AS s ON c.ID = s.CONTACT_ID
            LEFT JOIN CONTACTS_CATEGORIES AS cc ON s.STAGE_ID = cc.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
            LEFT JOIN CREDIT_SCORES AS cs ON c.ID = cs.CONTACT_ID
            LEFT JOIN CREDIT_REPORT_REQUEST AS cr ON c.ID = cr.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_DECIMAL
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = {$this->debtAmountCustomId}
            ) AS uf_debt ON c.ID = uf_debt.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_SHORTSTRING
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = {$this->agentCustomId}
            ) AS uf_agent ON c.ID = uf_agent.CONTACT_ID
            WHERE CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, c.CREATED)) >= '{$this->esc($startDate)}'::TIMESTAMP_NTZ
              AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> ''
              AND ISCOAPP = 0
              AND c.ID > {$lastId}
            QUALIFY ROW_NUMBER() OVER(PARTITION BY c.ID ORDER BY s.STAMP DESC) = 1
            ORDER BY c.ID
            LIMIT {$limit}
        ";
    }

    private function buildLTQuery(string $startDate, int $lastId, int $limit): string
    {
        // LT uses real ASSIGNED_ON/CREATED_BY/ASSIGNED_TO from joined tables.
        // Agent = ASSIGNED_TO (u2), no custom-field agent lookup.
        // Date filter includes a.STAMP. Duplicate leads filtered in Snowflake.
        return "
            SELECT
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.CREATED), 'YYYY-MM-DD HH24:MI:SS') AS CREATED,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', a.STAMP), 'YYYY-MM-DD HH24:MI:SS') AS ASSIGNED_ON,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, a.STAMP)), 'YYYY-MM-DD HH24:MI:SS') AS MODIFIED,
                c.ID AS LLG_ID,
                c.TP_ID AS EXTERNAL_ID,
                ds.NAME AS DATA_SOURCE,
                CONCAT(u1.FIRSTNAME, ' ', u1.LASTNAME) AS CREATED_BY,
                CONCAT(u2.FIRSTNAME, ' ', u2.LASTNAME) AS ASSIGNED_TO,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS FULLNAME,
                c.PHONE3 AS CELL_PHONE,
                c.EMAIL,
                c.ADDRESS AS ADDRESS1,
                c.ADDRESS2,
                c.CITY,
                c.STATE,
                c.ZIP,
                cc.TITLE AS STAGE,
                cls.TITLE AS STATUS,
                NULL AS LOAN_AMOUNT_NEEDED,
                cs.TRANSUNION AS CREDIT_SCORE,
                SUBSTRING(cr.METADATA, CHARINDEX('RevolvingCreditUtilization', cr.METADATA) + 29,
                    CHARINDEX('Day30', cr.METADATA) - CHARINDEX('RevolvingCreditUtilization', cr.METADATA) - 32) AS CREDIT_UTILIZATION,
                ep.FEE1,
                c.TP_ID AS TP_ID_COPY,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE), 'YYYY-MM-DD HH24:MI:SS') AS ENROLLED_DATE,
                uf_debt.F_SHORTSTRING AS DEBT_AMOUNT_CUSTOM,
                NULL AS PLAN_TITLE,
                NULL AS AGENT_CUSTOM
            FROM CONTACTS AS c
            LEFT JOIN CONTACTS_ASSIGNED AS a ON c.ID = a.CONTACT_ID
            LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN USERS AS u1 ON c.CREATED_BY = u1.UID
            LEFT JOIN USERS AS u2 ON c.ASSIGNED_TO = u2.UID
            LEFT JOIN CONTACTS_STATUS AS s ON c.ID = s.CONTACT_ID
            LEFT JOIN CONTACTS_CATEGORIES AS cc ON s.STAGE_ID = cc.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
            LEFT JOIN CREDIT_SCORES AS cs ON c.ID = cs.CONTACT_ID
            LEFT JOIN CREDIT_REPORT_REQUEST AS cr ON c.ID = cr.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_SHORTSTRING
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = {$this->debtAmountCustomId}
            ) AS uf_debt ON c.ID = uf_debt.CONTACT_ID
            WHERE CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, a.STAMP, c.CREATED)) >= '{$this->esc($startDate)}'::TIMESTAMP_NTZ
              AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> ''
              AND ISCOAPP = 0
              AND cls.TITLE <> 'Duplicate Lead'
              AND c.ID > {$lastId}
            QUALIFY ROW_NUMBER() OVER(PARTITION BY c.ID ORDER BY s.STAMP DESC) = 1
            ORDER BY c.ID
            LIMIT {$limit}
        ";
    }

    /**
     * Loads enrollment data scoped to enrolled contacts in this chunk.
     * A temp table passes the IDs to SQL Server, avoiding a full TblEnrollment table scan.
     */
    private function loadEnrollmentDataFiltered(DBConnector $connector, array $chunk): array
    {
        $empty = ['categories' => [], 'assigned_agents' => [], 'affiliate_agents' => []];

        $enrolledIds = [];
        foreach ($chunk as $row) {
            if (!empty($row['ENROLLED_DATE'])) {
                $id = (string) ($row['LLG_ID'] ?? '');
                if ($id !== '') {
                    $enrolledIds[] = $id;
                }
            }
        }

        if (empty($enrolledIds)) {
            return $empty;
        }

        $connector->querySqlServer("CREATE TABLE #TmpEnrollFilter (ContactId VARCHAR(20))");
        foreach (\array_chunk($enrolledIds, 1000) as $batch) {
            $values = \implode(', ', \array_map(
                fn($id) => "('" . \str_replace("'", "''", $id) . "')",
                $batch
            ));
            $connector->querySqlServer("INSERT INTO #TmpEnrollFilter VALUES {$values}");
        }

        $result = $connector->querySqlServer("
            SELECT e.LLG_ID, e.Category, e.Agent, e.Affiliate_Agent
            FROM TblEnrollment e
            JOIN #TmpEnrollFilter f ON e.LLG_ID = 'LLG-' + f.ContactId
            WHERE e.Category NOT IN ('', 'FDR', 'CSS', 'CNI')
        ");
        $connector->querySqlServer("DROP TABLE #TmpEnrollFilter");

        $categories      = [];
        $assignedAgents  = [];
        $affiliateAgents = [];

        foreach ($result['data'] ?? [] as $row) {
            $llgId = $row['LLG_ID'] ?? '';
            if (preg_match('/LLG-(\d+)/', $llgId, $matches)) {
                $contactId                   = $matches[1];
                $categories[$contactId]      = $row['Category'] ?? '';
                $assignedAgents[$contactId]  = $row['Agent'] ?? '';
                $affiliateAgents[$contactId] = $row['Affiliate_Agent'] ?? '';
            }
        }

        return [
            'categories'       => $categories,
            'assigned_agents'  => $assignedAgents,
            'affiliate_agents' => $affiliateAgents,
        ];
    }

    /**
     * Fetches drop names only for the External_IDs present in this chunk.
     * Uses a SQL Server temp table to avoid loading all of TblMailers.
     * Preserves fallback matching on the last 9 characters of External_ID.
     */
    private function fetchDropNamesFiltered(DBConnector $connector, array $chunk): array
    {
        $externalIds = [];
        foreach ($chunk as $row) {
            $tpId = \trim((string) ($row['EXTERNAL_ID'] ?? ''));
            if ($tpId !== '') {
                $externalIds[$tpId] = true;
            }
        }

        if (empty($externalIds)) {
            return [];
        }

        $externalIds = \array_keys($externalIds);

        $connector->querySqlServer("CREATE TABLE #TmpMailerFilter (ExtId VARCHAR(50))");
        foreach (\array_chunk($externalIds, 1000) as $batch) {
            $values = \implode(', ', \array_map(
                fn($id) => "('" . \str_replace("'", "''", \substr($id, 0, 50)) . "')",
                $batch
            ));
            $connector->querySqlServer("INSERT INTO #TmpMailerFilter VALUES {$values}");
        }

        $result = $connector->querySqlServer("
            SELECT m.External_ID, m.Drop_Name
            FROM TblMailers m
            WHERE m.External_ID IS NOT NULL
              AND m.Drop_Name IS NOT NULL
              AND (
                  m.External_ID IN (SELECT ExtId FROM #TmpMailerFilter)
                  OR (LEN(m.External_ID) > 9 AND RIGHT(m.External_ID, 9) IN (
                      SELECT RIGHT(ExtId, 9) FROM #TmpMailerFilter WHERE LEN(ExtId) > 9
                  ))
              )
        ");
        $connector->querySqlServer("DROP TABLE #TmpMailerFilter");

        $lookup = [];
        foreach ($result['data'] ?? [] as $row) {
            $externalId = $row['External_ID'] ?? '';
            $dropName   = $row['Drop_Name'] ?? '';
            if ($externalId && $dropName) {
                $lookup[$externalId] = $dropName;
                if (\strlen($externalId) > 9) {
                    $last9 = \substr($externalId, -9);
                    if (!isset($lookup[$last9])) {
                        $lookup[$last9] = $dropName;
                    }
                }
            }
        }

        return $lookup;
    }

    // -------------------------------------------------------------------------
    // Processing
    // -------------------------------------------------------------------------

    /**
     * Processes one chunk of Snowflake rows.
     * $seenTpIds is passed by reference so TP_ID deduplication persists across chunks.
     *
     * @param  array  $seenTpIds  Mutable dedup map — survives across all chunks in a run.
     * @return array{0: array, 1: array, 2: array}  [processedRows, categoryChanges, affiliateChanges]
     */
    private function processChunk(
        array $chunk,
        array $dropNames,
        array $enrollmentData,
        array &$seenTpIds
    ): array {
        $processed        = [];
        $categoryChanges  = [];
        $affiliateChanges = [];

        $existingCategories = $enrollmentData['categories'];
        $existingAffiliates = $enrollmentData['affiliate_agents'];

        // Ghost Snowflake records that don't exist in LT CRM — excluded permanently
        // because they share a TP_ID with valid contacts and win the dedup due to lower IDs.
        $ghostIds = [1212313502, 1212314964, 1212315478, 1212329195, 1212342404];

        foreach ($chunk as $row) {
            $contactId = $row['LLG_ID'] ?? '';
            $tpId      = $row['EXTERNAL_ID'] ?? '';

            if (in_array((int) $contactId, $ghostIds, true)) {
                continue;
            }
            if (($row['STATUS'] ?? '') === 'Duplicate Lead') {
                continue;
            }
            if ($tpId && isset($seenTpIds[$tpId])) {
                continue;
            }
            if ($tpId) {
                $seenTpIds[$tpId] = true;
            }

            $debtAmountRaw = $row['DEBT_AMOUNT_CUSTOM'] ?? 0;
            $debtAmount = is_numeric($debtAmountRaw) ? (float) $debtAmountRaw : 0.0;
            if ($this->source === 'LT' && ($debtAmount <= 0 || $debtAmount > self::MAX_LT_DEBT_AMOUNT)) {
                Log::warning('SyncContactsData: ignored invalid LT loan amount', [
                    'contact_id' => $contactId,
                    'value'      => $debtAmountRaw,
                ]);
                $debtAmount = 0.0;
            }
            $planTitle  = $row['PLAN_TITLE'] ?? '';
            $category   = $this->normalizePlanTitle($planTitle);
            // LT: agent is the assigned user from USERS join; LDR/PLAW: from custom field
            $agent      = $this->source === 'LT'
                ? ($row['ASSIGNED_TO'] ?? '')
                : ($row['AGENT_CUSTOM'] ?? '');
            $creditUtil = $this->parseCreditUtilization($row['CREDIT_UTILIZATION'] ?? '');

            $campaign = '';
            if ($tpId) {
                $campaign = $dropNames[$tpId] ?? ($dropNames[\substr($tpId, -9)] ?? '');
            }

            $processedRow = [
                'created_date'       => $this->formatDate($row['CREATED'] ?? null),
                'assigned_date'      => $this->formatDate($row['ASSIGNED_ON'] ?? null),
                'llg_id'             => 'LLG-' . $contactId,
                'external_id'        => \substr($tpId, 0, 50),
                'campaign'           => \substr($campaign, 0, 255),
                'data_source'        => \substr($row['DATA_SOURCE'] ?? '', 0, 255),
                'created_by'         => \substr($row['CREATED_BY'] ?? '', 0, 255),
                'agent'              => \substr($agent, 0, 255),
                'client'             => \substr($row['FULLNAME'] ?? '', 0, 255),
                'phone'              => \substr($this->cleanPhone($row['CELL_PHONE'] ?? ''), 0, 50),
                'email'              => $row['EMAIL'] ?? '',
                'address_1'          => \substr($row['ADDRESS1'] ?? '', 0, 255),
                'address_2'          => \substr($row['ADDRESS2'] ?? '', 0, 255),
                'city'               => \substr($row['CITY'] ?? '', 0, 100),
                'state'              => \substr($row['STATE'] ?? '', 0, 20),
                'zip'                => \substr($row['ZIP'] ?? '', 0, 20),
                'stage'              => $row['STAGE'] ?? '',
                'status'             => $row['STATUS'] ?? '',
                'debt_amount'        => \floor($debtAmount / 1000) * 1000,
                'debt_enrolled'      => $debtAmount,
                'credit_score'       => $row['CREDIT_SCORE'] ?? 0,
                'credit_utilization' => $creditUtil,
                'category'           => $category,
                'affiliate_agent'    => \substr($agent, 0, 255),
            ];

            // LT inserts into TblContacts which has no TP_ID column
            if ($this->source !== 'LT') {
                $processedRow['tp_id'] = \substr($tpId, 0, 50);
            }

            $processed[] = $processedRow;

            // Enrollment change detection in the same pass
            $enrolledDate = $row['ENROLLED_DATE'] ?? '';
            if (!empty($enrolledDate) && isset($existingCategories[$contactId]) && $category !== '') {
                $llgId = "LLG-{$contactId}";
                if ($existingCategories[$contactId] !== $category) {
                    $categoryChanges[] = ['llg_id' => $llgId, 'category' => $category];
                }
                if ($category !== 'LDR') {
                    $existingAffiliate = $existingAffiliates[$contactId] ?? '';
                    if ($agent !== '' && $existingAffiliate !== $agent && !\str_ends_with(\strtolower($agent), ' user')) {
                        $affiliateChanges[] = ['llg_id' => $llgId, 'agent' => $agent];
                    }
                }
            }
        }

        return [$processed, $categoryChanges, $affiliateChanges];
    }

    // -------------------------------------------------------------------------
    // Insertion
    // -------------------------------------------------------------------------

    /**
     * Inserts one processed chunk and returns the number of rows upserted.
     * Throws on any failure so the caller can abort the run immediately.
     *
     * Full refresh mode:  plain INSERT (table was already truncated).
     * Incremental mode:   DELETE matching LLG_IDs first, then INSERT — this
     *                     handles both updated existing contacts and brand-new ones
     *                     without needing a full-table MERGE statement.
     */
    private function insertChunk(DBConnector $connector, array $data, bool $incremental = false): int
    {
        if (empty($data)) {
            return 0;
        }

        // Deduplicate by LLG_ID within this chunk: keep the row with the most recent
        // assigned_date and prefer rows with a non-empty agent. Snowflake can return the
        // same contact multiple times (e.g. from multiple mailer campaigns) and inserting
        // all copies produces duplicate rows that break agent lookups.
        $deduped = [];
        foreach ($data as $row) {
            $llgId = $row['llg_id'] ?? '';
            if ($llgId === '') {
                $deduped[] = $row;
                continue;
            }
            if (!isset($deduped[$llgId])) {
                $deduped[$llgId] = $row;
                continue;
            }
            $existing = $deduped[$llgId];
            $existingAgent    = $existing['agent'] ?? '';
            $rowAgent         = $row['agent'] ?? '';
            $existingAssigned = $existing['assigned_date'] ?? '';
            $rowAssigned      = $row['assigned_date'] ?? '';
            // Prefer non-empty agent; break ties by latest assigned_date
            $rowBetter = ($existingAgent === '' && $rowAgent !== '')
                || ($existingAgent === $rowAgent && $rowAssigned > $existingAssigned);
            if ($rowBetter) {
                $deduped[$llgId] = $row;
            }
        }
        $data = \array_values($deduped);

        // TblContacts (LT) has 24 columns — no TP_ID. TblContactsLDR/PLAW have 25.
        $fields = 'Created_Date, Assigned_Date, LLG_ID, External_ID, Campaign, Data_Source, '
            . 'Created_By, Agent, Client, Phone, Email, Address_1, Address_2, City, State, '
            . 'Zip, Stage, Status, Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, '
            . 'Category, Affiliate_Agent'
            . ($this->source !== 'LT' ? ', TP_ID' : '');

        $pdo = $connector->getSqlServerConnection();
        $pdo->beginTransaction();

        try {
            // Incremental: remove stale rows for every LLG_ID we are about to re-insert
            if ($incremental) {
                foreach (\array_chunk($data, 1000) as $deleteBatch) {
                    $ids = \implode(', ', \array_map(
                        fn($row) => "'" . $this->escSql($row['llg_id']) . "'",
                        $deleteBatch
                    ));
                    $sql = "DELETE FROM {$this->targetTable} WHERE LLG_ID IN ({$ids})";
                    if ($pdo->exec($sql) === false) {
                        $err = $pdo->errorInfo();
                        throw new \RuntimeException('DELETE batch failed: ' . ($err[2] ?? 'unknown PDO error'));
                    }
                }
            }

            // SQL Server hard-limits INSERT ... VALUES to 1 000 rows per statement
            foreach (\array_chunk($data, 1000) as $batch) {
                $valuesParts = [];

                foreach ($batch as $row) {
                    $createdDate  = $row['created_date'] ? "'{$row['created_date']}'" : 'NULL';
                    $assignedDate = $row['assigned_date'] ? "'{$row['assigned_date']}'" : 'NULL';
                    $email        = \strpos($row['email'], '@') !== false
                        ? "'" . $this->escSql($row['email']) . "'"
                        : 'NULL';

                    $valuesParts[] = "({$createdDate}, {$assignedDate}, "
                        . "'{$this->escSql($row['llg_id'])}', "
                        . "'{$this->escSql($row['external_id'])}', "
                        . "'{$this->escSql($row['campaign'])}', "
                        . "'{$this->escSql($row['data_source'])}', "
                        . "'{$this->escSql($row['created_by'])}', "
                        . "'{$this->escSql($row['agent'])}', "
                        . "'{$this->escSql($row['client'])}', "
                        . "'{$this->escSql($row['phone'])}', "
                        . "{$email}, "
                        . "'{$this->escSql($row['address_1'])}', "
                        . "'{$this->escSql($row['address_2'])}', "
                        . "'{$this->escSql($row['city'])}', "
                        . "'{$this->escSql($row['state'])}', "
                        . "'{$this->escSql($row['zip'])}', "
                        . "'{$this->escSql($row['stage'])}', "
                        . "'{$this->escSql($row['status'])}', "
                        . ((int) $row['debt_amount']) . ", "
                        . ((float) $row['debt_enrolled']) . ", "
                        . ((int) $row['credit_score']) . ", "
                        . ((int) $row['credit_utilization']) . ", "
                        . "'{$this->escSql($row['category'])}', "
                        . "'{$this->escSql($row['affiliate_agent'])}'"
                        . ($this->source !== 'LT' ? ", '{$this->escSql($row['tp_id'])}'" : '')
                        . ')';
                }

                $sql = "INSERT INTO {$this->targetTable} ({$fields}) VALUES " . \implode(', ', $valuesParts);

                if ($pdo->exec($sql) === false) {
                    $err = $pdo->errorInfo();
                    throw new \RuntimeException('INSERT batch failed: ' . ($err[2] ?? 'unknown PDO error'));
                }
            }

            $pdo->commit();
            return \count($data);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Enrollment updates
    // -------------------------------------------------------------------------

    private function applyEnrollmentCategoryUpdates(DBConnector $connector, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $connector->querySqlServer("CREATE TABLE #TmpCatUpd (LLG_ID VARCHAR(50), NewCat VARCHAR(50))");

        foreach (\array_chunk($changes, 500) as $chunk) {
            $values = \implode(', ', \array_map(
                fn($c) => "('{$this->escSql($c['llg_id'])}', '{$this->escSql($c['category'])}')",
                $chunk
            ));
            $connector->querySqlServer("INSERT INTO #TmpCatUpd (LLG_ID, NewCat) VALUES {$values}");
        }

        $connector->querySqlServer("
            UPDATE TblEnrollment
            SET Category = u.NewCat
            FROM TblEnrollment e
            JOIN #TmpCatUpd u ON e.LLG_ID = u.LLG_ID
            WHERE e.Category <> u.NewCat OR e.Category IS NULL
        ");

        $connector->querySqlServer("DROP TABLE #TmpCatUpd");
    }

    private function applyEnrollmentAffiliateUpdates(DBConnector $connector, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $connector->querySqlServer("CREATE TABLE #TmpAffUpd (LLG_ID VARCHAR(50), NewAffiliate NVARCHAR(100))");

        foreach (\array_chunk($changes, 500) as $chunk) {
            $values = \implode(', ', \array_map(
                fn($c) => "('{$this->escSql($c['llg_id'])}', '{$this->escSql($c['agent'])}')",
                $chunk
            ));
            $connector->querySqlServer("INSERT INTO #TmpAffUpd (LLG_ID, NewAffiliate) VALUES {$values}");
        }

        $connector->querySqlServer("
            UPDATE TblEnrollment
            SET Affiliate_Agent = u.NewAffiliate
            FROM TblEnrollment e
            JOIN #TmpAffUpd u ON e.LLG_ID = u.LLG_ID
            WHERE (e.Affiliate_Agent <> u.NewAffiliate OR e.Affiliate_Agent IS NULL)
              AND u.NewAffiliate <> ''
        ");

        $connector->querySqlServer("DROP TABLE #TmpAffUpd");
    }

    // -------------------------------------------------------------------------
    // Table maintenance
    // -------------------------------------------------------------------------

    private function clearTargetTable(DBConnector $connector): void
    {
        $truncateResult = $connector->querySqlServer("TRUNCATE TABLE {$this->targetTable}");
        if ($truncateResult['success'] ?? false) {
            $this->info("[INFO] Target table truncated instantly.");
            return;
        }
        $this->info("[INFO] TRUNCATE failed ({$truncateResult['error']}), falling back to batch DELETE.");

        do {
            $connector->querySqlServer("DELETE TOP (50000) FROM {$this->targetTable}");
            $result = $connector->querySqlServer("SELECT COUNT(*) AS cnt FROM {$this->targetTable}");
            $count  = $result['data'][0]['cnt'] ?? 0;
            if ($count > 0) {
                $this->info("[INFO] Deleted batch, {$count} rows remaining...");
            }
        } while ($count > 0);

        $this->info("[INFO] Target table cleared");
    }

    private function updateRelatedTables(DBConnector $connector): bool
    {
        if ($this->source === 'LT') {
            return true;
        }

        if ($this->option('dry-run')) {
            return $this->previewMatching($connector, [$this->targetTable]);
        }

        $this->resetMatchingStats(7);
        $this->printMatchingHeader("{$this->source} post-sync matching");
        $this->matchSourceTableToContacts($connector, $this->targetTable);
        $this->fillEnrollmentAgents($connector);

        return $this->logMatchingSummary("{$this->source} post-sync");
    }

    private function runFinalMatching(DBConnector $connector): bool
    {
        if ($this->option('dry-run')) {
            return $this->previewMatching($connector, ['TblContactsLDR', 'TblContactsPLAW']);
        }

        $this->resetMatchingStats(10);
        $this->printMatchingHeader('orchestrator final matching (External ID → TblContacts → TblEnrollment)');

        foreach (['TblContactsLDR', 'TblContactsPLAW'] as $table) {
            $this->info("[MATCH] Processing {$table}...");
            $this->matchSourceTableToContacts($connector, $table);
        }

        $this->info('[MATCH] Propagating agents to TblEnrollment...');
        $this->fillEnrollmentAgents($connector);

        return $this->logMatchingSummary('orchestrator final');
    }

    /**
     * Read-only preview: counts rows that matching would touch + Jacob gap queries.
     * No UPDATE statements are executed.
     */
    private function previewMatching(DBConnector $connector, array $tables): bool
    {
        // 5 counts per source table + 4 enrollment fix counts + 6 Jacob gap counts
        $this->resetMatchingStats((\count($tables) * 5) + 4 + 6);
        $this->printMatchingHeader('DRY RUN — matching verification (read-only, no writes)');

        foreach ($tables as $table) {
            $this->info("[VERIFY] Preview {$table} → TblContacts...");
            $this->previewSourceTableMatching($connector, $table);
        }

        $this->info('[VERIFY] Preview TblEnrollment agent propagation...');
        $this->previewEnrollmentAgentFixes($connector);
        $this->previewJacobEnrollmentGaps($connector);

        return $this->logMatchingSummary('dry-run preview');
    }

    private function previewSourceTableMatching(DBConnector $connector, string $table): void
    {
        $agentGap = "COALESCE({$table}.Agent, '') <> '' AND COALESCE(TblContacts.Agent, '') = ''";

        $this->previewCountStep(
            $connector,
            "{$table}.llg_id_join",
            "SELECT COUNT(*) AS cnt FROM TblContacts INNER JOIN {$table} ON TblContacts.LLG_ID = {$table}.LLG_ID",
            "Rows joined on LLG_ID ({$table})"
        );

        $this->previewCountStep(
            $connector,
            "{$table}.llg_id_agent_gap",
            "SELECT COUNT(*) AS cnt FROM TblContacts INNER JOIN {$table} ON TblContacts.LLG_ID = {$table}.LLG_ID WHERE {$agentGap}",
            "Joined on LLG_ID with blank TblContacts.Agent ({$table})"
        );

        $this->previewCountStep(
            $connector,
            "{$table}.external_id_join",
            "SELECT COUNT(*) AS cnt FROM TblContacts INNER JOIN {$table}
             ON TblContacts.LLG_ID = 'LLG-' + CAST({$table}.External_ID AS VARCHAR(50))",
            "Rows joined on External_ID ({$table})"
        );

        $this->previewCountStep(
            $connector,
            "{$table}.external_id_agent_gap",
            "SELECT COUNT(*) AS cnt FROM TblContacts INNER JOIN {$table}
             ON TblContacts.LLG_ID = 'LLG-' + CAST({$table}.External_ID AS VARCHAR(50))
             WHERE {$agentGap}",
            "External_ID join with blank TblContacts.Agent ({$table})"
        );

        $this->previewCountStep(
            $connector,
            "{$table}.external_id_remap",
            "SELECT COUNT(*) AS cnt FROM TblContacts
             INNER JOIN {$table} ON TblContacts.LLG_ID = 'LLG-' + CAST({$table}.External_ID AS VARCHAR(50))
             LEFT JOIN TblContacts AS taken ON taken.LLG_ID = {$table}.LLG_ID
             WHERE taken.LLG_ID IS NULL AND TblContacts.LLG_ID <> {$table}.LLG_ID",
            "Rows eligible for LLG_ID remap ({$table})"
        );
    }

    private function previewEnrollmentAgentFixes(DBConnector $connector): void
    {
        $this->previewCountStep(
            $connector,
            'enrollment.agent_contacts',
            "SELECT COUNT(*) AS cnt FROM TblEnrollment e
             JOIN (
                 SELECT LLG_ID, MIN(Agent) AS Agent FROM TblContacts
                 WHERE Agent IS NOT NULL AND Agent <> '' GROUP BY LLG_ID
             ) c ON e.LLG_ID = c.LLG_ID
             WHERE e.Agent IS NULL OR e.Agent = '' OR e.Agent <> c.Agent",
            'Enrollments TblContacts.Agent would update'
        );

        $this->previewCountStep(
            $connector,
            'enrollment.agent_ldr_fallback',
            "SELECT COUNT(*) AS cnt FROM TblEnrollment e
             JOIN TblContactsLDR l ON e.LLG_ID = l.LLG_ID
             WHERE (e.Agent IS NULL OR e.Agent = '') AND l.Agent IS NOT NULL AND l.Agent <> ''",
            'Blank enrollments LDR fallback would fill'
        );

        $this->previewCountStep(
            $connector,
            'enrollment.agent_plaw_fallback',
            "SELECT COUNT(*) AS cnt FROM TblEnrollment e
             JOIN TblContactsPLAW p ON e.LLG_ID = p.LLG_ID
             WHERE (e.Agent IS NULL OR e.Agent = '') AND p.Agent IS NOT NULL AND p.Agent <> ''",
            'Blank enrollments PLAW fallback would fill'
        );

        $this->previewCountStep(
            $connector,
            'enrollment.drop_name',
            "SELECT COUNT(*) AS cnt FROM TblEnrollment e
             JOIN TblContacts c ON e.LLG_ID = c.LLG_ID
             WHERE COALESCE(c.Campaign, '') <> ''",
            'Enrollments Drop_Name would update from Campaign'
        );
    }

    /** Jacob's gap queries — blank enrollment agent but agent exists on contact table. */
    private function previewJacobEnrollmentGaps(DBConnector $connector): void
    {
        $this->line('');
        $this->info('[VERIFY] Jacob gap check (blank TblEnrollment.Agent, agent on contact):');

        $gaps = [
            'ldr.all'          => ['TblContactsLDR',  ''],
            'plaw.all'         => ['TblContactsPLAW', ''],
            'contacts.all'     => ['TblContacts',     ''],
            'ldr.aug2026'      => ['TblContactsLDR',  "AND e.Submitted_Date BETWEEN '8/1/2026' AND '8/31/2026'"],
            'plaw.aug2026'     => ['TblContactsPLAW', "AND e.Submitted_Date BETWEEN '8/1/2026' AND '8/31/2026'"],
            'contacts.aug2026' => ['TblContacts',     "AND e.Submitted_Date BETWEEN '8/1/2026' AND '8/31/2026'"],
        ];

        foreach ($gaps as $step => [$contactTable, $dateFilter]) {
            $this->previewCountStep(
                $connector,
                "gap.{$step}",
                "SELECT COUNT(*) AS cnt FROM TblEnrollment e
                 LEFT JOIN {$contactTable} c ON e.LLG_ID = c.LLG_ID
                 WHERE COALESCE(e.Agent, '') = '' AND c.Agent IS NOT NULL {$dateFilter}",
                "Gap rows — {$contactTable}" . ($dateFilter !== '' ? ' (Aug 2026)' : ' (all dates)')
            );
        }
    }

    private function previewCountStep(DBConnector $connector, string $step, string $sql, string $label): bool
    {
        $this->matchingStepNumber++;
        $progress = "[VERIFY {$this->matchingStepNumber}/{$this->matchingStepTotal}]";

        $result = $connector->querySqlServer($sql);
        if (!($result['success'] ?? false)) {
            $error = (string) ($result['error'] ?? 'unknown SQL Server error');
            $this->matchingStepsFailed++;
            $this->matchingFailures[] = ['step' => $step, 'label' => $label, 'error' => $error];
            $this->error("{$progress} FAILED — {$label}");
            $this->line("         error: {$error}");
            return false;
        }

        $row     = $result['data'][0] ?? [];
        $count   = (int) ($row['cnt'] ?? $row['CNT'] ?? 0);
        $this->matchingStepsOk++;
        $this->info("{$progress} {$label}: {$count}");

        return true;
    }

    private function printMatchingHeader(string $title): void
    {
        $this->line('');
        $this->info(str_repeat('─', 72));
        $this->info("[MATCH] {$title} ({$this->matchingStepTotal} checks)");
        $this->info(str_repeat('─', 72));
    }

    /** Fast read-only verification — no Snowflake, no writes. */
    private function runVerifyMatchOnly(?string $source): int
    {
        $this->info('[INFO] Verify-match only: read-only SQL counts (no sync, no writes).');

        try {
            $connector = DBConnector::fromEnvironment('ldr');
            $connector->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $tables = match ($source) {
            'LDR'  => ['TblContactsLDR'],
            'PLAW' => ['TblContactsPLAW'],
            null   => ['TblContactsLDR', 'TblContactsPLAW'],
            default => null,
        };

        if ($tables === null) {
            $this->error("Unknown source '{$source}' for --verify-match. Use LDR, PLAW, or omit --source.");
            return Command::FAILURE;
        }

        if (!$this->previewMatching($connector, $tables)) {
            $this->error('[ERROR] Verification queries failed — see output above.');
            return Command::FAILURE;
        }

        $this->info('[SUCCESS] Verification complete. Re-run without --verify-match to apply fixes.');
        return Command::SUCCESS;
    }

    /**
     * Match LDR/PLAW back to TblContacts by External_ID and copy switched fields.
     * Field copy is split from LLG_ID remap so a unique-index collision on LLG_ID
     * cannot block Agent (and related fields) from landing on TblContacts.
     */
    private function matchSourceTableToContacts(DBConnector $connector, string $table): void
    {
        $fields = $this->matchedFieldsSql($table);

        $this->runMatchingStep(
            $connector,
            "{$table}.llg_id_fields",
            "UPDATE TblContacts
             SET {$fields}
             FROM TblContacts
             INNER JOIN {$table} ON TblContacts.LLG_ID = {$table}.LLG_ID",
            "Switched fields on TblContacts from {$table} (LLG_ID match)"
        );

        $this->runMatchingStep(
            $connector,
            "{$table}.external_id_fields",
            "UPDATE TblContacts
             SET {$fields}
             FROM TblContacts
             INNER JOIN {$table}
               ON TblContacts.LLG_ID = 'LLG-' + CAST({$table}.External_ID AS VARCHAR(50))",
            "Switched fields on TblContacts from {$table} (External_ID match)"
        );

        $this->runMatchingStep(
            $connector,
            "{$table}.external_id_remap",
            "UPDATE TblContacts
             SET TblContacts.LLG_ID = {$table}.LLG_ID
             FROM TblContacts
             INNER JOIN {$table}
               ON TblContacts.LLG_ID = 'LLG-' + CAST({$table}.External_ID AS VARCHAR(50))
             LEFT JOIN TblContacts AS taken ON taken.LLG_ID = {$table}.LLG_ID
             WHERE taken.LLG_ID IS NULL
               AND TblContacts.LLG_ID <> {$table}.LLG_ID",
            "Remapped TblContacts.LLG_ID from {$table} (External_ID match)"
        );
    }

    private function fillEnrollmentAgents(DBConnector $connector): void
    {
        $steps = [
            'enrollment.agent_contacts' => [
                'sql' => "UPDATE TblEnrollment
             SET TblEnrollment.Agent = c.Agent
             FROM TblEnrollment
             JOIN (
                 SELECT LLG_ID, MIN(Agent) AS Agent
                 FROM TblContacts
                 WHERE Agent IS NOT NULL AND Agent <> ''
                 GROUP BY LLG_ID
             ) c ON TblEnrollment.LLG_ID = c.LLG_ID
             WHERE TblEnrollment.Agent IS NULL OR TblEnrollment.Agent = ''
                OR TblEnrollment.Agent <> c.Agent",
                'label' => 'Updated TblEnrollment.Agent from TblContacts',
            ],
            'enrollment.agent_ldr_fallback' => [
                'sql' => "UPDATE TblEnrollment
             SET TblEnrollment.Agent = l.Agent
             FROM TblEnrollment
             JOIN TblContactsLDR l ON TblEnrollment.LLG_ID = l.LLG_ID
             WHERE (TblEnrollment.Agent IS NULL OR TblEnrollment.Agent = '')
               AND l.Agent IS NOT NULL AND l.Agent <> ''",
                'label' => 'Updated TblEnrollment.Agent from TblContactsLDR (fallback)',
            ],
            'enrollment.agent_plaw_fallback' => [
                'sql' => "UPDATE TblEnrollment
             SET TblEnrollment.Agent = p.Agent
             FROM TblEnrollment
             JOIN TblContactsPLAW p ON TblEnrollment.LLG_ID = p.LLG_ID
             WHERE (TblEnrollment.Agent IS NULL OR TblEnrollment.Agent = '')
               AND p.Agent IS NOT NULL AND p.Agent <> ''",
                'label' => 'Updated TblEnrollment.Agent from TblContactsPLAW (fallback)',
            ],
            'enrollment.drop_name' => [
                'sql' => "UPDATE TblEnrollment
             SET TblEnrollment.Drop_Name = TblContacts.Campaign
             FROM TblEnrollment, TblContacts
             WHERE TblEnrollment.LLG_ID = TblContacts.LLG_ID
               AND COALESCE(TblContacts.Campaign, '') <> ''",
                'label' => 'Updated TblEnrollment.Drop_Name from TblContacts.Campaign',
            ],
        ];

        foreach ($steps as $step => $config) {
            $this->runMatchingStep($connector, $step, $config['sql'], $config['label']);
        }
    }

    private function matchedFieldsSql(string $src): string
    {
        return "TblContacts.Agent = CASE WHEN COALESCE({$src}.Agent, '') <> '' THEN {$src}.Agent ELSE TblContacts.Agent END,
                TblContacts.Affiliate_Agent = CASE WHEN COALESCE({$src}.Affiliate_Agent, '') <> '' THEN {$src}.Affiliate_Agent ELSE TblContacts.Affiliate_Agent END,
                TblContacts.Campaign = CASE WHEN COALESCE({$src}.Campaign, '') <> '' THEN {$src}.Campaign ELSE TblContacts.Campaign END,
                TblContacts.Category = CASE WHEN COALESCE({$src}.Category, '') <> '' THEN {$src}.Category ELSE TblContacts.Category END";
    }

    private function resetMatchingStats(int $stepTotal): void
    {
        $this->matchingStepsOk       = 0;
        $this->matchingStepsFailed   = 0;
        $this->matchingRowsAffected  = 0;
        $this->matchingStepNumber    = 0;
        $this->matchingStepTotal     = $stepTotal;
        $this->matchingFailures      = [];
    }

    private function runMatchingStep(DBConnector $connector, string $step, string $sql, string $label): bool
    {
        $this->matchingStepNumber++;
        $progress = "[MATCH {$this->matchingStepNumber}/{$this->matchingStepTotal}]";

        $result = $connector->querySqlServer($sql);
        if (!($result['success'] ?? false)) {
            $error = (string) ($result['error'] ?? 'unknown SQL Server error');
            $this->matchingStepsFailed++;
            $this->matchingFailures[] = ['step' => $step, 'label' => $label, 'error' => $error];
            $this->error("{$progress} FAILED — {$label}");
            $this->line("         step: {$step}");
            $this->line("         error: {$error}");
            return false;
        }

        $rows = (int) ($result['row_count'] ?? 0);
        $this->matchingStepsOk++;
        $this->matchingRowsAffected += $rows;
        $rowMsg = $rows === 0 ? '0 rows (nothing to update)' : "{$rows} rows affected";
        $this->info("{$progress} OK — {$label}: {$rowMsg}");

        return true;
    }

    private function logMatchingSummary(string $context): bool
    {
        $total = $this->matchingStepsOk + $this->matchingStepsFailed;

        $this->line('');
        $this->info(str_repeat('─', 72));

        if ($this->matchingStepsFailed === 0) {
            $label = str_contains($context, 'preview') ? 'PREVIEW COMPLETE' : 'COMPLETE';
            $this->info(
                "[MATCH] {$context} {$label} — {$this->matchingStepsOk}/{$total} steps OK, "
                . "{$this->matchingRowsAffected} total rows affected"
            );
            $this->info(str_repeat('─', 72));
            return true;
        }

        $this->error(
            "[MATCH] {$context} INCOMPLETE — {$this->matchingStepsFailed}/{$total} steps FAILED, "
            . "{$this->matchingStepsOk} OK, {$this->matchingRowsAffected} rows affected"
        );
        $this->line('');
        $this->error('Failed steps:');
        foreach ($this->matchingFailures as $failure) {
            $this->line("  • {$failure['step']}");
            $this->line("    {$failure['label']}");
            $this->line("    {$failure['error']}");
        }
        $this->info(str_repeat('─', 72));

        return false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalizePlanTitle(string $title): string
    {
        if (empty($title)) {
            return '';
        }
        return stripos($title, 'CCS') !== false ? 'CCS' : 'LDR';
    }

    private function parseCreditUtilization(string $value): int
    {
        if (empty($value)) {
            return 0;
        }
        if (preg_match('/^\s*([\d.]+)/', $value, $matches)) {
            $util = \floatval($matches[1]);
            if ($util < 1) {
                $util *= 100;
            }
            return \intval($util);
        }
        return 0;
    }

    private function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    private function formatDate($value): string
    {
        if (empty($value)) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        // Fast path: Snowflake returns timestamps as 'YYYY-MM-DD HH:MM:SS[.ffffff]'
        if (\is_string($value) && \strlen($value) >= 19 && $value[4] === '-' && $value[7] === '-') {
            return \substr($value, 0, 19);
        }
        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function initializeSnowflakeConnector(): DBConnector
    {
        try {
            return DBConnector::fromEnvironment(strtolower($this->source));
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unable to initialize Snowflake connector for {$this->source}: {$e->getMessage()}");
        }
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        try {
            $connector = DBConnector::fromEnvironment(strtolower($this->source));
            $connector->initializeSqlServer();
            return $connector;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unable to initialize SQL Server connector for {$this->source}: {$e->getMessage()}");
        }
    }

    // -------------------------------------------------------------------------
    // Sync timestamp (incremental watermark)
    // -------------------------------------------------------------------------

    private function timestampFilePath(): string
    {
        return storage_path('app/sync_timestamps.json');
    }

    private function readLastSyncTime(string $source): ?string
    {
        $path = $this->timestampFilePath();
        if (!\file_exists($path)) {
            return null;
        }
        $data = \json_decode(\file_get_contents($path), true);
        return $data[$source] ?? null;
    }

    private function writeLastSyncTime(string $source, string $datetime): void
    {
        $path = $this->timestampFilePath();
        $data = [];
        if (\file_exists($path)) {
            $data = \json_decode(\file_get_contents($path), true) ?? [];
        }
        $data[$source] = $datetime;
        \file_put_contents($path, \json_encode($data, JSON_PRETTY_PRINT));
    }

    // -------------------------------------------------------------------------
    // String escaping
    // -------------------------------------------------------------------------

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function escSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
