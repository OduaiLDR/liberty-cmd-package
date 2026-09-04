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
        {--owners-refresh : Re-pull EVERY contact since 2021-07-01 (current CRM ASSIGNED_TO) without truncating. Non-destructive DELETE+INSERT per chunk. Use to correct agent names that drifted because an incremental sync never re-pulled a reassignment older than the watermark.}
        {--dry-run   : Fetch and report changes without modifying SQL Server or sync watermarks; matching runs as read-only verification}
        {--verify-match : Read-only matching verification only (no Snowflake fetch, no SQL writes)}
        {--reconcile-agents : Reconcile non-blank enrollment agents from unambiguous source contact assignments}
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
        $ownersRefreshFlag = $this->option('owners-refresh') ? ['--owners-refresh'] : [];
        $dryRunFlag = $this->option('dry-run') ? ['--dry-run'] : [];
        $reconcileAgentsFlag = $this->option('reconcile-agents') ? ['--reconcile-agents'] : [];

        // ── Step 1: LT ────────────────────────────────────────────────────────
        $orchStarted = microtime(true);
        $this->logStep('Step 1/3: Syncing LT (primary contacts)...');
        $ltPool = Process::pool(function ($pool) use ($php, $artisan, $fullFlag, $ownersRefreshFlag, $dryRunFlag, $reconcileAgentsFlag) {
            $pool->as('LT')->timeout(7200)->command(
                array_merge([$php, $artisan, 'Sync:contacts-data', '--source=LT', '--no-match'], $fullFlag, $ownersRefreshFlag, $dryRunFlag, $reconcileAgentsFlag)
            );
        })->start(function (string $type, string $output, string $key) {
            foreach (explode("\n", rtrim($output)) as $line) {
                if ($line !== '') $this->line("[{$key}] {$line}");
            }
        });
        $ltStarted = microtime(true);
        $ltProcesses = $ltPool->wait();
        $this->logStep('Step 1/3: LT child process finished', $ltStarted);

        if ($ltProcesses['LT']->exitCode() !== 0) {
            $this->info("\n" . str_repeat('=', 80));
            $this->error('[ERROR] LT failed — aborting LDR/PLAW to avoid incomplete matching.');
            return Command::FAILURE;
        }

        // ── Step 2: LDR + PLAW (parallel) ────────────────────────────────────
        $this->logStep('Step 2/3: Syncing LDR and PLAW in parallel...');
        $pool = Process::pool(function ($pool) use ($php, $artisan, $fullFlag, $ownersRefreshFlag, $dryRunFlag, $reconcileAgentsFlag) {
            foreach (['LDR', 'PLAW'] as $src) {
                $pool->as($src)->timeout(7200)->command(
                    array_merge([$php, $artisan, 'Sync:contacts-data', "--source={$src}", '--no-match'], $fullFlag, $ownersRefreshFlag, $dryRunFlag, $reconcileAgentsFlag)
                );
            }
        })->start(function (string $type, string $output, string $key) {
            foreach (explode("\n", rtrim($output)) as $line) {
                if ($line !== '') $this->line("[{$key}] {$line}");
            }
        });
        $sideStarted = microtime(true);
        $processes = $pool->wait();
        $this->logStep('Step 2/3: LDR + PLAW child processes finished', $sideStarted);

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

        $this->logStep('Step 3/3: Running final table matching...');
        $matchStarted = microtime(true);
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

        $this->logStep('Step 3/3: matching done', $matchStarted);
        $this->info("\n" . str_repeat('=', 80));
        $this->logStep('All syncs (LT → LDR, PLAW → matching) completed successfully', $orchStarted);
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
        // Owners refresh: fetch wide like a full refresh (current ASSIGNED_TO for
        //   every contact) but DO NOT truncate — the per-chunk DELETE+INSERT is
        //   idempotent, so this safely corrects agent names that an incremental
        //   sync never re-pulled (reassignment older than the watermark) without
        //   the risk/downtime of dropping and rebuilding the table.
        // Full refresh: truncate the table and re-sync everything since 2021-07-01.
        $ownersRefresh = (bool) $this->option('owners-refresh');
        $lastSyncAt    = ($this->option('full') || $ownersRefresh) ? null : $this->readLastSyncTime($this->source);
        $isIncremental = $lastSyncAt !== null;

        if ($isIncremental) {
            // Subtract 24 hours as a safety buffer against clock skew / in-flight writes
            // and any latent timezone-conversion edge cases in the Snowflake date filter.
            // insertChunk() is an idempotent DELETE+INSERT, so re-pulling a day of overlap is safe.
            $startDate = date('Y-m-d H:i:s', strtotime($lastSyncAt) - 86400);
            $this->info("[INFO] Incremental mode: fetching contacts modified since {$startDate}.");
        } elseif ($ownersRefresh) {
            // Wide fetch, DELETE+INSERT per chunk, no truncate. Runs in incremental
            // insert mode so existing rows are replaced in place rather than wiped.
            $startDate     = '2021-07-01';
            $isIncremental = true;
            $this->info('[INFO] Owners-refresh mode: re-pulling every contact since 2021-07-01 (no truncate).');
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
        $categoryChanges  = [];
        $affiliateChanges = [];
        $totalFetched     = 0;
        $totalInserted    = 0;
        $pageNum          = 0;
        $syncLoopStarted  = microtime(true);

        do {
            $pageNum++;
            $pageStarted = microtime(true);
            $this->logStep("Page {$pageNum}: querying Snowflake (source={$this->source}, afterId={$lastId}, limit=" . self::PAGE_SIZE . ", since={$startDate})");

            $fetchStarted = microtime(true);
            $chunk     = $this->fetchContactsPage($snowflake, $startDate, $lastId, self::PAGE_SIZE);
            $chunkSize = \count($chunk);
            $this->logStep("Page {$pageNum}: Snowflake returned {$chunkSize} row(s)", $fetchStarted);

            if ($chunkSize === 0) {
                $this->logStep("Page {$pageNum}: empty page — done paging", $pageStarted);
                break;
            }

            $totalFetched += $chunkSize;
            $firstId = (int) ($chunk[0]['LLG_ID'] ?? 0);
            $lastId  = (int) end($chunk)['LLG_ID'];
            $this->logStep("Page {$pageNum}: ID range {$firstId} → {$lastId}");

            $enrollStarted = microtime(true);
            $this->logStep("Page {$pageNum}: loading enrollment filters...");
            $enrollmentData = $this->loadEnrollmentDataFiltered($sqlConnector, $chunk);
            $this->logStep(
                'Page ' . $pageNum . ': enrollment filters loaded ('
                . count($enrollmentData['categories'] ?? []) . ' enrolled matches)',
                $enrollStarted
            );

            $dropStarted = microtime(true);
            $this->logStep("Page {$pageNum}: loading drop names...");
            $dropNames = $this->fetchDropNamesFiltered($sqlConnector, $chunk);
            $this->logStep('Page ' . $pageNum . ': drop names loaded (' . count($dropNames) . ' matches)', $dropStarted);

            $processStarted = microtime(true);
            $this->logStep("Page {$pageNum}: processing chunk (ghost/dup-lead filter + TP_ID dedupe)...");
            $beforeProcess = $chunkSize;
            [$processedChunk, $newCatChanges, $newAffChanges] = $this->processChunk(
                $chunk,
                $dropNames,
                $enrollmentData
            );
            $this->logStep(
                'Page ' . $pageNum . ': processed ' . count($processedChunk)
                . ' row(s) from ' . $beforeProcess
                . ' (dropped ' . ($beforeProcess - count($processedChunk)) . ')',
                $processStarted
            );

            foreach ($newCatChanges as $c) {
                $categoryChanges[] = $c;
            }
            foreach ($newAffChanges as $c) {
                $affiliateChanges[] = $c;
            }

            if ($dryRun) {
                $totalInserted += count($processedChunk);
                $this->logStep('Page ' . $pageNum . ': dry-run skip write (' . count($processedChunk) . ' would upsert)');
            } else {
                try {
                    $writeStarted = microtime(true);
                    $this->logStep('Page ' . $pageNum . ': writing ' . count($processedChunk) . ' row(s) to ' . $this->targetTable . '...');
                    $totalInserted += $this->insertChunk($sqlConnector, $processedChunk, $isIncremental);
                    $this->logStep('Page ' . $pageNum . ': SQL write done', $writeStarted);
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

            $elapsed = number_format(microtime(true) - $syncLoopStarted, 1);
            $this->logStep(
                "Page {$pageNum} complete — totals: {$totalFetched} fetched, {$totalInserted} upserted | elapsed {$elapsed}s",
                $pageStarted
            );
        // Keep paging until a page comes back empty. The old condition
        // (chunkSize === PAGE_SIZE) silently ended the sync whenever a page came
        // back short for any reason, reporting success on a partial load.
        // DBConnector now throws on an incomplete fetch, so a short-but-non-empty
        // page here is legitimate and we must continue from the new cursor.
        } while ($chunkSize > 0);

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

        // Persist the watermark only after a fully successful run. An owners-refresh
        // is a wide corrective sweep, not a chronological checkpoint — leave the
        // incremental watermark untouched so the next scheduled incremental run
        // still picks up everything modified since the last real incremental.
        if (!$dryRun && !$ownersRefresh) {
            $this->writeLastSyncTime($this->source, $syncStartedAt);
            $this->info("[INFO] Sync watermark saved: {$syncStartedAt}");
        } elseif ($ownersRefresh) {
            $this->info('[INFO] Owners-refresh: incremental watermark left unchanged.');
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
        // Page by contact ID (keep LIMIT on this query so lastId paging stays correct).
        // TP_ID dups (latest Modified) are dropped in processChunk — QUALIFY here would
        // drop higher IDs and make the next page re-fetch the wrong Snowflake row.
        return "
            SELECT
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.CREATED), 'YYYY-MM-DD HH24:MI:SS') AS CREATED,
                NULL AS ASSIGNED_ON,
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, c.CREATED)), 'YYYY-MM-DD HH24:MI:SS') AS MODIFIED,
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
                uf_debt.F_DECIMAL AS DEBT_AMOUNT_CUSTOM,
                ed.TITLE AS PLAN_TITLE,
                uf_agent.F_SHORTSTRING AS AGENT_CUSTOM
            FROM CONTACTS AS c
            LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN USERS AS u1 ON c.CREATED_BY = u1.UID
            LEFT JOIN USERS AS u2 ON c.ASSIGNED_TO = u2.UID
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
              AND c.DEL = 'FALSE'
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
        // LT: ASSIGNED_TO only. Page by ID (LIMIT stays here). TP_ID dups are
        // dropped in processChunk by Modified DESC so paging lastId cannot skip.
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
            WHERE CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, c.CREATED)) >= '{$this->esc($startDate)}'::TIMESTAMP_NTZ
              AND c.DEL = 'FALSE'
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
     * @return array{0: array, 1: array, 2: array}  [processedRows, categoryChanges, affiliateChanges]
     */
    private function processChunk(
        array $chunk,
        array $dropNames,
        array $enrollmentData
    ): array {
        $processed        = [];
        $categoryChanges  = [];
        $affiliateChanges = [];

        $existingCategories = $enrollmentData['categories'];
        $existingAffiliates = $enrollmentData['affiliate_agents'];

        // Drop ghosts first, then keep latest Modified per TP_ID. If ghost wins
        // Modified DESC and we skip it after, the real contact for that TP_ID is lost.
        $ghostIds = [1212313502, 1212314964, 1212315478, 1212329195, 1212342404];
        $chunk = array_values(array_filter(
            $chunk,
            static function (array $row) use ($ghostIds): bool {
                if (in_array((int) ($row['LLG_ID'] ?? 0), $ghostIds, true)) {
                    return false;
                }
                return ($row['STATUS'] ?? '') !== 'Duplicate Lead';
            }
        ));
        $chunk = $this->dedupeSnowflakeChunkByTpId($chunk);

        foreach ($chunk as $row) {
            $contactId = $row['LLG_ID'] ?? '';
            $tpId      = trim((string) ($row['EXTERNAL_ID'] ?? ''));
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
            // Sales agents = LT SF ASSIGNED_TO roster only. Skip portal system accounts
            // ("ProgressLaw User", "LDR User", etc.). LDR/PLAW Agent always blank.
            // Affiliate_Agent on LDR/PLAW still comes from ASSIGNED_TO (not Agent).
            $assignedTo = trim((string) ($row['ASSIGNED_TO'] ?? ''));
            $agent = $this->source === 'LT'
                ? $this->rosterAgentName($assignedTo)
                : '';
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
                'affiliate_agent'    => \substr($this->source === 'LT' ? $agent : $assignedTo, 0, 255),
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
     *
     * LT special case: after matching remaps TblContacts.LLG_ID to LDR/PLAW,
     * the LT key no longer exists. Re-INSERT would create a duplicate. Instead
     * UPDATE the remapped row by External_ID and keep its LLG_ID (Jacob: match
     * updates IDs; later LT sync must not insert a second row).
     */
    private function insertChunk(DBConnector $connector, array $data, bool $incremental = false): int
    {
        if (empty($data)) {
            return 0;
        }

        // Deduplicate by LLG_ID within this chunk: keep the row with the most recent
        // assigned_date and prefer rows with a non-empty agent.
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
            $toInsert = $data;
            $toUpdate = [];

            if ($incremental && $this->source === 'LT') {
                $splitStarted = microtime(true);
                $this->logStep('SQL: splitting LT upserts (External_ID + remapped LDR/PLAW lookup)...');
                [$toUpdate, $toInsert] = $this->splitLtIncrementalUpserts($pdo, $data);
                $this->logStep(
                    'SQL: split done — update=' . count($toUpdate) . ', insert=' . count($toInsert),
                    $splitStarted
                );

                if ($toUpdate !== []) {
                    $updStarted = microtime(true);
                    $this->logStep('SQL: updating ' . count($toUpdate) . ' remapped TblContacts row(s)...');
                    $this->updateLtContactsByExternalId($pdo, $toUpdate);
                    $this->logStep('SQL: remapped updates done', $updStarted);
                }
            }

            if ($incremental && !empty($toInsert)) {
                $delStarted = microtime(true);
                $this->logStep('SQL: deleting ' . count($toInsert) . ' existing LLG_ID(s) before re-insert...');
                $this->deleteByLlgIds($pdo, \array_column($toInsert, 'llg_id'));
                $this->logStep('SQL: deletes done', $delStarted);
            }

            if (!empty($toInsert)) {
                $insStarted = microtime(true);
                $this->logStep('SQL: inserting ' . count($toInsert) . ' row(s) into ' . $this->targetTable . '...');
                $this->insertContactRows($pdo, $fields, $toInsert);
                $this->logStep('SQL: inserts done', $insStarted);
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

    /**
     * LT incremental: rows whose External_ID already exists under a different LLG_ID
     * were remapped by matching — UPDATE that row, do not INSERT the old LT key.
     * Also detects remaps via LDR/PLAW.External_ID = LT contact id (Amanda-class blank TP_ID).
     *
     * @return array{0: list<array>, 1: list<array>} [toUpdate, toInsert]
     */
    private function splitLtIncrementalUpserts(\PDO $pdo, array $data): array
    {
        $extStarted = microtime(true);
        $this->logStep('SQL: looking up existing External_ID owners (' . count($data) . ' rows)...');
        $extToExistingLlg = $this->lookupLtExternalIdOwners($pdo, $data);
        $this->logStep('SQL: External_ID owners found=' . count($extToExistingLlg), $extStarted);

        $remapStarted = microtime(true);
        $this->logStep('SQL: looking up remapped owners via LDR/PLAW External_ID...');
        $ltIdToExistingLlg = $this->lookupLtRemappedOwnersByContactId($pdo, $data);
        $this->logStep('SQL: remapped owners found=' . count($ltIdToExistingLlg), $remapStarted);

        $toUpdate = [];
        $toInsert = [];

        foreach ($data as $row) {
            $ext = (string) ($row['external_id'] ?? '');
            $llg = (string) ($row['llg_id'] ?? '');
            $ltId = preg_replace('/^LLG-/i', '', $llg);
            $existingLlg = $ext !== '' ? ($extToExistingLlg[$ext] ?? null) : null;
            if ($existingLlg === null && $ltId !== '') {
                $existingLlg = $ltIdToExistingLlg[$ltId] ?? null;
            }
            if ($existingLlg !== null && $existingLlg !== $llg) {
                // Carry LT contact id so UPDATE can join when TP_ID/External_ID is blank.
                $row['_lt_contact_id'] = $ltId;
                $toUpdate[] = $row;
            } else {
                $toInsert[] = $row;
            }
        }

        return [$toUpdate, $toInsert];
    }

    /**
     * Remapped TblContacts rows: side-table External_ID holds the original LT contact id.
     *
     * @return array<string, string> LT contact id => existing TblContacts.LLG_ID
     */
    private function lookupLtRemappedOwnersByContactId(\PDO $pdo, array $data): array
    {
        $ltIds = [];
        foreach ($data as $row) {
            $llg = (string) ($row['llg_id'] ?? '');
            $ltId = preg_replace('/^LLG-/i', '', $llg);
            if ($ltId !== '' && ctype_digit($ltId)) {
                $ltIds[$ltId] = $llg;
            }
        }
        if ($ltIds === []) {
            return [];
        }

        $pdo->exec('CREATE TABLE #TmpLtRemapIds (LtId VARCHAR(50) NOT NULL PRIMARY KEY)');
        try {
            foreach (\array_chunk(\array_keys($ltIds), 1000) as $batch) {
                $values = \implode(', ', \array_map(
                    fn($id) => "('" . $this->escSql($id) . "')",
                    $batch
                ));
                if ($pdo->exec("INSERT INTO #TmpLtRemapIds (LtId) VALUES {$values}") === false) {
                    $err = $pdo->errorInfo();
                    throw new \RuntimeException('LT remapped id temp insert failed: ' . ($err[2] ?? 'unknown PDO error'));
                }
            }

            $sql = "
                SELECT t.LtId, c.LLG_ID
                FROM #TmpLtRemapIds AS t
                INNER JOIN TblContactsPLAW AS src ON src.External_ID = t.LtId
                INNER JOIN TblContacts AS c ON c.LLG_ID = src.LLG_ID
                UNION
                SELECT t.LtId, c.LLG_ID
                FROM #TmpLtRemapIds AS t
                INNER JOIN TblContactsLDR AS src ON src.External_ID = t.LtId
                INNER JOIN TblContacts AS c ON c.LLG_ID = src.LLG_ID
            ";
            $stmt = $pdo->query($sql);
            if ($stmt === false) {
                $err = $pdo->errorInfo();
                throw new \RuntimeException('LT remapped contact-id lookup failed: ' . ($err[2] ?? 'unknown PDO error'));
            }

            $map = [];
            while ($found = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $ltId = (string) ($found['LtId'] ?? $found['ltid'] ?? '');
                $existing = (string) ($found['LLG_ID'] ?? $found['llg_id'] ?? '');
                $incoming = $ltIds[$ltId] ?? '';
                if ($ltId === '' || $existing === '' || $existing === $incoming) {
                    continue;
                }
                $map[$ltId] = $existing;
            }
            return $map;
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS #TmpLtRemapIds');
        }
    }

    /** @return array<string, string> External_ID => preferred existing LLG_ID (prefer non-incoming LT key when both exist) */
    private function lookupLtExternalIdOwners(\PDO $pdo, array $data): array
    {
        $map = [];
        foreach (\array_chunk($data, 1000) as $batch) {
            $extIds = [];
            foreach ($batch as $row) {
                $ext = (string) ($row['external_id'] ?? '');
                if ($ext !== '') {
                    $extIds[$ext] = (string) ($row['llg_id'] ?? '');
                }
            }
            if ($extIds === []) {
                continue;
            }
            $inList = \implode(', ', \array_map(
                fn($id) => "'" . $this->escSql($id) . "'",
                \array_keys($extIds)
            ));
            $stmt = $pdo->query(
                "SELECT LLG_ID, External_ID FROM {$this->targetTable} WHERE External_ID IN ({$inList})"
            );
            if ($stmt === false) {
                $err = $pdo->errorInfo();
                throw new \RuntimeException('LT External_ID lookup failed: ' . ($err[2] ?? 'unknown PDO error'));
            }
            $byExt = [];
            while ($found = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $ext = (string) ($found['External_ID'] ?? $found['external_id'] ?? '');
                $llg = (string) ($found['LLG_ID'] ?? $found['llg_id'] ?? '');
                if ($ext === '' || $llg === '') {
                    continue;
                }
                $byExt[$ext][] = $llg;
            }
            foreach ($byExt as $ext => $llgs) {
                $incoming = $extIds[$ext] ?? '';
                $preferred = null;
                foreach ($llgs as $llg) {
                    if ($llg !== $incoming) {
                        $preferred = $llg;
                        break;
                    }
                }
                $map[$ext] = $preferred ?? $llgs[0];
            }
        }

        return $map;
    }

    private function deleteByLlgIds(\PDO $pdo, array $llgIds): void
    {
        $llgIds = \array_values(\array_filter($llgIds, fn($id) => $id !== null && $id !== ''));
        if ($llgIds === []) {
            return;
        }
        foreach (\array_chunk($llgIds, 1000) as $deleteBatch) {
            $ids = \implode(', ', \array_map(
                fn($id) => "'" . $this->escSql((string) $id) . "'",
                $deleteBatch
            ));
            $sql = "DELETE FROM {$this->targetTable} WHERE LLG_ID IN ({$ids})";
            if ($pdo->exec($sql) === false) {
                $err = $pdo->errorInfo();
                throw new \RuntimeException('DELETE batch failed: ' . ($err[2] ?? 'unknown PDO error'));
            }
        }
    }

    /** Refresh fields on remapped TblContacts rows; LLG_ID unchanged. */
    private function updateLtContactsByExternalId(\PDO $pdo, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (\array_chunk($rows, 500) as $batch) {
            $pdo->exec('CREATE TABLE #TmpLtExtUpd (
                External_ID VARCHAR(50) NULL,
                LtContactId VARCHAR(50) NOT NULL,
                Created_Date DATETIME NULL,
                Assigned_Date DATETIME NULL,
                Campaign NVARCHAR(255) NULL,
                Data_Source NVARCHAR(255) NULL,
                Created_By NVARCHAR(255) NULL,
                Agent NVARCHAR(255) NULL,
                Client NVARCHAR(255) NULL,
                Phone VARCHAR(50) NULL,
                Email NVARCHAR(255) NULL,
                Address_1 NVARCHAR(255) NULL,
                Address_2 NVARCHAR(255) NULL,
                City NVARCHAR(100) NULL,
                State VARCHAR(20) NULL,
                Zip VARCHAR(20) NULL,
                Stage NVARCHAR(255) NULL,
                Status NVARCHAR(255) NULL,
                Debt_Amount INT NULL,
                Debt_Enrolled FLOAT NULL,
                Credit_Score INT NULL,
                Credit_Utilization INT NULL,
                Category NVARCHAR(255) NULL,
                Affiliate_Agent NVARCHAR(255) NULL
            )');

            $valuesParts = [];
            foreach ($batch as $row) {
                $createdDate  = $row['created_date'] ? "'{$row['created_date']}'" : 'NULL';
                $assignedDate = $row['assigned_date'] ? "'{$row['assigned_date']}'" : 'NULL';
                $email        = \strpos($row['email'], '@') !== false
                    ? "'" . $this->escSql($row['email']) . "'"
                    : 'NULL';
                $ltContactId = (string) ($row['_lt_contact_id'] ?? preg_replace('/^LLG-/i', '', (string) ($row['llg_id'] ?? '')));
                $extSql = ($row['external_id'] ?? '') !== ''
                    ? "'" . $this->escSql((string) $row['external_id']) . "'"
                    : 'NULL';
                $valuesParts[] = "("
                    . "{$extSql}, "
                    . "'{$this->escSql($ltContactId)}', "
                    . "{$createdDate}, {$assignedDate}, "
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
                    . ')';
            }

            $ins = 'INSERT INTO #TmpLtExtUpd (
                External_ID, LtContactId, Created_Date, Assigned_Date, Campaign, Data_Source, Created_By, Agent, Client,
                Phone, Email, Address_1, Address_2, City, State, Zip, Stage, Status,
                Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, Category, Affiliate_Agent
            ) VALUES ' . \implode(', ', $valuesParts);
            if ($pdo->exec($ins) === false) {
                $err = $pdo->errorInfo();
                throw new \RuntimeException('LT remapped UPDATE staging failed: ' . ($err[2] ?? 'unknown PDO error'));
            }

            $upd = "UPDATE c
                SET c.External_ID = CASE
                        WHEN COALESCE(c.External_ID, '') <> '' THEN c.External_ID
                        WHEN COALESCE(u.External_ID, '') <> '' THEN u.External_ID
                        ELSE u.LtContactId
                    END,
                    c.Created_Date = u.Created_Date,
                    c.Assigned_Date = u.Assigned_Date,
                    c.Campaign = u.Campaign,
                    c.Data_Source = u.Data_Source,
                    c.Created_By = u.Created_By,
                    c.Agent = u.Agent,
                    c.Client = u.Client,
                    c.Phone = u.Phone,
                    c.Email = u.Email,
                    c.Address_1 = u.Address_1,
                    c.Address_2 = u.Address_2,
                    c.City = u.City,
                    c.State = u.State,
                    c.Zip = u.Zip,
                    c.Stage = u.Stage,
                    c.Status = u.Status,
                    c.Debt_Amount = u.Debt_Amount,
                    c.Debt_Enrolled = u.Debt_Enrolled,
                    c.Credit_Score = u.Credit_Score,
                    c.Credit_Utilization = u.Credit_Utilization,
                    c.Category = u.Category,
                    c.Affiliate_Agent = u.Affiliate_Agent
                FROM {$this->targetTable} AS c
                INNER JOIN #TmpLtExtUpd AS u ON (
                    (COALESCE(u.External_ID, '') <> '' AND c.External_ID = u.External_ID)
                    OR c.External_ID = u.LtContactId
                    OR EXISTS (
                        SELECT 1 FROM TblContactsPLAW p
                        WHERE p.LLG_ID = c.LLG_ID AND CAST(p.External_ID AS VARCHAR(50)) = u.LtContactId
                    )
                    OR EXISTS (
                        SELECT 1 FROM TblContactsLDR l
                        WHERE l.LLG_ID = c.LLG_ID AND CAST(l.External_ID AS VARCHAR(50)) = u.LtContactId
                    )
                )";
            if ($pdo->exec($upd) === false) {
                $err = $pdo->errorInfo();
                throw new \RuntimeException('LT remapped UPDATE failed: ' . ($err[2] ?? 'unknown PDO error'));
            }

            $pdo->exec('DROP TABLE #TmpLtExtUpd');
        }
    }

    private function insertContactRows(\PDO $pdo, string $fields, array $data): void
    {
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

        $this->resetMatchingStats(9);
        $this->printMatchingHeader("{$this->source} post-sync matching");
        $this->matchSourceTableToContacts($connector, $this->targetTable);
        $this->fillEnrollmentAgents($connector, (bool) $this->option('reconcile-agents'));

        return $this->logMatchingSummary("{$this->source} post-sync");
    }

    private function runFinalMatching(DBConnector $connector): bool
    {
        if ($this->option('dry-run')) {
            return $this->previewMatching($connector, ['TblContactsLDR', 'TblContactsPLAW']);
        }

        $this->resetMatchingStats(15);
        $this->printMatchingHeader('orchestrator final matching (External ID → TblContacts → TblEnrollment)');

        foreach (['TblContactsLDR', 'TblContactsPLAW'] as $table) {
            $this->info("[MATCH] Processing {$table}...");
            $this->matchSourceTableToContacts($connector, $table);
        }

        $this->info('[MATCH] Propagating agents to TblEnrollment...');
        $this->fillEnrollmentAgents($connector, (bool) $this->option('reconcile-agents'));

        return $this->logMatchingSummary('orchestrator final');
    }

    /**
     * Read-only preview: counts rows that matching would touch + Jacob gap queries.
     * No UPDATE statements are executed.
     */
    private function previewMatching(DBConnector $connector, array $tables): bool
    {
        // 6 counts per source table + 3 enrollment fix counts + 6 Jacob gap counts
        $this->resetMatchingStats((\count($tables) * 6) + 3 + 6);
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

        $this->previewCountStep(
            $connector,
            "{$table}.backfill_external_id",
            "SELECT COUNT(*) AS cnt FROM TblContacts
             INNER JOIN {$table} ON TblContacts.LLG_ID = {$table}.LLG_ID
             WHERE COALESCE(TblContacts.External_ID, '') = ''
               AND COALESCE(CAST({$table}.External_ID AS VARCHAR(50)), '') <> ''",
            "Blank External_ID rows {$table} would backfill"
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
                 WHERE Agent IS NOT NULL AND Agent <> '' AND Agent NOT LIKE '% User'
                 GROUP BY LLG_ID
             ) c ON e.LLG_ID = c.LLG_ID
             WHERE e.Agent IS NULL OR e.Agent = '' OR e.Agent LIKE '% User' OR e.Agent <> c.Agent",
            'Enrollments TblContacts.Agent would update'
        );

        $this->previewCountStep(
            $connector,
            'enrollment.clear_system_user_agents',
            "SELECT COUNT(*) AS cnt FROM TblEnrollment e
             WHERE e.Agent LIKE '% User'
               AND NOT EXISTS (
                    SELECT 1 FROM TblContacts c
                    WHERE c.LLG_ID = e.LLG_ID
                      AND c.Agent IS NOT NULL AND c.Agent <> '' AND c.Agent NOT LIKE '% User'
               )",
            'Enrollment system-user agents that would clear'
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
     * Agent stays from LT (SF ASSIGNED_TO) — Jacob: contacts Agent linked from Assigned_To.
     * Field copy is split from LLG_ID remap so a unique-index collision on LLG_ID
     * cannot block Campaign/Category from landing on TblContacts.
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

        // Amanda-class: remapped rows often keep blank External_ID (LT TP_ID null).
        // Store side-table External_ID (LT contact id) so later LT sync can refresh Agent.
        $this->runMatchingStep(
            $connector,
            "{$table}.backfill_external_id",
            "UPDATE TblContacts
             SET TblContacts.External_ID = LEFT(CAST({$table}.External_ID AS VARCHAR(50)), 50)
             FROM TblContacts
             INNER JOIN {$table} ON TblContacts.LLG_ID = {$table}.LLG_ID
             WHERE COALESCE(TblContacts.External_ID, '') = ''
               AND COALESCE(CAST({$table}.External_ID AS VARCHAR(50)), '') <> ''",
            "Backfilled blank TblContacts.External_ID from {$table}"
        );

        $this->runMatchingStep(
            $connector,
            "{$table}.enroll_orphan_to_kept",
            "UPDATE e
             SET e.LLG_ID = kept.LLG_ID
             FROM TblEnrollment AS e
             INNER JOIN TblContacts AS lt ON e.LLG_ID = lt.LLG_ID
             INNER JOIN {$table} AS src
               ON lt.LLG_ID = 'LLG-' + CAST(src.External_ID AS VARCHAR(50))
             INNER JOIN TblContacts AS kept ON kept.LLG_ID = src.LLG_ID
             WHERE lt.LLG_ID <> src.LLG_ID
               AND NOT EXISTS (
                    SELECT 1 FROM TblEnrollment AS e2 WHERE e2.LLG_ID = kept.LLG_ID
               )",
            "Moved enrollments from orphan LT key to kept LLG_ID ({$table})"
        );

        $this->runMatchingStep(
            $connector,
            "{$table}.drop_lt_orphans",
            "DELETE lt
             FROM TblContacts AS lt
             INNER JOIN {$table} AS src
               ON lt.LLG_ID = 'LLG-' + CAST(src.External_ID AS VARCHAR(50))
             INNER JOIN TblContacts AS kept ON kept.LLG_ID = src.LLG_ID
             WHERE lt.LLG_ID <> src.LLG_ID
               AND NOT EXISTS (
                    SELECT 1 FROM TblEnrollment AS e WHERE e.LLG_ID = lt.LLG_ID
               )",
            "Dropped orphan LT-keyed TblContacts rows after {$table} match"
        );
    }

    private function fillEnrollmentAgents(DBConnector $connector, bool $reconcileAgents = false): void
    {
        // Enrollment Agent comes from TblContacts (LT roster) only — never LDR/PLAW
        // process users ("ProgressLaw User", "LDR User").
        $badAgent = "(TblEnrollment.Agent IS NULL OR TblEnrollment.Agent = '' OR TblEnrollment.Agent LIKE '% User')";
        $goodContact = "c.Agent IS NOT NULL AND c.Agent <> '' AND c.Agent NOT LIKE '% User'";

        $steps = [
            'enrollment.agent_contacts' => [
                'sql' => "UPDATE TblEnrollment
             SET TblEnrollment.Agent = c.Agent
             FROM TblEnrollment
             JOIN (
                 SELECT LLG_ID, MIN(Agent) AS Agent
                 FROM TblContacts
                 WHERE Agent IS NOT NULL AND Agent <> '' AND Agent NOT LIKE '% User'
                 GROUP BY LLG_ID
             ) c ON TblEnrollment.LLG_ID = c.LLG_ID
             WHERE {$badAgent}
                OR TblEnrollment.Agent <> c.Agent",
                'label' => 'Updated TblEnrollment.Agent from TblContacts',
            ],
            'enrollment.clear_system_user_agents' => [
                'sql' => "UPDATE TblEnrollment
             SET Agent = NULL
             WHERE Agent LIKE '% User'
               AND NOT EXISTS (
                    SELECT 1 FROM TblContacts c
                    WHERE c.LLG_ID = TblEnrollment.LLG_ID
                      AND {$goodContact}
               )",
                'label' => 'Cleared enrollment system-user agents with no roster contact',
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

    /**
     * Keep first record per TP_ID ordered by Modified DESC (Jacob).
     * Blank TP_ID rows are kept individually (keyed by contact ID).
     */
    private function dedupeSnowflakeChunkByTpId(array $chunk): array
    {
        $best = [];
        foreach ($chunk as $row) {
            $tpId = trim((string) ($row['EXTERNAL_ID'] ?? $row['TP_ID'] ?? ''));
            $id = (string) ($row['LLG_ID'] ?? '');
            $key = $tpId !== '' ? $tpId : ('ID-' . $id);
            $modified = (string) ($row['MODIFIED'] ?? '');
            if (!isset($best[$key])) {
                $best[$key] = $row;
                continue;
            }
            $existingModified = (string) ($best[$key]['MODIFIED'] ?? '');
            if ($modified > $existingModified
                || ($modified === $existingModified && $id > (string) ($best[$key]['LLG_ID'] ?? ''))
            ) {
                $best[$key] = $row;
            }
        }
        return array_values($best);
    }

    /** Real sales roster name — not portal system accounts. */
    private function rosterAgentName(string $assignedTo): string
    {
        $name = trim($assignedTo);
        if ($name === '' || preg_match('/\bUser$/i', $name)) {
            return '';
        }
        return $name;
    }

    private function matchedFieldsSql(string $src): string
    {
        // Do not overwrite Agent — TblContacts.Agent comes from LT SF ASSIGNED_TO.
        return "TblContacts.Affiliate_Agent = CASE WHEN COALESCE({$src}.Affiliate_Agent, '') <> '' THEN {$src}.Affiliate_Agent ELSE TblContacts.Affiliate_Agent END,
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

    /** Timestamped console line; optional elapsed seconds from $startedAt. */
    private function logStep(string $message, ?float $startedAt = null): void
    {
        $suffix = $startedAt !== null
            ? ' (' . number_format(microtime(true) - $startedAt, 1) . 's)'
            : '';
        $this->info('[INFO] ' . date('H:i:s') . ' ' . $message . $suffix);
        if (\defined('STDOUT')) {
            @\fflush(STDOUT);
        }
    }
}
