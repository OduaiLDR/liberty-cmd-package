<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\ContactSyncIdentity;
use Cmd\Reports\Services\ContactSyncMatching;
use Cmd\Reports\Services\ContactSyncSourceEvidence;
use Cmd\Reports\Services\ContactSyncTargets;
use Cmd\Reports\Services\ContactSyncWatermark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SyncContactsData extends Command
{
    protected $signature = 'Sync:contacts-data
        {--source=   : Run a single source only (LDR, PLAW, or LT)}
        {--full      : Preview a full source refresh; requires --dry-run while identities are reconciled}
        {--debt-only : Compare debt columns only; requires --dry-run and --source=LDR or PLAW}
        {--owners-refresh : Explicitly re-pull contacts since 2021-07-01 using guarded upserts; existing identities and remapped keys must verify}
        {--dry-run   : Fetch and report changes without modifying SQL Server or sync watermarks; matching runs as read-only verification}
        {--verify-match : Read-only matching verification only (no Snowflake fetch, no SQL writes)}
        {--reconcile-agents : Reconcile non-blank enrollment agents from unambiguous source contact assignments}
        {--no-match  : Skip post-sync table matching (internal flag used by the orchestrator)}';

    protected $description = 'Sync contacts data from Snowflake to SQL Server (TblContactsLDR, TblContactsPLAW, and TblContactsLT)';

    private const PAGE_SIZE = 1000;
    private const MIN_PAGE_SIZE = 200;
    private const PROCESS_TIMEOUT_SECONDS = 21600;
    private const MAX_LOAN_AMOUNT = 999999;

    private string $source;
    private int $debtAmountCustomId;
    private int $agentCustomId;
    private string $targetTable;

    private array $debtPreview = [];
    private ?\PDO $refreshPdo = null;
    private ?string $refreshStage = null;
    private bool $refreshPublished = false;
    private bool $mailerSuffixCacheReady = false;
    private array $cachedMailerSuffixes = [];
    private array $pendingMailerSuffixes = [];
    private int $pageSize = self::PAGE_SIZE;
    private array $contactFlags = [];
    private array $skippedContactIds = [];

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
        if ($this->option('full') && !$this->option('dry-run')) {
            $this->error('Full replacement is disabled while contact identities are being reconciled. Use a reviewed owners-refresh upsert; --full --dry-run remains read-only.');
            return Command::FAILURE;
        }
        if ($this->option('debt-only') && (!$this->option('dry-run') || !in_array($source, ['LDR', 'PLAW'], true))) {
            $this->error('--debt-only requires --dry-run and --source=LDR or --source=PLAW.');
            return Command::FAILURE;
        }
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
            $pool->as('LT')->timeout(self::PROCESS_TIMEOUT_SECONDS)->command(
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
        $hasSkippedRecords = str_contains($ltProcesses['LT']->output(), '[SYNC FLAGS]');
        $hasReviewFlags = str_contains($ltProcesses['LT']->output(), '[CONTACT FLAG]');

        // ── Step 2: LDR + PLAW (parallel) ────────────────────────────────────
        $this->logStep('Step 2/3: Syncing LDR and PLAW in parallel...');
        $pool = Process::pool(function ($pool) use ($php, $artisan, $fullFlag, $ownersRefreshFlag, $dryRunFlag, $reconcileAgentsFlag) {
            foreach (['LDR', 'PLAW'] as $src) {
                $pool->as($src)->timeout(self::PROCESS_TIMEOUT_SECONDS)->command(
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
            $hasSkippedRecords = $hasSkippedRecords || str_contains($processes[$src]->output(), '[SYNC FLAGS]');
            $hasReviewFlags = $hasReviewFlags || str_contains($processes[$src]->output(), '[CONTACT FLAG]');
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
                if (!$this->runFinalMatching($connector)) {
                    return Command::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->error('[DRY RUN] Matching preview failed: ' . $e->getMessage());
                return Command::FAILURE;
            }
            if ($hasSkippedRecords || $hasReviewFlags) {
                $this->warn('[DRY RUN] Preview completed with flagged records; review source summaries. No SQL Server changes were made.');
            } else {
                $this->info('[SUCCESS] Dry run completed; no SQL Server changes were made.');
            }
            return Command::SUCCESS;
        }

        if ($hasSkippedRecords) {
            $this->warn('[SYNC FLAGS] Source runs completed with skipped records. Their checkpoints were retained; global matching was not run.');
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
        $this->logStep($hasReviewFlags ? 'All source runs and matching completed with review flags; see source summaries'
            : 'All syncs (LT → LDR, PLAW → matching) completed successfully', $orchStarted);
        return Command::SUCCESS;
    }

    private function syncForSource(string $source): int
    {
        $this->source = $source;
        $this->info("[INFO] Sync Contacts Data: starting for {$this->source}.");
        if (!$this->option('dry-run') && !$this->option('owners-refresh')) {
            try {
                if ($this->readLastSyncTime($source) === null) {
                    $this->error('No source checkpoint exists. Refusing an implicit full replacement; review --owners-refresh explicitly.');
                    return Command::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->error('Invalid contact sync checkpoint: ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

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

        $this->debtPreview = array_fill_keys(['processed', 'loan', 'enrolled_fallback', 'no_debt',
            'changed', 'amount_changed', 'enrolled_changed', 'unchanged', 'new', 'samples'], 0);

        // A dry run must not write to a database-backed cache lock.
        if ($this->option('dry-run')) {
            try {
                return $this->runSourceSync();
            } catch (\Throwable $e) {
                $this->error('[DRY RUN FAILED] ' . $e->getMessage());
                return Command::FAILURE;
            }
        }

        // Prevent two syncs of the SAME source from running concurrently. Overlapping
        // runs race each other's DELETE+INSERT and silently create duplicate rows
        // (this is how TblContacts accumulated its historical duplicate backlog).
        // The lock is DB-backed (CACHE_STORE=database), so it serializes across the
        // orchestrator's subprocess and any manual/scheduled run on this host. It
        // auto-expires after 6h so a crashed run can never wedge future syncs.
        $lock = Cache::lock("sync-contacts-data:{$this->source}", self::PROCESS_TIMEOUT_SECONDS);
        if (! $lock->get()) {
            $this->warn("[WARN] Another {$this->source} sync is already running; skipping this run to avoid duplicate rows.");
            Log::warning('SyncContactsData: overlapping run skipped', ['source' => $this->source]);
            return Command::FAILURE;
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
        try {
            return $this->performSourceSync();
        } catch (\Throwable $e) {
            $this->error('[ERROR] ' . $this->source . ' sync failed: ' . $e->getMessage());
            if ($this->refreshStage !== null) {
                $this->error($this->refreshPublished
                    ? '[FULL REFRESH] Contacts were committed, but cleanup failed; watermark was not advanced.'
                    : '[FULL REFRESH] Did not complete. Staging does not clear the target; replacement errors trigger transaction rollback.');
            }
            return Command::FAILURE;
        } finally {
            if ($this->refreshStage !== null && $this->refreshPdo !== null) {
                try {
                    if ($this->mailerSuffixCacheReady) {
                        $this->checkedExec($this->refreshPdo, 'DROP TABLE IF EXISTS #TmpMailerSuffixCache');
                        $this->mailerSuffixCacheReady = false;
                        $this->cachedMailerSuffixes = [];
                    }
                    $this->checkedExec($this->refreshPdo, "DROP TABLE IF EXISTS {$this->refreshStage}");
                } catch (\Throwable $e) {
                    $this->warn('[WARN] Could not drop the session-local refresh table; it will be removed when the connection closes.');
                }
            }
            $this->refreshStage = null;
            $this->refreshPdo = null;
        }
    }

    private function performSourceSync(): int
    {
        $this->contactFlags = [];
        $this->skippedContactIds = [];
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('[DRY RUN] Read-only preview: no SQL Server writes or watermark updates. Debt samples show at most 10 changed IDs per source.');
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
        // Full previews are read-only. Full writes are blocked at the command boundary.
        $ownersRefresh = (bool) $this->option('owners-refresh');
        $lastSyncAt    = ($this->option('full') || $ownersRefresh) ? null : $this->readLastSyncTime($this->source);
        $isIncremental = $lastSyncAt !== null;

        if ($isIncremental) {
            // Subtract 24 hours as a safety buffer against clock skew / in-flight writes
            // and any latent timezone-conversion edge cases in the Snowflake date filter.
            // Guarded upserts are idempotent; repeated overlap does not merge native source IDs.
            $startDate = date('Y-m-d H:i:s', strtotime($lastSyncAt) - 86400);
            $this->info("[INFO] Incremental mode: fetching contacts modified since {$startDate}.");
        } elseif ($ownersRefresh) {
            // Explicit wide fetch uses the same verified target selection and field updates.
            $startDate     = '2021-07-01';
            $isIncremental = true;
            $this->info('[INFO] Owners-refresh mode: re-pulling every contact since 2021-07-01 (no truncate).');
        } else {
            $startDate = '2021-07-01';
            $this->info('[INFO] Full refresh mode.' . ($dryRun
                ? ' Read-only preview; target table will not be changed.'
                : ' Existing contacts remain in place while every page is staged.'));
            if (!$dryRun) {
                $this->beginFullRefresh($sqlConnector);
            }
        }

        // Record the sync start time before any data is fetched.
        // This timestamp is written to the file only after the entire run succeeds,
        // ensuring a failed/partial run never advances the watermark.
        $syncStartedAt = date('Y-m-d H:i:s');
        if (!$dryRun && !$this->option('debt-only')) {
            $this->pendingMailerSuffixes = $this->fetchMailerSuffixes($snowflake, $startDate);
        }

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
            $this->logStep("Page {$pageNum}: querying Snowflake (source={$this->source}, afterId={$lastId}, limit={$this->pageSize}, since={$startDate})");

            $fetchStarted = microtime(true);
            $chunk     = $this->fetchContactsPage($snowflake, $startDate, $lastId, $this->pageSize);
            $chunkSize = \count($chunk);
            $this->logStep("Page {$pageNum}: Snowflake returned {$chunkSize} row(s)", $fetchStarted);

            if ($chunkSize === 0) {
                $this->logStep("Page {$pageNum}: empty page — done paging", $pageStarted);
                break;
            }

            $totalFetched += $chunkSize;
            $firstId = (int) ($chunk[0]['LLG_ID'] ?? 0);
            $nextId = (int) (end($chunk)['LLG_ID'] ?? 0);
            if ($nextId <= $lastId) {
                throw new \RuntimeException('Snowflake cursor did not advance; refusing an incomplete refresh.');
            }
            $lastId = $nextId;
            $this->logStep("Page {$pageNum}: ID range {$firstId} → {$lastId}");

            if ($this->option('debt-only')) {
                $enrollmentData = ['categories' => [], 'assigned_agents' => [], 'affiliate_agents' => []];
                $dropNames = [];
                $this->info('[DRY RUN] Debt-only preview: enrollment and mailer lookups skipped.');
            } else {
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
            }

            $processStarted = microtime(true);
            $this->logStep("Page {$pageNum}: processing chunk (ghost/dup-lead filter + source ID validation)...");
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

            if ($dryRun) {
                $this->info("[DRY RUN][{$this->source}] Comparing proposed source rows with {$this->targetTable}...");
                if ($this->option('debt-only')) {
                    $accepted = $this->previewDebtChunk($sqlConnector, $processedChunk);
                } else {
                    $accepted = $this->previewContactChunk($sqlConnector, $processedChunk);
                }
                $totalInserted += $accepted;
                $this->logStep('Page ' . $pageNum . ': dry-run skip write (' . $accepted . ' accepted; ' . count($this->skippedContactIds) . ' flagged IDs so far)');
            } else {
                try {
                    $writeStarted = microtime(true);
                    $destination = $this->refreshStage !== null ? 'temporary staging' : $this->targetTable;
                    $this->logStep('Page ' . $pageNum . ': writing ' . count($processedChunk) . ' row(s) to ' . $destination . '...');
                    $totalInserted += $this->refreshStage !== null
                        ? $this->stageFullRefreshChunk($sqlConnector, $processedChunk)
                        : $this->insertChunk($sqlConnector, $processedChunk, $isIncremental);
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

            foreach ($newCatChanges as $c) {
                if (!isset($this->skippedContactIds[$c['llg_id']])) $categoryChanges[] = $c;
            }
            foreach ($newAffChanges as $c) {
                if (!isset($this->skippedContactIds[$c['llg_id']])) $affiliateChanges[] = $c;
            }

            unset($chunk, $enrollmentData, $dropNames, $processedChunk, $newCatChanges, $newAffChanges);
            \gc_collect_cycles();

            $action = $dryRun ? 'would be upserted' : ($this->refreshStage !== null ? 'staged' : 'upserted');
            $elapsed = number_format(microtime(true) - $syncLoopStarted, 1);
            $this->logStep(
                "Page {$pageNum} complete — totals: {$totalFetched} fetched, {$totalInserted} {$action} | elapsed {$elapsed}s",
                $pageStarted
            );
        // Keep paging until a page comes back empty. The old condition
        // (chunkSize === PAGE_SIZE) silently ended the sync whenever a page came
        // back short for any reason, reporting success on a partial load.
        // DBConnector now throws on an incomplete fetch, so a short-but-non-empty
        // page here is legitimate and we must continue from the new cursor.
        } while ($chunkSize > 0);

        if ($this->refreshStage !== null) {
            $this->publishFullRefresh($totalInserted);
        }
        if ($this->mailerSuffixCacheReady) {
            $cleanup = $sqlConnector->querySqlServer('DROP TABLE IF EXISTS #TmpMailerSuffixCache');
            if (! ($cleanup['success'] ?? false)) {
                $this->warn('[WARN] Temporary mailer suffix cache will be removed when the SQL Server connection closes.');
            }
            $this->mailerSuffixCacheReady = false;
            $this->cachedMailerSuffixes = [];
        }
        $action = $dryRun ? 'would be upserted' : 'upserted';
        $this->info("[INFO] Completed: {$totalInserted} records {$action} into {$this->targetTable}.");
        if ($dryRun) {
            $this->printDebtPreviewSummary();
        }
        if ($this->option('debt-only')) {
            $this->info('[DRY RUN] Enrollment changes and post-sync matching were not evaluated in debt-only mode.');
        } else {
            $this->info("[INFO] Enrollment updates: " . \count($categoryChanges) . " category, " . \count($affiliateChanges) . " affiliate agent");
        }

        if (!$this->option('debt-only')) {
            $this->applyEnrollmentCategoryUpdates($sqlConnector, $categoryChanges);
            $this->applyEnrollmentAffiliateUpdates($sqlConnector, $affiliateChanges);
        }

        // When called from the orchestrator (no --source flag), matching is deferred
        // to handle() so it runs after ALL sources finish. Skip it here in that case.
        if (!$this->option('no-match') && !$this->option('debt-only') && ($dryRun || $this->skippedContactIds === [])) {
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
        if (!$dryRun && $this->skippedContactIds !== [] && !$this->option('no-match')) {
            $this->warn('[WARN] Post-sync matching was not run because some records were skipped.');
        }

        // Persist the watermark only after a fully successful run. An owners-refresh
        // is a wide corrective sweep, not a chronological checkpoint — leave the
        // incremental watermark untouched so the next scheduled incremental run
        // still picks up everything modified since the last real incremental.
        if (!$dryRun && $this->skippedContactIds !== []) {
            $this->warn('[WARN] Sync checkpoint retained so skipped records are retried on the next run.');
        } elseif (!$dryRun && !$ownersRefresh) {
            $this->writeLastSyncTime($this->source, $syncStartedAt);
            $this->info("[INFO] Sync watermark saved: {$syncStartedAt}");
        } elseif ($ownersRefresh) {
            $this->info('[INFO] Owners-refresh: incremental watermark left unchanged.');
        }

        if ($this->skippedContactIds !== []) {
            $this->warn('[SYNC FLAGS] ' . json_encode(['source' => $this->source, 'accepted' => $totalInserted,
                'skipped_ids' => count($this->skippedContactIds), 'flags' => count($this->contactFlags),
                'dry_run' => $dryRun, 'checkpoint_advanced' => false], JSON_THROW_ON_ERROR));
            $this->warn($dryRun ? '[DRY RUN] Preview completed with flagged records; no changes applied.'
                : '[WARN] Accepted records were processed; skipped records still require review.');
        } elseif ($this->contactFlags !== []) {
            $this->warn('[WARN] Source processing completed with review flags; see [CONTACT FLAG] details above.'
                . ($dryRun ? ' No changes applied.' : ''));
        } else {
            $this->info($dryRun ? "[SUCCESS] {$this->source} dry run completed; no changes applied."
                : "[SUCCESS] {$this->source} sync completed successfully!");
        }
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
        while (true) {
            $sql = $this->source === 'LT'
                ? $this->buildLTQuery($startDate, $lastId, $limit)
                : $this->buildStandardQuery($startDate, $lastId, $limit);

            try {
                $result = $snowflake->query($sql);
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $response = $e->getResponse();
                if ($response === null || $response->getStatusCode() !== 408
                    || !str_contains((string) $response->getBody(), '000630')
                    || $limit <= self::MIN_PAGE_SIZE) {
                    throw $e;
                }
                $nextLimit = max(self::MIN_PAGE_SIZE, intdiv($limit, 5));
                $this->warn("[WARN] Snowflake statement timed out at afterId={$lastId}, limit={$limit}; retrying the same cursor with limit={$nextLimit}.");
                $limit = $nextLimit;
                continue;
            }

            if (($result['success'] ?? true) === false || !isset($result['data']) || !is_array($result['data'])) {
                throw new \RuntimeException('Snowflake did not return a valid contact page.');
            }
            if (isset($result['rowCount']) && (int) $result['rowCount'] !== count($result['data'])) {
                throw new \RuntimeException('Snowflake contact page is incomplete; aborting the refresh.');
            }
            $this->pageSize = $limit;
            return $result['data'];
        }
    }

    /** The replication clock catches late arrivals even when the source edit is older than the watermark. */
    private function contactChangedSql(string $startDate): string
    {
        $cutoff = "'{$this->esc($startDate)}'::TIMESTAMP_NTZ";
        $contact = "CONVERT_TIMEZONE('America/Los_Angeles', COALESCE(c.MODIFIED, c.CREATED))::TIMESTAMP_NTZ >= {$cutoff}
            OR CONVERT_TIMEZONE('America/Los_Angeles', c._FIVETRAN_SYNCED)::TIMESTAMP_NTZ >= {$cutoff}";
        if ($this->source !== 'LT') {
            return "({$contact})";
        }
        // Assignment STAMP is NTZ, so do not perform a session-dependent two-argument conversion.
        // Include replicated tombstones in eligibility; exclude them when choosing the current history row.
        return "({$contact} OR EXISTS (
            SELECT 1 FROM CONTACTS_ASSIGNED delta
            WHERE delta.CONTACT_ID = c.ID
              AND (delta.STAMP >= {$cutoff}
                OR CONVERT_TIMEZONE('America/Los_Angeles', delta._FIVETRAN_SYNCED)::TIMESTAMP_NTZ >= {$cutoff})
        ))";
    }

    private function buildStandardQuery(string $startDate, int $lastId, int $limit): string
    {
        $changed = $this->contactChangedSql($startDate);
        // Page contacts first, then reduce every one-to-many source independently.
        // Joining history tables together before deduplication multiplies their rows
        // (for example, 8 statuses × 4 scores × 3 reports = 96 rows for one contact).
        return "
            WITH contact_page AS (
                SELECT c.*
                FROM CONTACTS AS c
                WHERE {$changed}
                  AND c.DEL = 'FALSE' AND c._FIVETRAN_DELETED = FALSE
                  AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> ''
                  AND c.ISCOAPP = 0
                  AND c.ID > {$lastId}
                ORDER BY c.ID
                LIMIT {$limit}
            ),
            page_status AS (
                SELECT s.CONTACT_ID, s.STAGE_ID, s.STATUS_ID, s.STAMP
                FROM CONTACTS_STATUS AS s
                JOIN contact_page AS p ON p.ID = s.CONTACT_ID
                WHERE s._FIVETRAN_DELETED = FALSE
                QUALIFY ROW_NUMBER() OVER (PARTITION BY s.CONTACT_ID ORDER BY s.STAMP DESC, s.ID DESC) = 1
            ),
            page_scores AS (
                SELECT cs.CONTACT_ID, cs.TRANSUNION
                FROM CREDIT_SCORES AS cs
                JOIN contact_page AS p ON p.ID = cs.CONTACT_ID
                QUALIFY ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.CREATED_AT DESC, cs.ID DESC) = 1
            ),
            page_credit_reports AS (
                SELECT cr.CONTACT_ID, cr.METADATA
                FROM CREDIT_REPORT_REQUEST AS cr
                JOIN contact_page AS p ON p.ID = cr.CONTACT_ID
                QUALIFY ROW_NUMBER() OVER (PARTITION BY cr.CONTACT_ID ORDER BY cr.CREATED_AT DESC, cr.ID DESC) = 1
            ),
            page_enrollment_plans AS (
                SELECT ep.CONTACT_ID, ep.PLAN_ID, ep.FEE1
                FROM ENROLLMENT_PLAN AS ep
                JOIN contact_page AS p ON p.ID = ep.CONTACT_ID
                QUALIFY ROW_NUMBER() OVER (PARTITION BY ep.CONTACT_ID ORDER BY ep.CREATED_AT DESC, ep.ID DESC) = 1
            ),
            page_enrolled_debt AS (
                SELECT d.CONTACT_ID, SUM(d.ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS AS d
                JOIN contact_page AS p ON p.ID = d.CONTACT_ID
                WHERE d.ENROLLED = 1 AND d._FIVETRAN_DELETED = FALSE
                GROUP BY d.CONTACT_ID
            ),
            page_debt_field AS (
                SELECT uf.CONTACT_ID, uf.F_DECIMAL
                FROM CONTACTS_USERFIELDS AS uf
                JOIN contact_page AS p ON p.ID = uf.CONTACT_ID
                WHERE uf.CUSTOM_ID = {$this->debtAmountCustomId}
                QUALIFY ROW_NUMBER() OVER (PARTITION BY uf.CONTACT_ID ORDER BY uf.ID DESC) = 1
            ),
            page_agent_field AS (
                SELECT uf.CONTACT_ID, uf.F_SHORTSTRING
                FROM CONTACTS_USERFIELDS AS uf
                JOIN contact_page AS p ON p.ID = uf.CONTACT_ID
                WHERE uf.CUSTOM_ID = {$this->agentCustomId}
                QUALIFY ROW_NUMBER() OVER (PARTITION BY uf.CONTACT_ID ORDER BY uf.ID DESC) = 1
            )
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
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE), 'YYYY-MM-DD HH24:MI:SS') AS ENROLLED_DATE,
                uf_debt.F_DECIMAL AS DEBT_AMOUNT_CUSTOM,
                d.ENROLLED_DEBT,
                ed.TITLE AS PLAN_TITLE,
                uf_agent.F_SHORTSTRING AS AGENT_CUSTOM
            FROM contact_page AS c
            LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN USERS AS u1 ON c.CREATED_BY = u1.UID AND u1._FIVETRAN_DELETED = FALSE
            LEFT JOIN USERS AS u2 ON c.ASSIGNED_TO = u2.UID AND u2._FIVETRAN_DELETED = FALSE
            LEFT JOIN page_status AS s ON c.ID = s.CONTACT_ID
            LEFT JOIN CONTACTS_CATEGORIES AS cc ON s.STAGE_ID = cc.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
            LEFT JOIN page_scores AS cs ON c.ID = cs.CONTACT_ID
            LEFT JOIN page_credit_reports AS cr ON c.ID = cr.CONTACT_ID
            LEFT JOIN page_enrolled_debt AS d ON c.ID = d.CONTACT_ID
            LEFT JOIN page_enrollment_plans AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            LEFT JOIN page_debt_field AS uf_debt ON c.ID = uf_debt.CONTACT_ID
            LEFT JOIN page_agent_field AS uf_agent ON c.ID = uf_agent.CONTACT_ID
            ORDER BY c.ID
        ";
    }

    private function buildLTQuery(string $startDate, int $lastId, int $limit): string
    {
        $changed = $this->contactChangedSql($startDate);
        // Apply the keyset page before reading histories and reduce each history
        // independently. EXISTS keeps pages full of duplicate leads from hiding
        // later eligible contacts. Every joined CTE returns at most one row/contact.
        return "
            WITH contact_page AS (
                SELECT c.*
                FROM CONTACTS c
                WHERE {$changed}
                  AND c.DEL = 'FALSE' AND c._FIVETRAN_DELETED = FALSE
                  AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> ''
                  AND c.ISCOAPP = 0
                  AND c.ID > {$lastId}
                  AND EXISTS (
                      SELECT 1 FROM CONTACTS_STATUS eligible_status
                      JOIN CONTACTS_LEAD_STATUS eligible_lead ON eligible_status.STATUS_ID = eligible_lead.ID
                      WHERE eligible_status.CONTACT_ID = c.ID
                        AND eligible_lead.TITLE <> 'Duplicate Lead'
                        AND eligible_status._FIVETRAN_DELETED = FALSE
                        AND eligible_lead._FIVETRAN_DELETED = FALSE
                  )
                ORDER BY c.ID
                LIMIT {$limit}
            ),
            page_assignment AS (
                SELECT a.CONTACT_ID, a.STAMP
                FROM CONTACTS_ASSIGNED AS a
                JOIN contact_page AS p ON p.ID = a.CONTACT_ID
                WHERE a._FIVETRAN_DELETED = FALSE
                QUALIFY ROW_NUMBER() OVER (PARTITION BY a.CONTACT_ID ORDER BY a.STAMP DESC, a.ID DESC) = 1
            ),
            page_status AS (
                SELECT s.CONTACT_ID, s.STAGE_ID, s.STATUS_ID, s.STAMP
                FROM CONTACTS_STATUS AS s
                JOIN contact_page AS p ON p.ID = s.CONTACT_ID
                JOIN CONTACTS_LEAD_STATUS AS eligible_cls ON eligible_cls.ID = s.STATUS_ID
                WHERE eligible_cls.TITLE <> 'Duplicate Lead'
                  AND s._FIVETRAN_DELETED = FALSE AND eligible_cls._FIVETRAN_DELETED = FALSE
                QUALIFY ROW_NUMBER() OVER (PARTITION BY s.CONTACT_ID ORDER BY s.STAMP DESC, s.ID DESC) = 1
            ),
            page_scores AS (
                SELECT cs.CONTACT_ID, cs.TRANSUNION
                FROM CREDIT_SCORES AS cs
                JOIN contact_page AS p ON p.ID = cs.CONTACT_ID
                QUALIFY ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.CREATED_AT DESC, cs.ID DESC) = 1
            ),
            page_credit_reports AS (
                SELECT cr.CONTACT_ID, cr.METADATA
                FROM CREDIT_REPORT_REQUEST AS cr
                JOIN contact_page AS p ON p.ID = cr.CONTACT_ID
                QUALIFY ROW_NUMBER() OVER (PARTITION BY cr.CONTACT_ID ORDER BY cr.CREATED_AT DESC, cr.ID DESC) = 1
            ),
            page_enrollment_plans AS (
                SELECT ep.CONTACT_ID, ep.PLAN_ID, ep.FEE1
                FROM ENROLLMENT_PLAN AS ep
                JOIN contact_page AS p ON p.ID = ep.CONTACT_ID
                QUALIFY ROW_NUMBER() OVER (PARTITION BY ep.CONTACT_ID ORDER BY ep.CREATED_AT DESC, ep.ID DESC) = 1
            ),
            page_debt_field AS (
                SELECT uf.CONTACT_ID, uf.F_SHORTSTRING
                FROM CONTACTS_USERFIELDS AS uf
                JOIN contact_page AS p ON p.ID = uf.CONTACT_ID
                WHERE uf.CUSTOM_ID = {$this->debtAmountCustomId}
                QUALIFY ROW_NUMBER() OVER (PARTITION BY uf.CONTACT_ID ORDER BY uf.ID DESC) = 1
            )
            SELECT
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.CREATED), 'YYYY-MM-DD HH24:MI:SS') AS CREATED,
                TO_CHAR(a.STAMP, 'YYYY-MM-DD HH24:MI:SS') AS ASSIGNED_ON,
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
                TO_CHAR(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE), 'YYYY-MM-DD HH24:MI:SS') AS ENROLLED_DATE,
                uf_debt.F_SHORTSTRING AS DEBT_AMOUNT_CUSTOM,
                NULL AS PLAN_TITLE,
                NULL AS AGENT_CUSTOM
            FROM contact_page AS c
            LEFT JOIN page_assignment AS a ON c.ID = a.CONTACT_ID
            LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN USERS AS u1 ON c.CREATED_BY = u1.UID AND u1._FIVETRAN_DELETED = FALSE
            LEFT JOIN USERS AS u2 ON c.ASSIGNED_TO = u2.UID AND u2._FIVETRAN_DELETED = FALSE
            JOIN page_status AS s ON c.ID = s.CONTACT_ID
            LEFT JOIN CONTACTS_CATEGORIES AS cc ON s.STAGE_ID = cc.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
            LEFT JOIN page_scores AS cs ON c.ID = cs.CONTACT_ID
            LEFT JOIN page_credit_reports AS cr ON c.ID = cr.CONTACT_ID
            LEFT JOIN page_enrollment_plans AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN page_debt_field AS uf_debt ON c.ID = uf_debt.CONTACT_ID
            ORDER BY c.ID
        ";
    }

    /**
     * Loads enrollment data scoped to enrolled contacts in this chunk.
     * Bounded SELECTs are identical for preview and execution; lookup failures abort the run.
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

        $result = ['data' => []];
        foreach (array_chunk(array_unique($enrolledIds), 1000) as $batch) {
            $ids = $this->sqlStringList(array_map(fn($id) => 'LLG-' . $id, $batch));
            $rows = $this->selectPreviewRows($connector,
                "SELECT LLG_ID, Category, Agent, Affiliate_Agent FROM TblEnrollment
                 WHERE LLG_ID IN ({$ids}) AND Category NOT IN ('', 'FDR', 'CSS', 'CNI')");
            array_push($result['data'], ...$rows);
        }

        $categories      = [];
        $assignedAgents  = [];
        $affiliateAgents = [];
        $duplicates = [];

        foreach ($result['data'] ?? [] as $row) {
            $llgId = $row['LLG_ID'] ?? '';
            if (preg_match('/LLG-(\d+)/', $llgId, $matches)) {
                $contactId                   = $matches[1];
                if (isset($duplicates[$contactId])) continue;
                if (array_key_exists($contactId, $categories)) {
                    $duplicates[$contactId] = true;
                    unset($categories[$contactId], $assignedAgents[$contactId], $affiliateAgents[$contactId]);
                    $this->recordContactFlag(['id' => $llgId, 'code' => 'duplicate_enrollment_target',
                        'message' => 'Duplicate enrollment rows; dependent updates were skipped.'], true);
                    continue;
                }
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
        $lookup = [];
        $suffixLookup = [];

        if ($this->option('dry-run')) {
            foreach (array_chunk($externalIds, 1000) as $batch) {
                $ids = $this->sqlStringList(array_map(fn($id) => substr((string) $id, 0, 50), $batch));
                $started = microtime(true);
                $this->info('[PREVIEW] Mailer exact lookup starting (' . count($batch) . ' IDs).');
                $rows = $this->selectPreviewRows($connector,
                    "SELECT External_ID, Drop_Name FROM TblMailers WHERE External_ID IN ({$ids}) AND Drop_Name IS NOT NULL");
                $this->info(sprintf('[PREVIEW] Mailer exact lookup finished (%.1fs).', microtime(true) - $started));
                $this->mergeDropNameLookup($lookup, $rows);
            }
            $missTails = [];
            foreach ($externalIds as $extId) {
                if (!array_key_exists($extId, $lookup) && strlen((string) $extId) > 9) {
                    $tail = substr((string) $extId, -9);
                    $missTails[$tail] = true;
                }
            }
            foreach (array_chunk(array_keys($missTails), 500) as $batch) {
                $tails = $this->sqlStringList($batch);
                $started = microtime(true);
                $this->info('[PREVIEW] Mailer suffix lookup starting (' . count($batch) . ' suffixes).');
                $rows = $this->selectPreviewRows($connector,
                    "SELECT External_ID, Drop_Name FROM TblMailers WHERE External_ID IS NOT NULL
                     AND Drop_Name IS NOT NULL AND LEN(External_ID) > 9 AND RIGHT(External_ID, 9) IN ({$tails})");
                $this->info(sprintf('[PREVIEW] Mailer suffix lookup finished (%.1fs).', microtime(true) - $started));
                $this->mergeDropNameLookup($suffixLookup, $rows, true);
            }
            return $this->resolvedDropNames($chunk, $lookup, $suffixLookup);
        }

        // Exact match first; suffix matches are cached for the source run.
        $this->checkedSql($connector, "CREATE TABLE #TmpMailerFilter (ExtId VARCHAR(50) NOT NULL)");
        try {
            foreach (\array_chunk($externalIds, 1000) as $batch) {
                $values = \implode(', ', \array_map(
                    fn($id) => "('" . \str_replace("'", "''", \substr($id, 0, 50)) . "')",
                    $batch
                ));
                $this->checkedSql($connector, "INSERT INTO #TmpMailerFilter (ExtId) VALUES {$values}");
            }

            $exact = $this->checkedSql($connector, "
                SELECT m.External_ID, m.Drop_Name
                FROM TblMailers m
                INNER JOIN #TmpMailerFilter f ON m.External_ID = f.ExtId
                WHERE m.Drop_Name IS NOT NULL
            ");
            $this->mergeDropNameLookup($lookup, $exact['data'] ?? []);

            $missTails = [];
            foreach ($externalIds as $extId) {
                if (array_key_exists($extId, $lookup)) {
                    continue;
                }
                if (\strlen($extId) > 9) {
                    $tail = \substr($extId, -9);
                    $missTails[$tail] = true;
                }
            }

            foreach (\array_chunk(\array_keys($missTails), 500) as $tailBatch) {
                $this->loadMailerSuffixCache($connector, $tailBatch);
                $inList = \implode(', ', \array_map(
                    fn($t) => "'" . \str_replace("'", "''", $t) . "'",
                    $tailBatch
                ));
                $fallback = $this->checkedSql($connector,
                    "SELECT Suffix AS External_ID, Drop_Name FROM #TmpMailerSuffixCache WHERE Suffix IN ({$inList})"
                );
                if (! ($fallback['success'] ?? false)) {
                    throw new \RuntimeException('Temporary TblMailers suffix lookup failed: ' . ($fallback['error'] ?? 'unknown SQL Server error'));
                }
                $this->mergeDropNameLookup($suffixLookup, $fallback['data'] ?? [], true);
            }
        } finally {
            $this->checkedSql($connector, "DROP TABLE IF EXISTS #TmpMailerFilter");
        }

        return $this->resolvedDropNames($chunk, $lookup, $suffixLookup);
    }

    private function ensureMailerSuffixCache(DBConnector $connector): void
    {
        if ($this->mailerSuffixCacheReady) {
            return;
        }

        $create = $this->checkedSql($connector,
            "SET NOCOUNT ON;
             SELECT TOP (0) CAST(RIGHT(External_ID, 9) AS varchar(9)) AS Suffix, Drop_Name
             INTO #TmpMailerSuffixCache FROM TblMailers;
             SET NOCOUNT OFF;"
        );
        if (! ($create['success'] ?? false)) {
            throw new \RuntimeException('Unable to create temporary mailer suffix cache: ' . ($create['error'] ?? 'unknown SQL Server error'));
        }

        $index = $this->checkedSql($connector,
            'CREATE NONCLUSTERED INDEX IX_TmpMailerSuffixCache_Suffix ON #TmpMailerSuffixCache (Suffix)'
        );
        if (! ($index['success'] ?? false)) {
            throw new \RuntimeException('Unable to index temporary mailer suffix cache: ' . ($index['error'] ?? 'unknown SQL Server error'));
        }

        $this->mailerSuffixCacheReady = true;
        $this->info('[INFO] Created temporary TblMailers suffix cache for requested suffixes.');
    }

    private function loadMailerSuffixCache(DBConnector $connector, array $suffixes): void
    {
        $this->ensureMailerSuffixCache($connector);
        $requested = $this->pendingMailerSuffixes + array_fill_keys($suffixes, true);
        $missing = array_diff_key($requested, $this->cachedMailerSuffixes);
        if ($missing === []) {
            return;
        }

        $create = $this->checkedSql($connector,
            "SET NOCOUNT ON;
             SELECT TOP (0) CAST(RIGHT(External_ID, 9) AS varchar(9)) AS Suffix
             INTO #TmpMailerSuffixFilter FROM TblMailers;
             CREATE UNIQUE CLUSTERED INDEX IX_TmpMailerSuffixFilter_Suffix ON #TmpMailerSuffixFilter (Suffix);
             SET NOCOUNT OFF;"
        );
        if (! ($create['success'] ?? false)) {
            throw new \RuntimeException('Unable to create mailer suffix filter: ' . ($create['error'] ?? 'unknown SQL Server error'));
        }
        try {
            foreach (array_chunk(array_keys($missing), 1000) as $batch) {
                $values = implode(', ', array_map(fn($tail) => "('" . $this->escSql((string) $tail) . "')", $batch));
                $insert = $this->checkedSql($connector, "INSERT INTO #TmpMailerSuffixFilter (Suffix) VALUES {$values}");
                if (! ($insert['success'] ?? false)) {
                    throw new \RuntimeException('Unable to fill mailer suffix filter: ' . ($insert['error'] ?? 'unknown SQL Server error'));
                }
            }
            $populate = $this->checkedSql($connector,
                "INSERT INTO #TmpMailerSuffixCache (Suffix, Drop_Name)
                 SELECT CAST(RIGHT(m.External_ID, 9) AS varchar(9)), m.Drop_Name
                 FROM #TmpMailerSuffixFilter f
                 INNER HASH JOIN TblMailers m ON RIGHT(m.External_ID, 9) = f.Suffix
                 WHERE m.External_ID IS NOT NULL AND m.Drop_Name IS NOT NULL AND LEN(m.External_ID) > 9"
            );
            if (! ($populate['success'] ?? false)) {
                throw new \RuntimeException('Unable to populate temporary mailer suffix cache: ' . ($populate['error'] ?? 'unknown SQL Server error'));
            }
        } finally {
            $this->checkedSql($connector, 'DROP TABLE IF EXISTS #TmpMailerSuffixFilter');
        }

        $this->cachedMailerSuffixes += $missing;
        $this->pendingMailerSuffixes = [];
        $this->info('[INFO] Cached mailer lookup for ' . count($missing) . ' requested suffix(es).');
    }

    private function fetchMailerSuffixes(DBConnector $snowflake, string $startDate): array
    {
        $changed = $this->contactChangedSql($startDate);
        $result = $snowflake->query(
            "SELECT DISTINCT c.TP_ID AS EXTERNAL_ID FROM CONTACTS c
             WHERE {$changed}
               AND c.DEL = 'FALSE' AND c._FIVETRAN_DELETED = FALSE AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> ''
               AND c.ISCOAPP = 0 AND c.ID > 0"
        );
        if (($result['success'] ?? true) === false || !isset($result['data']) || !is_array($result['data'])
            || (isset($result['rowCount']) && (int) $result['rowCount'] !== count($result['data']))) {
            throw new \RuntimeException('Snowflake did not return a complete mailer suffix list.');
        }
        $suffixes = [];
        foreach ($result['data'] as $row) {
            if (!array_key_exists('EXTERNAL_ID', $row)) {
                throw new \RuntimeException('Snowflake mailer suffix list is missing EXTERNAL_ID.');
            }
            $externalId = trim((string) $row['EXTERNAL_ID']);
            if (strlen($externalId) > 9) {
                $suffixes[substr($externalId, -9)] = true;
            }
        }
        $this->info('[INFO] Planned ' . count($suffixes) . ' mailer suffix(es) for this source run.');
        return $suffixes;
    }

    /** Conflicting campaign values stay ambiguous regardless of result order. */
    private function mergeDropNameLookup(array &$lookup, array $rows, bool $suffix = false): void
    {
        foreach ($rows as $row) {
            $externalId = (string) ($row['External_ID'] ?? '');
            $dropName   = (string) ($row['Drop_Name'] ?? '');
            if ($externalId === '' || $dropName === '') {
                continue;
            }
            $key = $suffix ? substr($externalId, -9) : $externalId;
            $lookup[$key] = array_key_exists($key, $lookup) && $lookup[$key] !== $dropName ? null : $dropName;
        }
    }

    private function resolvedDropNames(array $chunk, array $exact, array $suffixes): array
    {
        $resolved = [];
        foreach ($chunk as $row) {
            $id = trim((string) ($row['EXTERNAL_ID'] ?? ''));
            if ($id === '' || $this->isFakeExternalId($id)) continue;
            $tail = substr($id, -9);
            $value = array_key_exists($id, $exact) ? $exact[$id]
                : (array_key_exists($tail, $suffixes) ? $suffixes[$tail] : '');
            if ($value === null) {
                $this->recordContactFlag(['id' => 'LLG-' . ($row['LLG_ID'] ?? ''), 'code' => 'ambiguous_mailer_campaign',
                    'message' => 'Conflicting mailer campaign values; contact was skipped without choosing a campaign.'], true);
                continue;
            }
            if ($value !== '') $resolved[$id] = $value;
        }
        return $resolved;
    }

    // -------------------------------------------------------------------------
    // Processing
    // -------------------------------------------------------------------------

    /** Loan/requested amount and enrolled debt are deliberately separate. */
    private function resolveDebtValues(array $row): array
    {
        $raw = $row['DEBT_AMOUNT_CUSTOM'] ?? null;
        $loan = is_numeric($raw) ? (float) $raw : 0.0;
        $validLoan = is_finite($loan) && $loan > 0 && $loan <= self::MAX_LOAN_AMOUNT;

        if ($this->source === 'LT') {
            if (!$validLoan) {
                Log::warning('SyncContactsData: ignored invalid LT loan amount', [
                    'contact_id' => $row['LLG_ID'] ?? '',
                    'value' => $raw ?? 0,
                ]);
            }
            $amount = $validLoan ? $loan : 0.0;
            return ['amount' => floor($amount / 1000) * 1000, 'enrolled' => $amount,
                'basis' => $validLoan ? 'loan' : 'no_debt'];
        }

        // A missing SELECT alias must fail instead of silently recreating the zero-debt bug.
        if (!array_key_exists('ENROLLED_DEBT', $row)) {
            throw new \RuntimeException('Snowflake result is missing ENROLLED_DEBT; debt mapping aborted.');
        }
        $rawEnrolled = $row['ENROLLED_DEBT'];
        if ($rawEnrolled !== null && (!is_numeric($rawEnrolled) || !is_finite((float) $rawEnrolled))) {
            throw new \RuntimeException('Snowflake returned a nonnumeric ENROLLED_DEBT value.');
        }
        $enrolled = (float) ($rawEnrolled ?? 0);
        $amount = $validLoan ? $loan : $enrolled;

        return ['amount' => floor($amount / 1000) * 1000, 'enrolled' => $enrolled,
            'basis' => $validLoan ? 'loan' : ($enrolled > 0 ? 'enrolled_fallback' : 'no_debt')];
    }

    private function checkedSql(DBConnector $connector, string $sql): array
    {
        $result = $connector->querySqlServer($sql);
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('Contact lookup SQL failed: ' . ($result['error'] ?? 'unknown error'));
        }
        return $result;
    }

    /** Only SELECTs, including when the normal sync uses temporary lookup tables. */
    private function selectPreviewRows(DBConnector $connector, string $sql): array
    {
        $result = $connector->querySqlServer($sql);
        if (!($result['success'] ?? false)) {
            throw new \RuntimeException('Dry-run SQL read failed: ' . ($result['error'] ?? 'unknown error'));
        }
        return $result['data'] ?? [];
    }

    private function sqlStringList(array $values): string
    {
        return implode(', ', array_map(fn($value) => "'" . $this->escSql((string) $value) . "'", $values));
    }

    private function previewDebtChunk(DBConnector $connector, array $rows): int
    {
        $accepted = 0;
        foreach (array_chunk($rows, 1000) as $batch) {
            $ids = $this->sqlStringList(array_column($batch, 'llg_id'));
            $existing = $this->selectPreviewRows($connector,
                "SELECT LLG_ID, Debt_Amount, Debt_Enrolled FROM {$this->targetTable} WHERE LLG_ID IN ({$ids})");
            $lookup = [];
            $duplicates = [];
            foreach ($existing as $old) {
                $id = (string) $old['LLG_ID'];
                if (isset($duplicates[$id])) continue;
                if (isset($lookup[$id])) {
                    $duplicates[$id] = true;
                    unset($lookup[$id]);
                    $this->recordContactFlag(['id' => $id, 'code' => 'duplicate_debt_target',
                        'message' => 'Duplicate contact rows; debt comparison was skipped.'], true);
                    continue;
                }
                $lookup[$id] = $old;
            }
            foreach ($batch as $row) {
                if (isset($this->skippedContactIds[$row['llg_id']])) continue;
                $this->recordDebtPreview($row, isset($lookup[$row['llg_id']]) ? array_change_key_case($lookup[$row['llg_id']], CASE_LOWER) : null);
                $accepted++;
            }
        }
        $this->info(sprintf('[DRY RUN][%s] Debt comparison: %d processed, %d existing changed, %d unchanged, %d new.',
            $this->source, $this->debtPreview['processed'], $this->debtPreview['changed'],
            $this->debtPreview['unchanged'], $this->debtPreview['new']));
        return $accepted;
    }

    private function recordDebtPreview(array $row, ?array $old): void
    {
        $this->debtPreview['processed']++;
        $this->debtPreview[$row['debt_basis']]++;
        if ($old === null) {
            $this->debtPreview['new']++;
            return;
        }
        $amountChanged = $old['debt_amount'] === null
            || round((float) $old['debt_amount'], 2) !== round((float) $row['debt_amount'], 2);
        $enrolledChanged = $old['debt_enrolled'] === null
            || round((float) $old['debt_enrolled'], 2) !== round((float) $row['debt_enrolled'], 2);
        $this->debtPreview['amount_changed'] += (int) $amountChanged;
        $this->debtPreview['enrolled_changed'] += (int) $enrolledChanged;
        $this->debtPreview[$amountChanged || $enrolledChanged ? 'changed' : 'unchanged']++;
        if (($amountChanged || $enrolledChanged) && $this->debtPreview['samples'] < 10) {
            $this->debtPreview['samples']++;
            $format = fn($value) => $value === null ? 'NULL' : number_format((float) $value, 2, '.', '');
            $this->line(sprintf('[DEBT CHANGE] %s Debt_Amount %s => %s; Debt_Enrolled %s => %s; basis=%s',
                $row['llg_id'], $format($old['debt_amount']), $format($row['debt_amount']),
                $format($old['debt_enrolled']), $format($row['debt_enrolled']), $row['debt_basis']));
        }
    }

    private function printDebtPreviewSummary(): void
    {
        $this->info("[DRY RUN][{$this->source}] FINAL DEBT SUMMARY (selected source rows only)");
        $labels = ['processed' => 'Proposed source rows', 'loan' => 'Valid loan amount used',
            'enrolled_fallback' => 'Enrolled-debt fallback used', 'no_debt' => 'No positive fallback debt',
            'changed' => 'Existing rows with either debt column changed',
            'amount_changed' => 'Existing Debt_Amount values changed',
            'enrolled_changed' => 'Existing Debt_Enrolled values changed',
            'unchanged' => 'Existing rows with both debt columns unchanged', 'new' => 'New target IDs'];
        foreach ($labels as $key => $label) {
            $this->line(sprintf('  %-51s %s', $label, number_format($this->debtPreview[$key])));
        }
        $this->line('[DRY RUN] Comparisons are live snapshots. New IDs are excluded from changed counts; target rows outside this source selection are not compared.');
        $this->info('[DRY RUN] Writes performed: 0. No cache lock, temp tables, target/enrollment updates, or watermark changes.');
    }
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

        // Filter explicit excluded records, then verify native source IDs.
        // External/partner IDs are allowed to repeat and never choose a winning person.
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
            $tpId = trim((string) ($row['EXTERNAL_ID'] ?? ''));
            if ($this->isFakeExternalId($tpId)) {
                $tpId = '';
            }
            $debt = $this->resolveDebtValues($row);
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
                $campaign = $dropNames[$tpId] ?? '';
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
                'debt_amount'        => $debt['amount'],
                'debt_enrolled'      => $debt['enrolled'],
                'debt_basis'         => $debt['basis'],
                'credit_score'       => $row['CREDIT_SCORE'] ?? 0,
                'credit_utilization' => $creditUtil,
                'category'           => $category,
                'affiliate_agent'    => \substr($this->source === 'LT' ? $agent : $assignedTo, 0, 255),
            ];

            $processed[] = $processedRow;

            // Enrollment change detection in the same pass
            $enrolledDate = $row['ENROLLED_DATE'] ?? '';
            if (!empty($enrolledDate) && isset($existingCategories[$contactId]) && $category !== '') {
                $llgId = "LLG-{$contactId}";
                if ($existingCategories[$contactId] !== $category) {
                    $categoryChanges[] = ['llg_id' => $llgId, 'category' => $category, 'before' => $existingCategories[$contactId], 'client' => $processedRow['client'], 'email' => $processedRow['email'], 'phone' => $processedRow['phone']];
                }
                if ($category !== 'LDR') {
                    $existingAffiliate = $existingAffiliates[$contactId] ?? '';
                    if ($agent !== '' && $existingAffiliate !== $agent && !\str_ends_with(\strtolower($agent), ' user')) {
                        $affiliateChanges[] = ['llg_id' => $llgId, 'agent' => $agent, 'before' => $existingAffiliate, 'client' => $processedRow['client'], 'email' => $processedRow['email'], 'phone' => $processedRow['phone']];
                    }
                }
            }
        }

        return [$processed, $categoryChanges, $affiliateChanges];
    }

    // -------------------------------------------------------------------------
    // Insertion
    // -------------------------------------------------------------------------

    /** Same SELECT-based target decisions as dry-run; commit one validated chunk. */
    private function insertChunk(DBConnector $connector, array $data, bool $incremental = false): int
    {
        $data = array_values(array_filter($data, fn ($row) => !isset($this->skippedContactIds[$row['llg_id']])));
        if ($data === []) {
            return 0;
        }
        $pdo = $connector->getSqlServerConnection();
        // Read source corroboration before taking any SQL Server write locks.
        $evidence = $this->targetTable === $this->refreshStage ? null : $this->contactEvidence($connector, $data);
        if (!$pdo->beginTransaction()) {
            throw new \RuntimeException('Could not begin contact upsert transaction.');
        }
        try {
            // Staging only receives rows already planned against the real destination.
            if ($this->targetTable === $this->refreshStage) {
                $this->insertContactRows($pdo, $this->contactFields(), $data);
                $count = count($data);
            } else {
                $plan = $this->acceptedContactPlan(ContactSyncTargets::plan($pdo, $data, $this->source, true, $evidence, true));
                $count = ContactSyncTargets::apply($pdo, $plan, $this->source);
            }
            if (!$pdo->commit()) {
                throw new \RuntimeException('Contact upsert commit failed.');
            }
            return $count;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function previewContactChunk(DBConnector $connector, array $rows): int
    {
        $rows = array_values(array_filter($rows, fn ($row) => !isset($this->skippedContactIds[$row['llg_id']])));
        $evidence = $this->contactEvidence($connector, $rows);
        $plan = $this->acceptedContactPlan(ContactSyncTargets::plan($connector->getSqlServerConnection(), $rows, $this->source, false, $evidence, true));
        $byId = array_column($rows, null, 'llg_id');
        foreach ($plan as $change) {
            $debtRow = $byId[$change['incoming_id']];
            $debtRow['llg_id'] = $change['target_id'];
            $this->recordDebtPreview($debtRow, $change['before']);
            $this->line('[PREVIEW] ' . json_encode([
                'source' => $this->source, 'table' => $this->targetTable,
                'incoming_id' => $change['incoming_id'], 'target_id' => $change['target_id'],
                'action' => $change['before'] === null ? 'insert' : ($change['changes'] === [] ? 'unchanged' : 'update'),
                'changes' => $change['changes'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (isset($change['linked_identity'])) {
                $identity = $change['linked_identity'];
                $this->line('[PREVIEW] ' . json_encode([
                    'source' => $this->source, 'table' => 'TblContacts',
                    'incoming_id' => $identity['incoming_id'], 'target_id' => $identity['target_id'],
                    'action' => 'update', 'changes' => $identity['changes'],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
        }
        return count($plan);
    }

    private function acceptedContactPlan(array $plan): array
    {
        $accepted = [];
        foreach ($plan as $change) {
            $skip = (bool) ($change['skip'] ?? false);
            $warnings = $change['warnings'] ?? [];
            if ($skip && $warnings === []) {
                $warnings[] = ['code' => 'contact_conflict', 'message' => 'Contact identity or ownership requires review.'];
            }
            foreach ($warnings as $warning) {
                $this->recordContactFlag(['id' => $change['incoming_id']] + $warning, $skip);
            }
            if (!$skip) $accepted[] = $change;
        }
        return $accepted;
    }

    /** Identity values and evidence tokens never belong in a record flag. */
    private function recordContactFlag(array $warning, bool $skip): void
    {
        $flag = ['source' => $this->source ?? 'UNKNOWN', 'id' => (string) ($warning['id'] ?? ''),
            'code' => (string) ($warning['code'] ?? 'contact_conflict'),
            'message' => (string) ($warning['message'] ?? 'Record requires review.'), 'skipped' => $skip];
        foreach (['winner', 'duplicates', 'candidates', 'stale_candidates'] as $key) {
            if (!isset($warning[$key]) || !is_array($warning[$key])) continue;
            $ids = $key === 'winner' ? [$warning[$key]] : $warning[$key];
            $ids = array_map(fn ($item) => ['source' => (string) ($item['source'] ?? ''),
                'id' => (string) ($item['id'] ?? '')], $ids);
            $flag[$key] = $key === 'winner' ? $ids[0] : $ids;
        }
        if ($skip) $this->skippedContactIds[$flag['id']] = true;
        $key = $flag['source'] . ':' . $flag['id'] . ':' . $flag['code'];
        if (!isset($this->contactFlags[$key])) {
            $this->contactFlags[$key] = $flag;
            if (isset($this->output)) $this->warn('[CONTACT FLAG] ' . json_encode($flag, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
    }

    protected function contactEvidence(DBConnector $connector, array $rows): ?ContactSyncSourceEvidence
    {
        $requests = ContactSyncTargets::evidenceRequests($connector->getSqlServerConnection(), $rows, $this->source);
        if ($requests === []) {
            return null;
        }
        $sources = [];
        return ContactSyncSourceEvidence::collect($requests, function (string $source, string $sql) use (&$sources): array {
            $sources[$source] ??= DBConnector::fromEnvironment(strtolower($source));
            $result = $sources[$source]->query($sql, [], 60);
            if (!isset($result['data']) || !is_array($result['data'])
                || (isset($result['rowCount']) && (int) $result['rowCount'] !== count($result['data']))) {
                throw new \RuntimeException('Incomplete contact identity evidence response.');
            }
            return $result['data'];
        });
    }

    private function insertContactRows(\PDO $pdo, string $fields, array $data): void
    {
        foreach (\array_chunk($data, 1000) as $batch) {
            $valuesParts = [];

            foreach ($batch as $row) {
                $createdDate  = $row['created_date'] ? "'{$row['created_date']}'" : 'NULL';
                $assignedDate = $row['assigned_date'] ? "'{$row['assigned_date']}'" : 'NULL';
                $email        = \strpos((string) ($row['email'] ?? ''), '@') !== false
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
        $this->applyEnrollmentFieldChanges($connector, $changes, 'Category', 'category');
    }

    private function applyEnrollmentAffiliateUpdates(DBConnector $connector, array $changes): void
    {
        $this->applyEnrollmentFieldChanges($connector, $changes, 'Affiliate_Agent', 'agent');
    }

    private function applyEnrollmentFieldChanges(DBConnector $connector, array $changes, string $field, string $key): void
    {
        $changes = array_values(array_filter($changes, fn ($change) => !isset($this->skippedContactIds[$change['llg_id']])));
        $name = $this->contactNameMatchSql('e', 'u');
        $identity = ContactSyncIdentity::sql('c', 'u');
        $owner = ContactSyncMatching::enrollmentOwnerSql('c');
        foreach (array_chunk($changes, 100) as $batch) {
            $values = [];
            $params = [];
            foreach ($batch as $change) {
                $values[] = '(?, ?, ?, ?, ?, ?)';
                array_push($params, $change['llg_id'], $change['client'], $change['email'], $change['phone'], $change['before'], $change[$key]);
            }
            $from = 'FROM TblEnrollment e JOIN (VALUES ' . implode(', ', $values) . ") u(LLG_ID, Client, Email, Phone, BeforeValue, AfterValue)
                ON e.LLG_ID = u.LLG_ID AND {$name}
                WHERE COALESCE(e.{$field}, '') = u.BeforeValue
                  AND (SELECT COUNT(*) FROM TblEnrollment d WHERE d.LLG_ID = e.LLG_ID) = 1
                  AND (SELECT COUNT(*) FROM TblContacts c WHERE c.LLG_ID = e.LLG_ID) = 1
                  AND EXISTS (SELECT 1 FROM TblContacts c WHERE c.LLG_ID = e.LLG_ID AND {$identity} AND {$owner})";
            if ($this->option('dry-run')) {
                $sql = "SELECT e.LLG_ID, e.{$field} AS BeforeValue, u.AfterValue {$from}";
                $result = $connector->querySqlServer($sql, $params);
                if (!($result['success'] ?? false)) {
                    throw new \RuntimeException("Enrollment {$field} preview failed.");
                }
                foreach ($result['data'] ?? [] as $row) {
                    $this->line('[ENROLLMENT PREVIEW] ' . json_encode(['field' => $field, 'change' => $row], JSON_THROW_ON_ERROR));
                }
                $verified = array_fill_keys(array_column($result['data'] ?? [], 'LLG_ID'), true);
                foreach ($batch as $change) {
                    if (!isset($verified[$change['llg_id']])) {
                        $this->recordContactFlag(['id' => $change['llg_id'], 'code' => 'enrollment_' . strtolower($field) . '_conflict',
                            'message' => 'Enrollment identity or expected value did not verify; proposed update was skipped.'], true);
                    }
                }
                continue;
            }
            $pdo = $connector->getSqlServerConnection();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare("UPDATE e SET e.{$field} = u.AfterValue {$from}");
                if ($statement === false || !$statement->execute($params) || $statement->rowCount() !== count($batch)) {
                    throw new \RuntimeException("Enrollment {$field} changed or write count mismatch.");
                }
                if (!$pdo->commit()) {
                    throw new \RuntimeException("Enrollment {$field} commit failed.");
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Table maintenance
    // -------------------------------------------------------------------------

    private function contactFields(): string
    {
        return 'Created_Date, Assigned_Date, LLG_ID, External_ID, Campaign, Data_Source, '
            . 'Created_By, Agent, Client, Phone, Email, Address_1, Address_2, City, State, '
            . 'Zip, Stage, Status, Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, '
            . 'Category, Affiliate_Agent';
    }

    private function checkedExec(\PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) {
            throw new \RuntimeException('SQL operation failed: ' . ($pdo->errorInfo()[2] ?? 'unknown error'));
        }
    }

    private function refreshRowCount(\PDO $pdo, string $table): int
    {
        $statement = $pdo->query("SELECT COUNT_BIG(*) FROM {$table}");
        if ($statement === false || ($count = $statement->fetchColumn()) === false) {
            throw new \RuntimeException('Unable to verify full-refresh row count.');
        }
        return (int) $count;
    }

    private function beginFullRefresh(DBConnector $connector): void
    {
        $this->refreshPublished = false;
        $this->refreshPdo = $connector->getSqlServerConnection();
        $this->refreshStage = '#ContactsRefresh_' . bin2hex(random_bytes(8));
        $fields = $this->contactFields();
        $this->checkedExec($this->refreshPdo,
            "SELECT TOP (0) {$fields} INTO {$this->refreshStage} FROM {$this->targetTable}");
        $this->checkedExec($this->refreshPdo,
            "CREATE UNIQUE INDEX RefreshContactId ON {$this->refreshStage} (LLG_ID)");
        $this->info("[FULL REFRESH] Staging {$this->source}; {$this->targetTable} is unchanged until all pages succeed.");
    }

    private function stageFullRefreshChunk(DBConnector $connector, array $rows): int
    {
        $target = $this->targetTable;
        $rows = array_values(array_filter($rows, fn ($row) => !isset($this->skippedContactIds[$row['llg_id']])));
        $evidence = $this->contactEvidence($connector, $rows);
        $plan = $this->acceptedContactPlan(ContactSyncTargets::plan($connector->getSqlServerConnection(), $rows, $this->source, false, $evidence, true));
        $rows = array_column($plan, 'after');
        try {
            $this->targetTable = $this->refreshStage;
            return $this->insertChunk($connector, $rows, false);
        } finally {
            $this->targetTable = $target;
        }
    }

    private function publishFullRefresh(int $expectedRows): void
    {
        $pdo = $this->refreshPdo;
        $stage = $this->refreshStage;
        if ($pdo === null || $stage === null || $expectedRows < 1) {
            throw new \RuntimeException('Full refresh returned no contacts; refusing to clear the target.');
        }
        if ($this->refreshRowCount($pdo, $stage) !== $expectedRows) {
            throw new \RuntimeException('Staged contact count mismatch; target left unchanged.');
        }
        $this->info("[FULL REFRESH] All pages staged ({$expectedRows} rows). Replacing {$this->targetTable} in one transaction...");
        if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
            throw new \RuntimeException('Could not start the full-refresh replacement transaction.');
        }
        try {
            // DELETE is transactional and works when TRUNCATE is disallowed.
            $this->checkedExec($pdo, "DELETE FROM {$this->targetTable} WITH (TABLOCKX)");
            $fields = $this->contactFields();
            $this->checkedExec($pdo,
                "INSERT INTO {$this->targetTable} ({$fields}) SELECT {$fields} FROM {$stage}");
            if ($this->refreshRowCount($pdo, $this->targetTable) !== $expectedRows) {
                throw new \RuntimeException('Replacement row count mismatch.');
            }
            if (!$pdo->commit()) {
                throw new \RuntimeException('Full-refresh commit failed.');
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $this->refreshPublished = true;
        $this->info("[FULL REFRESH] Committed {$expectedRows} contacts to {$this->targetTable}.");
        // Drop staging before enrollment/matching. A later failure must not claim
        // the contact replacement was rolled back after it has committed.
        $this->checkedExec($pdo, "DROP TABLE {$stage}");
        $this->refreshStage = null;
        $this->refreshPdo = null;
    }

    private function updateRelatedTables(DBConnector $connector): bool
    {
        if ($this->source === 'LT') {
            return true;
        }

        if ($this->option('dry-run')) {
            return $this->previewMatching($connector, [$this->targetTable]);
        }

        $this->resetMatchingStats(6);
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

        $this->resetMatchingStats(10);
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
     * Read-only preview: exact current target/field differences plus Jacob gap queries.
     * No UPDATE statements are executed.
     */
    private function previewMatching(DBConnector $connector, array $tables): bool
    {
        // Current-state previews cannot simulate subsequent matching steps without writing.
        $this->resetMatchingStats((\count($tables) * 4) + 2 + 6);
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
        $source = $table === 'TblContactsLDR' ? 'LDR' : 'PLAW';
        foreach (ContactSyncMatching::sourceSteps($source) as $step => $sql) {
            $this->previewMatchingRows($connector, "{$table}.{$step}", $sql['preview']);
        }
    }

    private function previewEnrollmentAgentFixes(DBConnector $connector): void
    {
        foreach (ContactSyncMatching::enrollmentSteps((bool) $this->option('reconcile-agents')) as $step => $sql) {
            $this->previewMatchingRows($connector, "enrollment.{$step}", $sql['preview']);
        }
    }

    private function previewMatchingRows(DBConnector $connector, string $step, string $sql): void
    {
        $rows = $this->selectPreviewRows($connector, $sql);
        $this->matchingStepNumber++;
        $this->matchingStepsOk++;
        $this->matchingRowsAffected += count($rows);
        foreach ($rows as $row) {
            $this->line('[MATCH PREVIEW] ' . json_encode(['step' => $step, 'change' => $row], JSON_THROW_ON_ERROR));
        }
        $this->info("[VERIFY] {$step}: " . count($rows) . ' currently eligible changes.');
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
        $source = $table === 'TblContactsLDR' ? 'LDR' : 'PLAW';
        foreach (ContactSyncMatching::sourceSteps($source) as $step => $sql) {
            if (!$this->runMatchingStep($connector, "{$table}.{$step}", $sql['update'], "{$table}: {$step}")) {
                throw new \RuntimeException("Matching failed at {$table}.{$step}; watermark must not advance.");
            }
        }
    }

    private function fillEnrollmentAgents(DBConnector $connector, bool $reconcileAgents = false): void
    {
        foreach (ContactSyncMatching::enrollmentSteps($reconcileAgents) as $step => $sql) {
            if (!$this->runMatchingStep($connector, "enrollment.{$step}", $sql['update'], "Enrollment: {$step}")) {
                throw new \RuntimeException("Enrollment matching failed at {$step}; watermark must not advance.");
            }
        }
    }

    /**
     * External IDs may be shared by different contacts; never discard a native source ID.
     */
    private function dedupeSnowflakeChunkByTpId(array $chunk): array
    {
        $seen = [];
        $bad = [];
        foreach ($chunk as $row) {
            $id = (string) ($row['LLG_ID'] ?? '');
            if (!preg_match('/^[1-9][0-9]*$/D', $id) || isset($seen[$id])) {
                $bad[$id] = true;
            }
            $seen[$id] = true;
        }
        foreach (array_keys($bad) as $id) {
            $this->recordContactFlag(['id' => 'LLG-' . $id, 'code' => 'invalid_or_duplicate_source_id',
                'message' => 'Invalid or duplicate source ID; every occurrence was skipped.'], true);
        }
        return array_values(array_filter($chunk, fn ($row) => !isset($bad[(string) ($row['LLG_ID'] ?? '')])));
    }

    private function snowflakeRowBetterThan(array $candidate, array $existing): bool
    {
        $cScore = $this->snowflakeRowRichnessScore($candidate);
        $eScore = $this->snowflakeRowRichnessScore($existing);
        if ($cScore !== $eScore) {
            return $cScore > $eScore;
        }
        $cMod = (string) ($candidate['MODIFIED'] ?? '');
        $eMod = (string) ($existing['MODIFIED'] ?? '');
        if ($cMod !== $eMod) {
            return $cMod > $eMod;
        }
        return (string) ($candidate['LLG_ID'] ?? '') > (string) ($existing['LLG_ID'] ?? '');
    }

    private function snowflakeRowRichnessScore(array $row): int
    {
        $score = 0;
        if (trim((string) ($row['CELL_PHONE'] ?? $row['PHONE3'] ?? '')) !== '') {
            $score += 4;
        }
        if (trim((string) ($row['EMAIL'] ?? '')) !== '') {
            $score += 4;
        }
        if (trim((string) ($row['ADDRESS1'] ?? $row['ADDRESS'] ?? '')) !== '') {
            $score += 1;
        }
        if (trim((string) ($row['ASSIGNED_TO'] ?? '')) !== '') {
            $score += 1;
        }
        return $score;
    }

    /** Shared junk TP_IDs from Forth test/mailer spam — not real External IDs. */
    private function isFakeExternalId(string $tpId): bool
    {
        return in_array($tpId, ['0', '1234567840', 'UNKNOWN'], true);
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

    /**
     * Require a non-empty, normalized client name before treating equal keys as a match.
     * The source systems can reuse a numeric ID across companies, so IDs alone are not identity.
     */
    private function contactNameMatchSql(string $leftAlias, string $rightAlias): string
    {
        $left = $this->normalizedContactNameSql($leftAlias);
        $right = $this->normalizedContactNameSql($rightAlias);

        return "({$left} <> '' AND {$left} = {$right})";
    }

    /**
     * Fail closed on cross-company ID collisions: require the same normalized name,
     * reject conflicting populated phone/email values, and require at least one
     * matching email or normalized phone as an independent identity signal.
     */
    private function contactIdentityMatchSql(string $leftAlias, string $rightAlias): string
    {
        return ContactSyncIdentity::sql($leftAlias, $rightAlias);
    }

    private function normalizedContactNameSql(string $alias): string
    {
        return ContactSyncIdentity::nameSql($alias);
    }

    private function normalizedContactPhoneSql(string $alias): string
    {
        return ContactSyncIdentity::phoneSql($alias);
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
        return ContactSyncWatermark::read($this->timestampFilePath(), $source);
    }

    private function writeLastSyncTime(string $source, string $datetime): void
    {
        ContactSyncWatermark::write($this->timestampFilePath(), $source, $datetime);
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
