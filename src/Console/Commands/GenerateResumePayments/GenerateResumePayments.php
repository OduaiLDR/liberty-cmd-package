<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateResumePayments;

use Cmd\Reports\Pmod\Services\DppDataClient;
use Cmd\Reports\Pmod\Services\DppSeleniumService;
use Cmd\Reports\Pmod\Services\ForthPayPmodExecutionGateway;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Generate:resume-payments — Laravel port of the LDR + PLAW "ResumePayments" VBA macro.
 *
 * Workflow (per company):
 *   Phase 1  — Pull NSF candidates from Snowflake (returned drafts since 2022-09-01,
 *              enrolled + not graduated, R-codes R01/R02/R03/R04/R07/R08/R09/R10/R11/R13/R15/R16/R17/R20/R24/R29).
 *   Phase 2  — Per contact: walk draft history to find latest R-code, count NSFs by
 *              distinct calendar month, compute age (days) from earliest NSF.
 *   Phase 3  — Recent-successful-draft adjustment (subtract count of cleared drafts in last 3 days).
 *   Phase 4  — Decide CRM action by (R-code, age) bucket; call Forth via gateway:
 *                 set client_status, click Resume Payments, log to TblResumePayments.
 *              Skip contacts whose status contains Dropped/LUSA-FUNDED/System Cancel/etc.
 *   Phase 5  — System Cancel for contacts with age > 105 days:
 *                 Day 1 (first eligible day) → insert TblEnrollmentCancellations and stop
 *                 (5-business-day grace, shown 1-indexed Day 1..Day 5; cancel on Day 6)
 *                 Day 6+ → assemble Cancel Program from primitives:
 *                     (a) Schedule ACH Credit/Fee for min(Balance, EPF) (paid-to system account)
 *                     (b) Void pending settlements (reason "NSF/SKIP PAYMENT") if Balance < ScheduledSettlements
 *                     (c) Cancel pending drafts + create refund if Balance > 0
 *                     (d) Set status to "System Cancel (NSF-3)" etc.
 *                     (e) Add drop-reason note ("Unable to Resolve NSF")
 *   Phase 6  — Build "Status Changes" Excel + send recap email to candice/anthony/adrian/...
 *
 * @see vendor/.../ForthPayPmodExecutionGateway.php for the Forth CRM + Forth Pay API calls.
 */
final class GenerateResumePayments extends Command
{
    protected $signature = 'Generate:resume-payments
        {--dry-run : Skip all CRM writes; only log what would happen}
        {--company=* : Limit run to LDR and/or PLAW (default: both)}
        {--contact-id=* : Only process these Forth contact IDs (controlled live test)}
        {--limit= : Process at most N contacts per company (after NSF-state filtering)}
        {--execute-cancels : Actually run the Day-4+ System Cancel (browser drop/refund). Off by default — Phase 5 only reports cancel-ready contacts unless this is passed.}
        {--max-cancels= : Safety cap — process at most N cancel candidates per company per run (the rest are reported as deferred). 0/unset = no cap.}
        {--max-voids= : Settlement AUTO-VOID on-switch + safety cap — void the settlements of at most N cancel candidates per company per run. 0/unset = no auto-void (settlement contacts route to manual, todays default). Start at 1; ramp up as it proves out.}
        {--probe-cancel= : Diagnostic only — drive the cancel flow for ONE contact id and print which selectors exist, WITHOUT clicking save (commits nothing). Tenant = first --company (default LDR).}
        {--probe-resume= : Diagnostic only — probe the Forth resume-payments API for ONE contact id. READ-ONLY by default (token health + contact resolve + transactions paths; safe on any contact). Tenant = first --company (default LDR).}
        {--probe-resume-execute : With --probe-resume, also FIRE the resume POST (an action — TEST FILES ONLY). Without it the resume probe is read-only.}
        {--probe-void-settlements= : Diagnostic only — READ-ONLY dump of a single contact settlement-void screen (selectors, void-reason options, settlement rows) plus the pending settlement offers we would target for auto-void. Commits nothing. Tenant = first --company.}
        {--no-recap : Skip the Phase 6 recap email (status writes + resumes still happen). Use for controlled live tests so the team is not emailed a partial run.}
        {--cancels-only : Skip Phase 4 (NSF status updates + resume) and run ONLY the Day-4+ System Cancels. Use with --execute-cancels to work extra cancel batches through the backlog WITHOUT re-writing statuses or re-firing status-change triggers (Jacob 2026-07-20).}';

    protected $description = 'Process NSF contacts for LDR and Progress Law: update statuses, resume drafts, and execute system cancels per the ResumePayments VBA workflow.';

    private const PROCESSED_R_CODES = ['R01', 'R02', 'R03', 'R04', 'R07', 'R08', 'R09', 'R10', 'R11', 'R13', 'R15', 'R16', 'R17', 'R20', 'R24', 'R29'];

    private const SYSTEM_CANCEL_AGE_DAYS = 105;
    // Grace period before a System Cancel executes (business days). Jacob 2026-07-22: extended
    // to 5 and shown 1-indexed — Day 1 the first eligible day through Day 5, cancel on Day 6.
    // Internally $day is 0-indexed (0 = first day), so cancel fires when $day >= 5.
    private const CANCEL_COOLDOWN_DAYS = 5;

    /**
     * Candidates per Phase-2/3 batch. Bounds the in-memory transaction buffer:
     * computeNsfStates loads every draft for the contacts it's handed, so running it
     * over all ~6.7k candidates at once buffered their entire draft history and was
     * the OOM risk behind the server outage (Bryan 2026-07). Chunking caps peak
     * memory at roughly one batch's worth.
     */
    private const CANDIDATE_CHUNK_SIZE = 500;

    /**
     * Recap stage keys (Jacob 2026-07-20): every processed client lands in exactly
     * one stage. The recap email shows a per-stage COUNT + total debt, and the
     * attached workbook has one sheet per stage (LLG ID / Name / Debt / Days since
     * NSF). NSF-1/2/3 are derived from nsf_count via nsfStage(). These keys MUST
     * stay in sync with Formatter::STAGES.
     */
    private const STAGE_RESOLVED = 'Resolved';
    private const STAGE_CANCEL_GRACE = 'Cancels - Grace Period';
    private const STAGE_CANCEL_HOLD = 'Cancels - Release Hold Requested';
    private const STAGE_CANCEL_BACKLOG = 'Cancels - Backlog';
    private const STAGE_CANCEL_COMPLETE = 'Cancels - Complete';

    /** Forth CRM/Pay API gateway — Phase 4 resume (POST /contacts/{id}/resume) + Phase 5 reads. */
    private ForthPayPmodExecutionGateway $gateway;

    /** DPP "post" data-API client for client_status / notebody writes (Phase 4). */
    private DppDataClient $dppClient;

    /** Headless-browser client for the #cancelbtn (Phase 5) flow. (Phase 4 resume is now the API.) */
    private DppSeleniumService $dppSelenium;

    /** Statuses whose presence in CurrentStatus causes the contact to be SKIPPED entirely. */
    private const SKIP_STATUS_SUBSTRINGS = [
        'Dropped / Cancelled',
        '>Dropped / Cancelled<',
        'Enrolled (Reconsideration Pending)',
        'No Re-Draft',
        'Enrolled (Cancellation Pending EPF Hold)',
        'LDR Enrolled (LUSA-FUNDED)',
        'PLAW Enrolled (LUSA-FUNDED)',
        'System Cancel',
        'Dropped',
    ];

    public function handle(ForthPayPmodExecutionGateway $gateway, DppDataClient $dppClient, DppSeleniumService $dppSelenium): int
    {
        $this->gateway = $gateway;
        $this->dppClient = $dppClient;
        $this->dppSelenium = $dppSelenium;

        $probeCancelId = (string) ($this->option('probe-cancel') ?? '');
        $probeResumeId = (string) ($this->option('probe-resume') ?? '');
        $probeVoidId = (string) ($this->option('probe-void-settlements') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        // Concurrency guard (2026-07-23): this command drives ONE headless Chromium on the fixed
        // ChromeDriver port 9515 against ONE DPP login. Two overlapping runs collide ("port 9515
        // is already in use") and would double-process contacts — that crashed ~26 cancels on
        // 2026-07-22 when a manual test overlapped the scheduled run. A dry-run uses no browser;
        // every other invocation (a probe or a live run) takes an exclusive lock and exits cleanly
        // if another run already holds it. flock auto-releases if a run dies, so no stale locks.
        // $lockHandle is intentionally held in scope for the whole run — closing it frees the lock.
        $usesBrowser = $probeCancelId !== '' || $probeResumeId !== '' || $probeVoidId !== '' || ! $dryRun;
        $lockHandle = false;
        if ($usesBrowser) {
            $lockHandle = $this->acquireRunLock();
            if ($lockHandle === null) {
                $this->warn('[INFO] Another resume-payments run holds the browser lock — exiting to avoid a collision.');
                Log::warning('ResumePayments: skipped — another run holds the browser lock');
                return Command::SUCCESS;
            }
        }

        if ($probeCancelId !== '') {
            return $this->runProbeCancel($probeCancelId);
        }
        if ($probeResumeId !== '') {
            return $this->runProbeResume($gateway, $probeResumeId);
        }
        if ($probeVoidId !== '') {
            return $this->runProbeVoidSettlements($probeVoidId);
        }

        $companies = $this->resolveCompanies();

        $this->info('[INFO] ResumePayments: starting.');
        $this->info('[INFO] Dry-run: ' . ($dryRun ? 'YES' : 'NO'));
        $this->info('[INFO] Companies: ' . implode(', ', $companies));

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateResumePayments: connector init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        foreach ($companies as $company) {
            try {
                $this->info("[INFO] === {$company} ===");
                $snowflake = DBConnector::fromEnvironment(strtolower($company));

                $rows = $this->fetchNsfCandidates($snowflake);
                $this->info(sprintf('[INFO] [%s] NSF candidates: %d', $company, count($rows)));

                // Scope candidates BEFORE the heavy per-contact transaction load so
                // --contact-id/--limit actually cut work + memory. Then compute NSF
                // state in bounded chunks so we never buffer every candidate's full
                // draft history at once (Bryan 2026-07: the OOM behind the outage).
                $rows = $this->applyCandidateFilters($rows);
                $states = $this->computeNsfStatesChunked($snowflake, $rows);
                if ($this->hasRunFilters()) {
                    $this->info(sprintf('[INFO] [%s] After --contact-id/--limit filter: %d', $company, count($states)));
                }

                // Enrich each state with the enrolled debt (TblEnrollment.Debt_Amount)
                // for the recap's Debt column (Jacob 2026-07-20).
                $this->applyDebtAmounts($sqlConnector, $states);

                if ($this->option('cancels-only')) {
                    // Jacob 2026-07-20: extra backlog batches — run only the Day-4+ cancels,
                    // skip Phase 4 so we don't re-write statuses or re-fire status-change
                    // triggers. (Phase 1–3 candidate load still runs — cancels need the states.)
                    $statusChanges = [];
                    $this->info(sprintf('[INFO] [%s] --cancels-only: skipping Phase 4 status updates.', $company));
                } else {
                    $statusChanges = $this->processContacts($gateway, $snowflake, $sqlConnector, $company, $states, $dryRun);
                    $this->info(sprintf('[INFO] [%s] Phase 4 status changes: %d', $company, count($statusChanges)));
                }
                $this->processSystemCancels($gateway, $snowflake, $sqlConnector, $company, $states, $statusChanges, $dryRun);

                if ($this->option('no-recap')) {
                    $this->info(sprintf('[INFO] [%s] --no-recap: skipping recap email (%d status changes).', $company, count($statusChanges)));
                } else {
                    $this->sendRecap($sqlConnector, $company, $statusChanges, $dryRun);
                }

                $this->info(sprintf('[INFO] [%s] Peak memory: %.1f MB', $company, memory_get_peak_usage(true) / 1048576));
            } catch (\Throwable $e) {
                $this->error("[{$company}] ResumePayments failed: " . $e->getMessage());
                Log::error('GenerateResumePayments: company failed', [
                    'company' => $company,
                    'exception' => $e,
                ]);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * --probe-cancel: run the no-save cancel diagnostic for one contact and print
     * the report. Commits nothing (DppSeleniumService::probeCancel never clicks
     * #savebtn). Tenant is the first --company (default LDR).
     */
    private function runProbeCancel(string $contactId): int
    {
        $company = $this->resolveCompanies()[0] ?? 'LDR';
        $tenant = strtolower($company);

        $this->info("[INFO] Probe-cancel (NO-SAVE) for contact {$contactId} as {$company} — commits nothing.");

        try {
            $report = $this->dppSelenium->probeCancel($tenant, $contactId);
        } catch (\Throwable $e) {
            $this->error('Probe failed: ' . $e->getMessage());
            Log::error('GenerateResumePayments: probe-cancel failed', ['contact_id' => $contactId, 'exception' => $e]);

            return Command::FAILURE;
        }

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * --probe-resume: call the Forth CRM resume-payments API (path discovered
     * 2026-06-30: POST /servicing/enrollment/{id}/resume-payments) for one contact
     * and print the raw result, plus a read-only dump of the contact's
     * transactions. Confirms whether the endpoint accepts the contact id directly
     * (vs a separate enrollment id) before we wire it into Phase 4. Run ONLY on a
     * test file — a 2xx resume is a real action. Tenant is the first --company.
     */
    private function runProbeResume(ForthPayPmodExecutionGateway $gateway, string $contactId): int
    {
        $company = $this->resolveCompanies()[0] ?? 'LDR';
        $tenant = strtolower($company);
        $execute = (bool) $this->option('probe-resume-execute');

        if ($execute) {
            $this->info("[INFO] Probe-resume for contact {$contactId} as {$company} — EXECUTE mode (will fire a REAL resume). TEST FILES ONLY.");
        } else {
            $this->info("[INFO] Probe-resume for contact {$contactId} as {$company} — READ-ONLY (no writes; safe on any contact).");
        }

        try {
            $report = $gateway->probeResumePayments($tenant, $contactId, $execute);
        } catch (\Throwable $e) {
            $this->error('Probe failed: ' . $e->getMessage());
            Log::error('GenerateResumePayments: probe-resume failed', ['contact_id' => $contactId, 'exception' => $e]);

            return Command::FAILURE;
        }

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * --probe-void-settlements: READ-ONLY diagnostic for the settlement auto-void we are
     * about to build. Prints (a) the pending settlement OFFERS Snowflake thinks this
     * contact has (the ids we'd void, with pending/cleared row counts + a fully-unpaid
     * flag), and (b) a live DOM dump of the DPP settlement-void screen (selectors, the
     * void-reason options, the settlement table rows). Commits NOTHING — no settlement is
     * voided. Confirms the real selectors + id semantics before we write the destructive
     * voidSettlements(). Tenant = first --company (default LDR).
     */
    private function runProbeVoidSettlements(string $contactId): int
    {
        $company = $this->resolveCompanies()[0] ?? 'LDR';
        $tenant = strtolower($company);

        $this->info("[INFO] Probe-void-settlements (READ-ONLY) for contact {$contactId} as {$company} — commits nothing.");

        // Snowflake side: which settlement offers we THINK are pending for this contact.
        $offers = [];
        try {
            $snowflake = DBConnector::fromEnvironment($tenant);
            $offers = $this->fetchPendingSettlementOffers($snowflake, [$contactId]);
        } catch (\Throwable $e) {
            $this->error('Snowflake offer fetch failed: ' . $e->getMessage());
            Log::warning('GenerateResumePayments: probe-void offer fetch failed', ['contact_id' => $contactId, 'error' => $e->getMessage()]);
        }

        // Browser side: read-only dump of the live settlement-void screen.
        try {
            $ui = $this->dppSelenium->probeVoidSettlements($tenant, $contactId);
        } catch (\Throwable $e) {
            $this->error('Probe failed: ' . $e->getMessage());
            Log::error('GenerateResumePayments: probe-void-settlements failed', ['contact_id' => $contactId, 'exception' => $e]);

            return Command::FAILURE;
        }

        $this->line((string) json_encode([
            'snowflake_pending_offers' => $offers[$contactId] ?? [],
            'live_ui' => $ui,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    /**
     * Acquire an exclusive, non-blocking file lock so only ONE browser-driving run executes at a
     * time (prevents the ChromeDriver port-9515 collision + double-processing). Returns the open
     * lock handle (KEEP it referenced for the whole run — closing/GC releases the lock), null if
     * another run already holds it, or false if the lock file can't be opened (proceed anyway — a
     * lock-infra hiccup must not block the daily run). flock is process-scoped, so a crashed run
     * releases its lock automatically (no stale-lock recovery needed).
     *
     * @return resource|null|false
     */
    private function acquireRunLock()
    {
        $path = storage_path('app/resume-payments-run.lock');
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            Log::warning('ResumePayments: could not open run-lock file; proceeding WITHOUT a concurrency lock', ['path' => $path]);

            return false;
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function resolveCompanies(): array
    {
        $opt = array_values(array_filter(array_map(
            static fn(mixed $v): string => strtoupper(trim((string) $v)),
            (array) $this->option('company'),
        )));

        return $opt !== [] ? $opt : ['LDR', 'PLAW'];
    }

    private function hasRunFilters(): bool
    {
        return $this->runContactIds() !== [] || $this->option('limit') !== null;
    }

    /**
     * @return list<string>
     */
    private function runContactIds(): array
    {
        return array_values(array_filter(array_map(
            static fn(mixed $v): string => trim((string) $v),
            (array) $this->option('contact-id'),
        )));
    }

    /**
     * Apply --contact-id / --limit to the CANDIDATE rows up front — before Phase 2/3
     * load each contact's draft history — so a scoped run (one/few contacts, or the
     * first N) does proportionally less DB work and holds proportionally less memory.
     * computeNsfStates is 1:1 candidate→state, so filtering here is equivalent to the
     * old post-compute slice but far cheaper.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function applyCandidateFilters(array $candidates): array
    {
        $only = $this->runContactIds();
        if ($only !== []) {
            $set = array_flip($only);
            $candidates = array_values(array_filter(
                $candidates,
                static fn(array $c): bool => isset($set[(string) ($c['CONTACT_ID'] ?? '')]),
            ));
        }

        $limit = $this->option('limit');
        if ($limit !== null && (int) $limit > 0) {
            $candidates = array_slice($candidates, 0, (int) $limit);
        }

        return $candidates;
    }

    /**
     * Phase 1 — Pull the latest failed draft per contact since 2022-09-01.
     *
     * Mirrors the VBA's "fill columns A-F + post-delete where E is empty OR F > 1"
     * by using Snowflake QUALIFY to keep only the most recent qualifying transaction
     * per contact. RESPONSE='Invalid Routing Number' is normalised to R03 to match
     * the VBA's CASE WHEN.
     *
     * Returns one row per contact: CONTACT_ID, FULLNAME, PROCESS_DATE, RETURNED_DATE, RETURN_CODE.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchNsfCandidates(DBConnector $snowflake): array
    {
        $rCodeList = "'" . implode("','", self::PROCESSED_R_CODES) . "'";

        $sql = "
            SELECT
                t.CONTACT_ID,
                CONCAT(COALESCE(c.FIRSTNAME, ''), ' ', COALESCE(c.LASTNAME, '')) AS FULLNAME,
                TO_VARCHAR(t.PROCESS_DATE::date, 'YYYY-MM-DD') AS PROCESS_DATE,
                TO_VARCHAR(t.RETURNED_DATE::date, 'YYYY-MM-DD') AS RETURNED_DATE,
                CASE WHEN t.RESPONSE = 'Invalid Routing Number' THEN 'R03' ELSE t.RETURN_CODE END AS RETURN_CODE
            FROM TRANSACTIONS t
            JOIN CONTACTS c ON t.CONTACT_ID = c.ID
            WHERE t.TRANS_TYPE = 'D'
              AND (t.RETURN_CODE IN ({$rCodeList}) OR t.RESPONSE = 'Invalid Routing Number')
              AND t.PROCESS_DATE < CURRENT_DATE()
              AND t.PROCESS_DATE >= '2022-09-01'
              AND c.ENROLLED = 1
              AND c.GRADUATED = 0
            QUALIFY ROW_NUMBER() OVER (PARTITION BY t.CONTACT_ID ORDER BY t.PROCESS_DATE DESC) = 1
            ORDER BY t.CONTACT_ID ASC
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    /**
     * Chunked driver for Phase 2 + 3: compute NSF state for the candidates in bounded
     * batches (CANDIDATE_CHUNK_SIZE) so we never hold every candidate's full draft
     * history in memory at once. Each chunk loads only its own transactions; those raw
     * rows are freed between chunks, leaving just the small per-contact state. Behavior
     * is identical to running Phase 2/3 over the whole set — the per-contact logic has
     * no cross-contact dependency.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function computeNsfStatesChunked(DBConnector $snowflake, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $states = [];
        foreach (array_chunk($candidates, self::CANDIDATE_CHUNK_SIZE) as $chunk) {
            $chunkStates = $this->computeNsfStates($snowflake, $chunk);
            $this->applyRecentDraftAdjustment($snowflake, $chunkStates);
            foreach ($chunkStates as $state) {
                $states[] = $state;
            }
            unset($chunkStates);
        }

        return $states;
    }

    /**
     * Phase 2 — Walk each contact's draft history newest-to-oldest to compute:
     *   - is_current: true if latest D txn has CLEARED_DATE and no RETURNED_DATE
     *   - current_rcode: oldest non-empty R-code in the current NSF chain ("" if Current)
     *   - age_days: today - PROCESS_DATE of that oldest NSF in the chain
     *   - nsf_count: one increment per distinct calendar month of RETURNED_DATE
     *   - last_cleared_date: most recent CLEARED_DATE encountered (diagnostic)
     *
     * The chain walk stops at the first successful (cleared, not returned) draft,
     * matching the VBA's Exit Do behavior — anything older than a successful draft
     * does not count toward the current NSF state.
     *
     * Normalizes CLEARED_DATE to null whenever RETURNED_DATE is set (VBA's
     * "if M<>'' then clear N" step) so a returned draft is never mistaken for cleared.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function computeNsfStates(DBConnector $snowflake, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $contactIds = array_map(static fn(array $r): string => (string) ($r['CONTACT_ID'] ?? ''), $candidates);
        $contactIds = array_values(array_filter($contactIds, static fn(string $v): bool => $v !== ''));
        if ($contactIds === []) {
            return [];
        }

        $cidList = implode(',', array_map('intval', $contactIds));

        $sql = "
            SELECT
                CONTACT_ID,
                TO_VARCHAR(PROCESS_DATE::date, 'YYYY-MM-DD') AS PROCESS_DATE,
                TO_VARCHAR(RETURNED_DATE::date, 'YYYY-MM-DD') AS RETURNED_DATE,
                TO_VARCHAR(CLEARED_DATE::date, 'YYYY-MM-DD') AS CLEARED_DATE,
                RESPONSE,
                CASE WHEN RESPONSE = 'Invalid Routing Number' THEN 'R03' ELSE RETURN_CODE END AS RETURN_CODE
            FROM TRANSACTIONS
            WHERE CONTACT_ID IN ({$cidList})
              AND TRANS_TYPE = 'D'
              AND PROCESS_DATE < CURRENT_DATE()
              AND CANCELLED = 0
            ORDER BY CONTACT_ID ASC, PROCESS_DATE DESC, CLEARED_DATE ASC
        ";

        $result = $snowflake->query($sql);
        $rows = $result['data'] ?? [];

        $byContact = [];
        foreach ($rows as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid === '') {
                continue;
            }
            // VBA normalization: if RETURNED_DATE present, clear CLEARED_DATE.
            if (!empty($row['RETURNED_DATE'])) {
                $row['CLEARED_DATE'] = null;
            }
            $byContact[$cid][] = $row;
        }

        $today = strtotime('today');
        $nsfRcodes = array_flip(self::PROCESSED_R_CODES);

        $output = [];
        foreach ($candidates as $candidate) {
            $cid = (string) ($candidate['CONTACT_ID'] ?? '');
            $txns = $byContact[$cid] ?? [];

            $state = $candidate + [
                'is_current' => false,
                'current_rcode' => '',
                'age_days' => 0,
                'nsf_anchor_date' => '',
                'nsf_count' => 0,
                'last_cleared_date' => null,
            ];

            if ($txns === []) {
                $output[] = $state;
                continue;
            }

            $first = $txns[0];
            $firstReturned = (string) ($first['RETURNED_DATE'] ?? '');
            $firstCleared = (string) ($first['CLEARED_DATE'] ?? '');

            // Current detection: latest txn has CLEARED_DATE and no RETURNED_DATE.
            if ($firstReturned === '' && $firstCleared !== '') {
                $state['is_current'] = true;
                $state['current_rcode'] = '';
                $state['last_cleared_date'] = $firstCleared;
                $output[] = $state;
                continue;
            }

            $nsfMonthKey = null;
            foreach ($txns as $txn) {
                $returnedDate = (string) ($txn['RETURNED_DATE'] ?? '');
                $clearedDate = (string) ($txn['CLEARED_DATE'] ?? '');
                $processDate = (string) ($txn['PROCESS_DATE'] ?? '');
                $rcode = (string) ($txn['RETURN_CODE'] ?? '');

                if ($clearedDate !== '') {
                    $state['last_cleared_date'] = $clearedDate;
                }

                if ($rcode !== '') {
                    // Overwrite each iteration so we end on the OLDEST R-code in the chain.
                    $state['current_rcode'] = $rcode;
                    $state['age_days'] = $processDate !== ''
                        ? (int) floor(($today - strtotime($processDate)) / 86400)
                        : 0;
                    // Streak anchor = the oldest unresolved NSF's process date (the start of the
                    // current NSF episode). Used by resolveCancelDay to reset the cooldown when a
                    // client resolved then re-cancelled. Unlike age_days, it is NOT reduced by the
                    // resume adjustment below, so it stays the true episode start.
                    $state['nsf_anchor_date'] = $processDate !== '' ? date('Y-m-d', strtotime($processDate)) : '';
                }

                if ($rcode !== '' && isset($nsfRcodes[$rcode])) {
                    if ($returnedDate !== '') {
                        $month = substr($returnedDate, 0, 7);
                        if ($nsfMonthKey === null) {
                            $nsfMonthKey = $month;
                            $state['nsf_count']++;
                        } elseif ($month !== $nsfMonthKey) {
                            $nsfMonthKey = $month;
                            $state['nsf_count']++;
                        }
                    }
                } elseif ($returnedDate === '' && $clearedDate !== '') {
                    // VBA: hit a successful (cleared, not returned) draft → stop.
                    break;
                }
            }

            $output[] = $state;
        }

        return $output;
    }

    /**
     * Phase 3 — Successful-draft adjustment.
     *
     * For each contact with a latest-NSF PROCESS_DATE (col C from Phase 1):
     *   - count successful (ACTIVE=1, CANCELLED=0) D-type txns CREATED after that
     *     PROCESS_DATE with their own PROCESS_DATE within the last 3 days
     *   - subtract that count from nsf_count
     *   - subtract count*30 from age_days (floor at 0)
     *   - if nsf_count drops below 1 → mark Current (clear R-code, clear age, clear count)
     *
     * Implemented as a single batch fetch of recent successful drafts for all
     * candidate contacts, then a per-contact in-PHP filter against each contact's
     * own PROCESS_DATE cutoff.
     *
     * @param list<array<string, mixed>> $states
     */
    private function applyRecentDraftAdjustment(DBConnector $snowflake, array &$states): void
    {
        if ($states === []) {
            return;
        }

        $contactIds = [];
        foreach ($states as $state) {
            $cid = (string) ($state['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $contactIds[] = $cid;
            }
        }
        if ($contactIds === []) {
            return;
        }

        $cidList = implode(',', array_map('intval', $contactIds));

        $sql = "
            SELECT
                CONTACT_ID,
                TO_VARCHAR(CREATED_AT::date, 'YYYY-MM-DD') AS CREATED_AT
            FROM TRANSACTIONS
            WHERE CONTACT_ID IN ({$cidList})
              AND TRANS_TYPE = 'D'
              AND CANCELLED = 0
              AND ACTIVE = 1
              AND PROCESS_DATE >= DATEADD(day, -3, CURRENT_DATE())
        ";

        $result = $snowflake->query($sql);
        $rows = $result['data'] ?? [];

        $createdByContact = [];
        foreach ($rows as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            $created = (string) ($row['CREATED_AT'] ?? '');
            if ($cid === '' || $created === '') {
                continue;
            }
            $createdByContact[$cid][] = $created;
        }

        foreach ($states as &$state) {
            $cid = (string) ($state['CONTACT_ID'] ?? '');
            $cutoff = (string) ($state['PROCESS_DATE'] ?? '');
            if ($cid === '' || $cutoff === '') {
                continue;
            }

            $candidates = $createdByContact[$cid] ?? [];
            $count = 0;
            foreach ($candidates as $created) {
                if ($created >= $cutoff) {
                    $count++;
                }
            }

            if ($count === 0) {
                continue;
            }

            $state['nsf_count'] = max(0, ((int) ($state['nsf_count'] ?? 0)) - $count);
            $state['age_days'] = max(0, ((int) ($state['age_days'] ?? 0)) - ($count * 30));

            if ($state['nsf_count'] < 1) {
                $state['is_current'] = true;
                $state['current_rcode'] = '';
                $state['age_days'] = 0;
                $state['nsf_count'] = 0;
            }
        }
        unset($state);
    }

    /**
     * Attach the enrolled debt (dbo.TblEnrollment.Debt_Amount, keyed by LLG_ID) to
     * each state as `debt_amount` for the recap's Debt column (Jacob 2026-07-20).
     * TblEnrollment is the same SQL Server source the other reports read debt from,
     * and our rows are already LLG-{contactId} — so this is one keyed batch lookup,
     * chunked to stay under SQL Server's 2100-parameter cap. A missing row (or any
     * query error) leaves debt_amount at 0.0; debt is display-only and must never
     * fail the run.
     *
     * @param list<array<string, mixed>> $states
     */
    private function applyDebtAmounts(DBConnector $sqlConnector, array &$states): void
    {
        if ($states === []) {
            return;
        }

        $llgIds = [];
        foreach ($states as $state) {
            $cid = (string) ($state['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $llgIds[] = 'LLG-' . $cid;
            }
        }
        if ($llgIds === []) {
            return;
        }

        $debtByLlg = [];
        foreach (array_chunk(array_values(array_unique($llgIds)), 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT LLG_ID, Debt_Amount FROM TblEnrollment WHERE LLG_ID IN ({$placeholders})";
            try {
                $result = $sqlConnector->querySqlServer($sql, $chunk);
                foreach ($result['data'] ?? [] as $row) {
                    $llg = (string) ($row['LLG_ID'] ?? '');
                    if ($llg !== '') {
                        $debtByLlg[$llg] = (float) ($row['Debt_Amount'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('ResumePayments: debt lookup chunk failed; those clients show 0 debt', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($states as &$state) {
            $cid = (string) ($state['CONTACT_ID'] ?? '');
            $state['debt_amount'] = $cid !== '' ? ($debtByLlg['LLG-' . $cid] ?? 0.0) : 0.0;
        }
        unset($state);
    }

    /**
     * Phase 4 — For each contact, decide and execute the CRM action via the gateway.
     * Returns the list of recap rows (llg_id, name, stage, days, debt) for the email.
     *
     * @param list<array<string, mixed>> $states
     * @return list<array{llg_id:string,name:string,stage:string,days:int,debt:float}>
     */
    private function processContacts(
        ForthPayPmodExecutionGateway $gateway,
        DBConnector $snowflake,
        DBConnector $sqlConnector,
        string $company,
        array $states,
        bool $dryRun
    ): array {
        $statusChanges = [];
        if ($states === []) {
            return $statusChanges;
        }

        $tenant = strtolower($company); // 'ldr' | 'plaw' — selects DPP key + Forth token

        $contactIds = [];
        foreach ($states as $state) {
            $cid = (string) ($state['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $contactIds[] = $cid;
            }
        }

        $currentStatuses = $this->fetchCurrentStatuses($snowflake, $contactIds);
        $enrollmentPlans = $this->fetchEnrollmentPlans($snowflake, $contactIds);

        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');

        foreach ($states as $state) {
            $cid = (string) ($state['CONTACT_ID'] ?? '');
            if ($cid === '') {
                continue;
            }

            $name = trim((string) ($state['FULLNAME'] ?? ''));
            $rcode = (string) ($state['current_rcode'] ?? '');
            $age = (int) ($state['age_days'] ?? 0);
            $nsfCount = (int) ($state['nsf_count'] ?? 0);
            $returnDate = (string) ($state['RETURNED_DATE'] ?? '');
            $debt = (float) ($state['debt_amount'] ?? 0.0);

            $currentStatus = (string) ($currentStatuses[$cid] ?? '');

            // Skip contacts already in a terminal/excluded status (VBA's big InStr guard).
            if ($this->shouldSkipStatus($currentStatus)) {
                continue;
            }

            $plan = $this->detectPlan((string) ($enrollmentPlans[$cid] ?? ''));
            if ($plan === null) {
                // VBA hits `Stop` here; we log and skip the contact instead of halting.
                Log::warning('ResumePayments: unmatched/empty enrollment plan, skipping contact', [
                    'company' => $company,
                    'contact_id' => $cid,
                    'plan_title' => $enrollmentPlans[$cid] ?? null,
                ]);
                continue;
            }

            // --- NSF cleared / Current (VBA Case H = "") ---
            if ($rcode === '') {
                $target = "{$plan} Enrolled";
                $alreadyEnrolled = strcasecmp(trim($currentStatus), $target) === 0;
                if ($alreadyEnrolled || stripos($currentStatus, 'Funded') !== false) {
                    continue;
                }

                $this->dppClient->setClientStatus($tenant, $cid, $target, $dryRun);
                $statusChanges[] = $this->row($cid, $name, self::STAGE_RESOLVED, $age, $debt);

                if ($this->tryResume($tenant, $cid, $dryRun)) {
                    $this->dppClient->addNote($tenant, $cid, 'Payments Resumed. New draft scheduled.', $dryRun);
                }

                $this->insertResumePayments($sqlConnector, $cid, $today, $returnDate, 0, $monthStart, $dryRun);
                continue;
            }

            $group = $this->rcodeGroup($rcode);
            if ($group === null) {
                continue; // R-code outside the processed set (shouldn't reach here)
            }
            [$reasonLabel, $isNsf] = $group;

            if ($isNsf) {
                // --- NSF ladder (R01/R09): resume + status + TblResumePayments ---
                $status = $this->nsfEnrolledStatus($plan, $age);
                if (stripos($currentStatus, $status) !== false) {
                    continue; // already at this status
                }

                if ($this->tryResume($tenant, $cid, $dryRun)) {
                    $this->dppClient->addNote($tenant, $cid, 'Payments Resumed. New draft scheduled.', $dryRun);
                }
                // Resumed or not, the recap stage is the same NSF bucket (Jacob 2026-07-20
                // folds the resume outcome into NSF-1/2/3); the status write below records it.
                $statusChanges[] = $this->row($cid, $name, $this->nsfStage($nsfCount), $age, $debt);

                $this->insertResumePayments($sqlConnector, $cid, $today, $returnDate, $nsfCount, $monthStart, $dryRun);
                $this->dppClient->setClientStatus($tenant, $cid, $status, $dryRun);
                continue;
            }

            // --- Reason ladders (Account Closed / Invalid Bank / Payment Stopped /
            //     Unauthorized): status update only, no resume, no insert ---
            $status = $this->reasonLadderStatus($reasonLabel, $age);
            if (stripos($currentStatus, $status) !== false) {
                continue;
            }

            $this->dppClient->setClientStatus($tenant, $cid, $status, $dryRun);
            $statusChanges[] = $this->row($cid, $name, $this->nsfStage($nsfCount), $age, $debt);
        }

        return $statusChanges;
    }

    /**
     * Build one recap row in the shape the {@see Formatter} consumes: LLG id, client
     * name, the recap STAGE (a STAGE_* constant or nsfStage()), days-since-NSF (age),
     * and enrolled debt. One client = one stage.
     *
     * @return array{llg_id:string,name:string,stage:string,days:int,debt:float}
     */
    private function row(string $contactId, string $name, string $stage, int $days, float $debt): array
    {
        return [
            'llg_id' => "LLG-{$contactId}",
            'name' => $name,
            'stage' => $stage,
            'days' => $days,
            'debt' => $debt,
        ];
    }

    /** nsf_count → NSF-1/2/3 recap stage (Jacob folds 15/30/45/60/90 into 1/2/3). */
    private function nsfStage(int $nsfCount): string
    {
        return 'NSF-' . max(1, min(3, $nsfCount));
    }

    /**
     * Latest lead-status title per contact (VBA: CONTACTS_STATUS join
     * CONTACTS_LEAD_STATUS, newest STAMP). Batched for all candidates.
     *
     * @param list<string> $contactIds
     * @return array<string, string> contactId => status title
     */
    private function fetchCurrentStatuses(DBConnector $snowflake, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $cidList = implode(',', array_map('intval', $contactIds));

        $sql = "
            SELECT CONTACT_ID, TITLE
            FROM (
                SELECT
                    s.CONTACT_ID AS CONTACT_ID,
                    cls.TITLE AS TITLE,
                    ROW_NUMBER() OVER (PARTITION BY s.CONTACT_ID ORDER BY s.STAMP DESC) AS RN
                FROM CONTACTS_STATUS s
                LEFT JOIN CONTACTS_LEAD_STATUS cls ON s.STATUS_ID = cls.ID
                WHERE s.CONTACT_ID IN ({$cidList})
            )
            WHERE RN = 1
        ";

        $result = $snowflake->query($sql);

        $map = [];
        foreach ($result['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $map[$cid] = (string) ($row['TITLE'] ?? '');
            }
        }

        return $map;
    }

    /**
     * Enrollment plan title per contact (VBA: ENROLLMENT_PLAN join
     * ENROLLMENT_DEFAULTS2). First row wins, matching GetDatabaseValue.
     *
     * @param list<string> $contactIds
     * @return array<string, string> contactId => plan title
     */
    private function fetchEnrollmentPlans(DBConnector $snowflake, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }

        $cidList = implode(',', array_map('intval', $contactIds));

        $sql = "
            SELECT p.CONTACT_ID AS CONTACT_ID, e.TITLE AS TITLE
            FROM ENROLLMENT_PLAN p
            LEFT JOIN ENROLLMENT_DEFAULTS2 e ON p.PLAN_ID = e.ID
            WHERE p.CONTACT_ID IN ({$cidList})
        ";

        $result = $snowflake->query($sql);

        $map = [];
        foreach ($result['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid !== '' && !isset($map[$cid])) {
                $map[$cid] = (string) ($row['TITLE'] ?? '');
            }
        }

        return $map;
    }

    /**
     * Plan-detection ladder → status-title prefix. LDR / "LT L" prefix -> LDR;
     * "PLAW" prefix OR contains "Progress" -> ProLaw; anything else -> null (skip).
     *
     * NOTE: the prefix returned must match a REAL status family in that company's
     * Forth account. The PLAW account defines its enrolled/NSF statuses ONLY as
     * "ProLaw …" — there is no "PLAW Enrolled" status in either account (confirmed
     * via forth:dump-stages-statuses 2026-06-30). So a "PLAW…" plan title maps to
     * "ProLaw", same as a "Progress" title; returning "PLAW" would write an orphan.
     */
    private function detectPlan(string $enrollmentPlan): ?string
    {
        $plan = trim($enrollmentPlan);
        if ($plan === '' || strcasecmp($plan, 'Untitled') === 0) {
            return null;
        }

        $upper = strtoupper($plan);

        if (substr($upper, 0, 3) === 'LDR') {
            return 'LDR';
        }
        if (substr($upper, 0, 4) === 'LT L') {
            return 'LDR';
        }
        if (stripos($plan, 'Progress') !== false) {
            return 'ProLaw';
        }
        if (substr($upper, 0, 4) === 'PLAW') {
            return 'ProLaw';
        }

        return null;
    }

    private function shouldSkipStatus(string $currentStatus): bool
    {
        if ($currentStatus === '') {
            return false; // VBA proceeds when no status is present
        }

        foreach (self::SKIP_STATUS_SUBSTRINGS as $needle) {
            if (stripos($currentStatus, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * NSF "Enrolled" ladder for R01/R09 (VBA age buckets 18/34/45/63/93).
     */
    private function nsfEnrolledStatus(string $plan, int $age): string
    {
        return match (true) {
            $age <= 18 => "{$plan} Enrolled (NSF-1)",
            $age <= 34 => "{$plan} Enrolled (NSF-1) 15 day",
            $age <= 45 => "{$plan} Enrolled (NSF-2) 30 day",
            $age <= 63 => "{$plan} Enrolled (NSF-2) 45 day",
            $age <= 93 => "{$plan} Enrolled (NSF-3) 60 day",
            default => "{$plan} Enrolled (NSF-3) 90 day",
        };
    }

    /**
     * Reason-specific ladder for Account Closed / Invalid Bank / Payment Stopped /
     * Unauthorized (VBA age buckets 29/44/59/89). Titles are NOT plan-prefixed.
     */
    private function reasonLadderStatus(string $reasonLabel, int $age): string
    {
        $suffix = match (true) {
            $age <= 29 => '1',
            $age <= 44 => '30',
            $age <= 59 => '45',
            $age <= 89 => '60',
            default => '90',
        };

        return "NSF {$reasonLabel} - {$suffix}";
    }

    /**
     * Map an R-code to its Phase-4 reason group. Note R08 is Payment Stopped here
     * (in Phase 5 it regroups under NSF — preserved separately in processSystemCancels).
     *
     * @return array{0:string,1:bool}|null [reasonLabel, isNsf]
     */
    private function rcodeGroup(string $rcode): ?array
    {
        return match ($rcode) {
            'R01', 'R09' => ['nsf', true],
            'R02', 'R15' => ['Account Closed', false],
            'R03', 'R04', 'R13', 'R16', 'R20' => ['Invalid Bank', false],
            'R07', 'R08' => ['Payment Stopped', false],
            'R10', 'R11', 'R17', 'R24', 'R29' => ['Unauthorized', false],
            default => null,
        };
    }

    /**
     * Resume a contact via the Forth CRM API (POST /contacts/{id}/resume), replacing
     * the headless-browser #resumebtn click. Returns true only when the contact was
     * actually paused and is now resumed (HTTP 200, response.paused === false) — the
     * caller then writes the "Payments Resumed" note. Every other outcome returns
     * false → the VBA's "(Unable to Resume)" branch, status write still proceeds:
     *   - 409 "Client is not paused": already active, nothing to resume (faithful to
     *     the browser, whose Resume button is only actionable when paused). Logged
     *     distinctly so we can see how often it occurs and revisit reporting with
     *     Jacob if needed.
     *   - any other non-2xx / transport error: a real failure.
     */
    private function tryResume(string $tenant, string $contactId, bool $dryRun): bool
    {
        try {
            $result = $this->gateway->resumeContact($tenant, $contactId, $dryRun);
            $outcome = $result['result'] ?? '';

            if ($outcome === 'resumed' || $outcome === 'dry_run') {
                return true;
            }

            // 'not_paused' (409) — already active; nothing to resume.
            Log::warning('ResumePayments: not resumed (Unable to Resume)', [
                'tenant' => $tenant,
                'contact_id' => $contactId,
                'result' => $outcome,
                'message' => $result['message'] ?? '',
            ]);

            return false;
        } catch (\Throwable $e) {
            // Real non-2xx (e.g. 400) or transport failure → treat as "Unable to
            // Resume" so the status write still proceeds.
            Log::warning('ResumePayments: resume failed (Unable to Resume)', [
                'tenant' => $tenant,
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function insertResumePayments(
        DBConnector $sqlConnector,
        string $contactId,
        string $today,
        string $returnDate,
        int $nsfs,
        string $monthStart,
        bool $dryRun
    ): void {
        if ($dryRun) {
            Log::info('ResumePayments: DRY RUN - would INSERT TblResumePayments', [
                'llg_id' => "LLG-{$contactId}",
                'return_date' => $returnDate,
                'nsfs' => $nsfs,
            ]);

            return;
        }

        $insert = 'INSERT INTO TblResumePayments (LLG_ID, Process_Date, Return_Date, NSFs, Process_Month) VALUES (?, ?, ?, ?, ?)';

        $sqlConnector->querySqlServer($insert, [
            "LLG-{$contactId}",
            $today,
            $returnDate !== '' ? $returnDate : null,
            $nsfs,
            $monthStart,
        ]);
    }

    /**
     * Phase 5 — Cool-down gate + warning emission for age > 105 day contacts.
     *
     * Day counting (per Jacob 2026-06-18, grace extended + renumbered 2026-07-22):
     *   Check the cancels table; no match → insert today (this is the first day). A match →
     *   count business days since that date. Shown on the report 1-indexed: Day 1 the first
     *   eligible day through Day 5, cancel on Day 6. (Internally $day is 0-indexed: 0 = first
     *   day; cancel fires at $day >= CANCEL_COOLDOWN_DAYS (5), i.e. displayed Day 6.)
     *
     * Uses only the existing columns (LLG_ID, Cancellation_Date) — no schema change.
     * Re-cancel is prevented by cancelProgram()'s #cancelbtn check, not a DB flag; a
     * resolve-then-recancel restarts the count (see resolveCancelDay's stale-anchor reset).
     *
     * Behavior:
     *   - Skip contacts where is_current=true or age_days <= 105.
     *   - Look up the latest TblEnrollmentCancellations row for LLG-{contactId}.
     *       - No row (or a stale pre-episode row) → INSERT (Cancellation_Date = today) = Day 1.
     *       - Row exists, internal day 0..4 (Day 1..5) → Grace Period sheet.
     *       - Row exists, internal day >= 5 (Day 6) → with --execute-cancels, run the browser
     *         cancel (see executeSystemCancel); otherwise it lands on the Backlog/Queued sheet.
     *
     * @param list<array<string, mixed>> $states
     * @param list<array{llg_id:string,name:string,stage:string,days:int,debt:float}> $statusChanges
     */
    private function processSystemCancels(
        ForthPayPmodExecutionGateway $gateway, // reserved for Day 4+ EPF capture + settlement void
        DBConnector $snowflake,                // reserved for Day 4+ balance/EPF/settlement queries
        DBConnector $sqlConnector,
        string $company,                       // reserved for Day 4+ DppSeleniumService tenant routing
        array $states,
        array &$statusChanges,
        bool $dryRun
    ): void {
        // Cancel-cooldown runs on business (Pacific) days per Jacob 2026-07-02: the
        // command runs 7 days/week but cancels only EXECUTE Mon–Fri, and the day count
        // skips weekends. Use the business timezone — the automation fires 7pm PT, which
        // in server-UTC is the next calendar day, so date() alone would break both.
        $businessNow = new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'));
        $today = $businessNow->format('Y-m-d');
        $isWeekday = (int) $businessNow->format('N') <= 5; // 1=Mon .. 5=Fri
        $executeCancels = (bool) $this->option('execute-cancels');
        $maxCancels = (int) ($this->option('max-cancels') ?? 0);
        $processDate = $this->nextBusinessDay();
        $tenant = strtolower($company);
        $systemAccount = $this->systemAccountFor($company);

        // Pass 1 — day-count every age>105 contact (insert first-day rows, Grace sheet shows
        // Day 1..5) and collect the ones that will actually be cancelled. Contacts past the
        // grace (Day 6+) that are NOT cancelled today (weekend / reporting-only run) are
        // COUNTED, not listed — Jacob 2026-07-10 wants the grace days then only the clients
        // dropped that day, no running "Day N" list.
        $toCancel = [];
        foreach ($states as $state) {
            $isCurrent = (bool) ($state['is_current'] ?? false);
            $ageDays = (int) ($state['age_days'] ?? 0);

            if ($isCurrent || $ageDays <= self::SYSTEM_CANCEL_AGE_DAYS) {
                continue;
            }

            $contactId = (string) ($state['CONTACT_ID'] ?? '');
            if ($contactId === '') {
                continue;
            }

            $llgId = 'LLG-' . $contactId;
            $name = trim((string) ($state['FULLNAME'] ?? ''));
            $debt = (float) ($state['debt_amount'] ?? 0.0);

            $anchorDate = (string) ($state['nsf_anchor_date'] ?? '');
            $day = $this->resolveCancelDay($sqlConnector, $llgId, $today, $anchorDate, $dryRun)['day'];

            if ($day < self::CANCEL_COOLDOWN_DAYS) {
                // Grace period — managers can still intervene (Grace Period sheet). Jacob
                // 2026-07-22: show the cooldown day 1-indexed (Day 1 the first eligible day
                // .. Day 5), NOT the NSF age. $day is 0-indexed internally, so display $day+1.
                $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_GRACE, $day + 1, $debt);
                continue;
            }

            // Grace over (internal day >= 5, i.e. Day 6): ready to cancel.
            $rcode = (string) ($state['current_rcode'] ?? '');
            $cancelInfo = $this->systemCancelInfo($rcode);

            if ($cancelInfo === null) {
                // Past grace but the return code doesn't map to a cancel type — it can't be
                // auto-cancelled, so a person must handle it (Release Hold / manual sheet).
                $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_HOLD, $ageDays, $debt);
                continue;
            }

            // Execute the drop only when the flag is on and it's a weekday (cancels run
            // Mon–Fri only per Jacob). On a weekend / reporting-only run the contact is
            // ready but not dropped today — it goes to the Backlog sheet.
            if (!$executeCancels || !$isWeekday) {
                $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_BACKLOG, $ageDays, $debt);
                continue;
            }

            $toCancel[] = ['contact_id' => $contactId, 'llg_id' => $llgId, 'name' => $name, 'day' => $day, 'info' => $cancelInfo, 'days' => $ageDays, 'debt' => $debt];
        }

        // Pass 2 — execute, honoring the --max-cancels safety cap. Deferred contacts
        // (past the cap) are counted, not listed (Jacob 2026-07-10).
        if ($toCancel !== []) {
            // Batch-fetch the Snowflake data for all to-cancel contacts in one shot
            // each (instead of ~5 round-trips per contact).
            $contactIds = array_column($toCancel, 'contact_id');
            $balances = $this->fetchBalances($snowflake, $contactIds);
            $epfs = $this->fetchPendingSums($snowflake, $contactIds, 'PF');
            $settlements = $this->fetchPendingSums($snowflake, $contactIds, 'S');
            $englishFlags = $this->fetchEnglishFlags($snowflake, $contactIds);
            $settlementOffers = $this->fetchPendingSettlementOffers($snowflake, $contactIds);

            // Settlement AUTO-VOID gating: fires when --max-voids>0 (the single on-switch —
            // 0/unset = off, today's safe default). --max-voids caps how many CONTACTS have
            // their settlements voided this run (start at 1); beyond it, settlement contacts
            // fall through to cancelProgram's manual gate exactly as today. A slot is only spent
            // on a contact that will actually void — offers present and NOT positive-balance+EPF
            // (that hits the EPF gate, which still blocks the drop).
            $maxVoids = (int) ($this->option('max-voids') ?? 0);
            $voidsUsed = 0;

            $processed = 0;
            foreach ($toCancel as $item) {
                if ($maxCancels > 0 && $processed >= $maxCancels) {
                    // Past today's safety cap — goes to the Backlog sheet, cancelled a
                    // later run (Jacob 2026-07-20: detail on the sheet, count in the email).
                    $statusChanges[] = $this->row($item['contact_id'], $item['name'], self::STAGE_CANCEL_BACKLOG, $item['days'], $item['debt']);
                    continue;
                }
                $processed++;

                $cid = $item['contact_id'];
                $balance = $balances[$cid] ?? 0.0;
                $epf = $epfs[$cid] ?? 0.0;
                $settlementSum = $settlements[$cid] ?? 0.0;

                // Resolve the offers to void for THIS contact (empty = route to manual as
                // before). Consumes a --max-voids slot in dry-run too, so the preview
                // reflects exactly which contacts the live run would auto-void.
                $offersForContact = [];
                if (
                    $maxVoids > 0
                    && $settlementSum > 0
                    && !($balance > 0 && $epf > 0)
                    && ($settlementOffers[$cid] ?? []) !== []
                    && $voidsUsed < $maxVoids
                ) {
                    $offersForContact = $settlementOffers[$cid];
                    $voidsUsed++;
                }

                try {
                    $this->executeSystemCancel(
                        $tenant,
                        $cid,
                        $item['llg_id'],
                        $item['name'],
                        $item['info'],
                        $balance,
                        $epf,
                        $settlementSum,
                        $englishFlags[$cid] ?? true,
                        $systemAccount,
                        $processDate,
                        $today,
                        $item['days'],
                        $item['debt'],
                        $offersForContact,
                        $dryRun,
                        $statusChanges,
                    );
                } catch (\Cmd\Reports\Pmod\Services\DppSeleniumException $e) {
                    // A DPP SESSION failure (dead login, ChromeDriver port collision, Chromium
                    // crash) breaks EVERY remaining cancel the same way. STOP attempting the rest
                    // this run instead of silently piling ~1000 contacts into Backlog (which
                    // masquerades as a huge queue — exactly the 2026-07-22 confusion). The loud
                    // error is the signal that the run was broken, not that there's a big backlog.
                    if ($e->stage === 'session_failure') {
                        Log::error('ResumePayments: DPP session dead — stopping Phase 5 cancels for this company', [
                            'company' => $company,
                            'processed' => $processed,
                            'error' => $e->getMessage(),
                        ]);
                        break;
                    }

                    throw $e;
                }
            }
        }

        // No summary "N more ready" line and no separate Rama digest anymore (Jacob
        // 2026-07-20): every ready-but-not-dropped client is an individual row on the
        // Backlog sheet, and held/manual clients are on the Release Hold Requested
        // sheet. The email shows per-stage counts; the workbook carries the detail.
    }

    /**
     * Day-4+ System Cancel for one contact (balances/language pre-fetched in batch
     * by the caller): add the cancel note, drive the browser cancel
     * (DppSeleniumService), then on the result set the final status and email
     * setforth for a negative balance. Mirrors the VBA's cancel block. Re-cancel is
     * prevented by cancelProgram()'s #cancelbtn check, so no DB tracking is needed.
     *
     * @param array{0:string,1:string,2:string} $cancelInfo [statusTitle, reasonEn, reasonEs]
     * @param list<array{offer_id:string,pending_rows:int,cleared_rows:int,fully_unpaid:bool}> $settlementOffers offers to auto-void (empty = route settlements to manual)
     * @param list<array{llg_id:string,name:string,stage:string,days:int,debt:float}> $statusChanges
     */
    private function executeSystemCancel(
        string $tenant,
        string $contactId,
        string $llgId,
        string $name,
        array $cancelInfo,
        float $balance,
        float $epf,
        float $settlements,
        bool $english,
        string $systemAccount,
        string $processDate,
        string $today,
        int $days,
        float $debt,
        array $settlementOffers,
        bool $dryRun,
        array &$statusChanges
    ): void {
        [$statusTitle, $reasonEn, $reasonEs] = $cancelInfo;
        $reason = $english ? $reasonEn : $reasonEs;

        if ($dryRun) {
            // Preview the LIVE outcome (mirrors BOTH of cancelProgram's manual gates):
            //  - settlements route to manual UNLESS the auto-void will handle them — the caller
            //    already resolved that into $settlementOffers (switch on + within --max-voids +
            //    void-eligible), so a non-empty list here means this contact WOULD auto-void.
            //  - a positive-balance + EPF client (with no settlement, so not caught above) hits
            //    the EPF_UNVERIFIED gate → manual. Mirror it so the preview matches the live run.
            $gate = ($settlements > 0 && $settlementOffers === [])
                ? 'settlement'
                : (($balance > 0 && $epf > 0) ? 'epf' : null);

            Log::info('ResumePayments: DRY RUN - system cancel preview', [
                'contact_id' => $contactId,
                'outcome' => $gate === null ? 'auto_cancel' : 'manual_review',
                'gate' => $gate,
                'would_void_offers' => array_column($settlementOffers, 'offer_id'),
                'status' => $statusTitle,
                'balance' => $balance,
                'epf' => $epf,
                'scheduled_settlements' => $settlements,
            ]);
            // Preview lands in the stage it WOULD end in: a clean drop → Complete,
            // a settlement gate → Release Hold Requested (manual).
            $statusChanges[] = $this->row($contactId, $name, $gate === null ? self::STAGE_CANCEL_COMPLETE : self::STAGE_CANCEL_HOLD, $days, $debt);
            return;
        }

        // Cancel note (DPP post) before driving the browser, matching the VBA.
        $this->dppClient->addNote($tenant, $contactId, "System Cancel ({$reason}-3)", false);

        try {
            $result = $this->dppSelenium->cancelProgram($tenant, $contactId, [
                'balance' => $balance,
                'epf' => $epf,
                'scheduled_settlements' => $settlements,
                'settlement_offers' => $settlementOffers,
                'process_date' => $processDate,
                'today' => $today,
                'system_account' => $systemAccount,
                'drop_reason' => 'Unable to Resolve NSF',
                'note' => 'Attempted to resume payments 4 times.',
            ]);
        } catch (\Throwable $e) {
            // A PARTIAL settlement void (≥1 offer already voided, then a later one failed) is
            // IRREVERSIBLE and leaves the client un-dropped + un-refunded — it must NOT be
            // buried in the Backlog like a transient error. Alert loudly and route to the
            // manual (Release Hold) sheet so a person reconciles and finishes the cancel.
            if ($e instanceof \Cmd\Reports\Pmod\Services\DppSeleniumException && $e->stage === 'settlement_void_partial') {
                Log::error('ResumePayments: PARTIAL settlement void — manual reconcile required', [
                    'contact_id' => $contactId,
                    'error' => $e->getMessage(),
                ]);
                $this->emailCancellationAudit($contactId, 'URGENT — PARTIAL settlement void; client NOT dropped/refunded, reconcile manually: ' . $e->getMessage());
                $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_HOLD, $days, $debt);
                return;
            }

            // DPP SESSION-level failure (dead/expired login, ChromeDriver port collision,
            // Chromium not reachable): EVERY remaining cancel will fail identically, so record
            // this one to Backlog and RETHROW a typed session_failure so the Pass-2 loop stops
            // and alerts loudly, instead of silently backlogging the whole batch. (This is what
            // masqueraded as "0 cancelled / ~1000 queued" on 2026-07-22.)
            if (preg_match('/login failed|port \d+ is already in use|session (?:deleted|not created|timed out|id is null)|invalid session id|chrome not reachable|disconnected|unable to connect/i', $e->getMessage())) {
                Log::error('ResumePayments: DPP SESSION FAILURE during cancel — aborting further attempts', [
                    'contact_id' => $contactId,
                    'error' => $e->getMessage(),
                ]);
                $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_BACKLOG, $days, $debt);

                throw new \Cmd\Reports\Pmod\Services\DppSeleniumException(
                    'DPP session failure during cancels: ' . $e->getMessage(),
                    tenant: $tenant,
                    contactId: $contactId,
                    stage: 'session_failure',
                    previous: $e,
                );
            }

            // One stuck contact must not abort the rest of the company's Phase 5 loop.
            Log::warning('ResumePayments: cancelProgram threw', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            // Transient/unknown failure — retries next run; lands on the Backlog sheet.
            $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_BACKLOG, $days, $debt);
            return;
        }

        $status = (string) ($result['status'] ?? 'failed');

        if ($status === 'manual_audit') {
            $reason = (string) ($result['message'] ?? 'manual review required');
            Log::warning('ResumePayments: cancel routed to MANUAL review', [
                'contact_id' => $contactId,
                'reason' => $reason,
            ]);
            $this->emailCancellationAudit($contactId, $reason);
            $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_HOLD, $days, $debt);
            return;
        }

        if ($status === 'success') {
            $this->dppClient->setClientStatus($tenant, $contactId, $statusTitle, false);

            if (($result['balance_branch'] ?? '') === 'negative') {
                $this->emailReverseFees($contactId, $name);
            }

            $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_COMPLETE, $days, $debt);
            return;
        }

        if ($status === 'not_cancellable') {
            // #cancelbtn isn't "Cancel Program" (already cancelled / not droppable):
            // like the VBA, do nothing and add no recap row.
            Log::info('ResumePayments: contact not cancellable, skipping', [
                'contact_id' => $contactId,
                'message' => $result['message'] ?? '',
            ]);
            return;
        }

        $msg = (string) ($result['message'] ?? '');

        // A drop that ran but didn't land = the client can't be auto-cancelled
        // (Returned Payments Hold / refund rejection). Jacob 2026-07-14: collect these
        // for a single manual-cancel digest to Rama (sent once per run below), and DON'T
        // set the status — so no Termination Notice fires on a client we didn't drop.
        if (stripos($msg, 'drop did not take effect') !== false) {
            // Returned Payments Hold / refund blocked the drop — a person must release &
            // cancel it. Lands on the Release Hold Requested sheet (Jacob 2026-07-20: no
            // separate Rama email; held clients live on that sheet).
            $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_HOLD, $days, $debt);
            return;
        }

        // Any other failure → retries on the next run (like the VBA); Backlog sheet.
        $statusChanges[] = $this->row($contactId, $name, self::STAGE_CANCEL_BACKLOG, $days, $debt);
    }

    /**
     * Phase-5 R-code grouping (note R08 sits with NSF here, unlike Phase 4).
     *
     * @return array{0:string,1:string,2:string}|null [statusTitle, reasonEnglish, reasonSpanish]
     */
    private function systemCancelInfo(string $rcode): ?array
    {
        return match ($rcode) {
            'R01', 'R08', 'R09' => ['System Cancel (NSF-3)', 'NSF', 'Fondos Insuficientes'],
            'R02', 'R15' => ['System Cancel (Account Closed-3)', 'Account Closed', 'Cierre de Cuenta'],
            'R03', 'R04', 'R13', 'R16', 'R20' => ['System Cancel (Invalid Bank-3)', 'Invalid Bank', 'Banco Invalido'],
            'R07' => ['System Cancel (Payment Stopped-3)', 'Payment Stopped', 'Pago Detenido'],
            'R10', 'R11', 'R17', 'R24', 'R29' => ['System Cancel (Unauthorized-3)', 'Payment Unauthorized', 'Autorización Necesaria'],
            default => null,
        };
    }

    /** Next business day (Mon–Fri), matching the VBA's ProcessDate. */
    private function nextBusinessDay(): string
    {
        $ts = strtotime('+1 day');
        while ((int) date('N', $ts) > 5) {
            $ts = strtotime('+1 day', $ts);
        }

        return date('Y-m-d', $ts);
    }

    /** DPP system account used for the EPF credit (per the VBA). */
    private function systemAccountFor(string $company): string
    {
        return strtoupper($company) === 'PLAW' ? '35564 - Progress Law' : '35281 - LDR - Main';
    }

    /**
     * Latest pending balance per contact (VBA: TOP 1 PENDING FROM CONTACT_BALANCES,
     * newest STAMP). Batched for all to-cancel contacts in one query.
     *
     * @param list<string> $contactIds
     * @return array<string, float> contactId => balance
     */
    private function fetchBalances(DBConnector $snowflake, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $cidList = implode(',', array_map('intval', $contactIds));
        $sql = "
            SELECT CONTACT_ID, PENDING
            FROM (
                SELECT CONTACT_ID, PENDING,
                       ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY STAMP DESC) AS RN
                FROM CONTACT_BALANCES
                WHERE CONTACT_ID IN ({$cidList})
            )
            WHERE RN = 1
        ";

        $map = [];
        foreach ($snowflake->query($sql)['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $map[$cid] = (float) ($row['PENDING'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * Sum of pending (uncleared, active, not cancelled) PF (EPF) or S (settlement)
     * transactions per contact. Batched.
     *
     * @param list<string> $contactIds
     * @return array<string, float> contactId => total
     */
    private function fetchPendingSums(DBConnector $snowflake, array $contactIds, string $transType): array
    {
        if ($contactIds === []) {
            return [];
        }
        $cidList = implode(',', array_map('intval', $contactIds));
        $type = $transType === 'PF' ? 'PF' : 'S';
        $sql = "
            SELECT CONTACT_ID, SUM(AMOUNT) AS TOTAL
            FROM TRANSACTIONS
            WHERE CONTACT_ID IN ({$cidList})
              AND TRANS_TYPE = '{$type}'
              AND CLEARED_DATE IS NULL
              AND CANCELLED = 0
              AND ACTIVE = 1
            GROUP BY CONTACT_ID
        ";

        $map = [];
        foreach ($snowflake->query($sql)['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid !== '') {
                $map[$cid] = (float) ($row['TOTAL'] ?? 0);
            }
        }

        return $map;
    }

    /**
     * Pending SETTLEMENT OFFERS per contact — the offer IDs the auto-void would target
     * (currently used only by the read-only --probe-void-settlements). A 'S' (settlement)
     * transaction links to its SETTLEMENT_OFFERS.ID via LINKED_TO (VBA chain). An offer is
     * "pending" if it has any uncleared/active/non-cancelled 'S' row; we also count cleared
     * rows so the future void can EXCLUDE partially-paid offers (fully_unpaid = no cleared
     * rows). Read-only; mirrors fetchPendingSums('S') but keeps the per-offer ids.
     *
     * @param list<string> $contactIds
     * @return array<string, list<array{offer_id:string,pending_rows:int,cleared_rows:int,fully_unpaid:bool}>>
     */
    private function fetchPendingSettlementOffers(DBConnector $snowflake, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $cidList = implode(',', array_map('intval', $contactIds));
        $sql = "
            SELECT
                CONTACT_ID,
                LINKED_TO AS OFFER_ID,
                SUM(CASE WHEN CLEARED_DATE IS NULL AND CANCELLED = 0 AND ACTIVE = 1 THEN 1 ELSE 0 END) AS PENDING_ROWS,
                SUM(CASE WHEN CLEARED_DATE IS NOT NULL THEN 1 ELSE 0 END) AS CLEARED_ROWS
            FROM TRANSACTIONS
            WHERE CONTACT_ID IN ({$cidList})
              AND TRANS_TYPE = 'S'
              AND LINKED_TO IS NOT NULL
            GROUP BY CONTACT_ID, LINKED_TO
            HAVING SUM(CASE WHEN CLEARED_DATE IS NULL AND CANCELLED = 0 AND ACTIVE = 1 THEN 1 ELSE 0 END) > 0
        ";

        $map = [];
        foreach ($snowflake->query($sql)['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid === '') {
                continue;
            }
            $cleared = (int) ($row['CLEARED_ROWS'] ?? 0);
            $map[$cid][] = [
                'offer_id' => (string) ($row['OFFER_ID'] ?? ''),
                'pending_rows' => (int) ($row['PENDING_ROWS'] ?? 0),
                'cleared_rows' => $cleared,
                'fully_unpaid' => $cleared === 0,
            ];
        }

        return $map;
    }

    /**
     * Language flag per contact for the cancel-note reason (VBA: data source NAME
     * contains "ES" — case-sensitive — OR the Spanish user-field → Spanish). Batched
     * into two queries regardless of contact count.
     *
     * @param list<string> $contactIds
     * @return array<string, bool> contactId => isEnglish
     */
    private function fetchEnglishFlags(DBConnector $snowflake, array $contactIds): array
    {
        if ($contactIds === []) {
            return [];
        }
        $cidList = implode(',', array_map('intval', $contactIds));

        $english = [];
        $sql1 = "SELECT c.ID AS ID, ds.NAME AS NAME FROM CONTACTS c LEFT JOIN DATA_SOURCES ds ON c.C_SOURCE = ds.ID WHERE c.ID IN ({$cidList})";
        foreach ($snowflake->query($sql1)['data'] ?? [] as $row) {
            $cid = (string) ($row['ID'] ?? '');
            if ($cid !== '') {
                $english[$cid] = strpos((string) ($row['NAME'] ?? ''), 'ES') === false;
            }
        }

        // Only the Spanish user-field can flip an otherwise-English contact to Spanish.
        $sql2 = "SELECT CONTACT_ID AS CONTACT_ID, F_STRING AS F_STRING FROM CONTACTS_USERFIELDS WHERE CONTACT_ID IN ({$cidList}) AND CUSTOM_ID = 299195";
        foreach ($snowflake->query($sql2)['data'] ?? [] as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid !== '' && ($english[$cid] ?? true) && (string) ($row['F_STRING'] ?? '') === 'Spanish') {
                $english[$cid] = false;
            }
        }

        return $english;
    }

    private function emailCancellationAudit(string $contactId, string $reason = ''): void
    {
        $detail = $reason !== '' ? " Reason: {$reason}." : ' (balance + pending settlements).';

        (new EmailSenderService())->sendMailHtml(
            'Cancellation Audit',
            "Client {$contactId} is pending cancellation but requires manual review.{$detail} Please review and process manually.",
            ['jennifer@libertydebtrelief.com'],
        );
    }

    private function emailReverseFees(string $contactId, string $name): void
    {
        (new EmailSenderService())->sendMailHtml(
            "{$contactId} - {$name}",
            'Hi, please reverse pending fees to zero balance file is cancelled. Thank you.',
            ['clients@setforth.com'],
            ['nancy@libertydebtrelief.com'],
        );
    }

    /**
     * Look up (or insert) the cancel-cooldown anchor row for an LLG_ID, using only
     * the existing columns (LLG_ID, Cancellation_Date) — no schema change needed.
     * Re-cancel is prevented by cancelProgram()'s #cancelbtn check, not a DB flag.
     *
     * Re-cancel RESET (Jacob 2026-07-22): if the stored anchor predates the client's current
     * NSF episode ($nsfAnchorDate = the oldest unresolved NSF's process date), they resolved
     * after a prior cooldown and then re-NSF'd — counting from the old date would cancel them
     * immediately with no fresh grace. In that case we ignore the stale anchor and start over
     * (insert a fresh row = Day 0). Erring here is safe: a false reset only grants MORE grace,
     * never a premature cancel.
     *
     * @return array{day:int}
     */
    private function resolveCancelDay(DBConnector $sqlConnector, string $llgId, string $today, string $nsfAnchorDate, bool $dryRun): array
    {
        $select = "SELECT TOP 1 Cancellation_Date
                   FROM TblEnrollmentCancellations
                   WHERE LLG_ID = ?
                   ORDER BY Cancellation_Date DESC";

        $result = $sqlConnector->querySqlServer($select, [$llgId]);

        if (!empty($result['data'])) {
            $cancellationDate = (string) ($result['data'][0]['Cancellation_Date'] ?? '');

            // Stale = the stored cooldown anchor is OLDER than the current NSF episode's start
            // → a resolve-then-recancel. Y-m-d strings compare lexicographically = chronologically.
            $stale = $cancellationDate !== '' && $nsfAnchorDate !== ''
                && substr($cancellationDate, 0, 10) < substr($nsfAnchorDate, 0, 10);

            if ($cancellationDate !== '' && !$stale) {
                // Business-day count (Mon–Fri only) per Jacob 2026-07-02 — weekends
                // don't advance the cooldown clock.
                return ['day' => $this->businessDaysBetween($cancellationDate, $today)];
            }

            // Stale (or unreadable) anchor → fall through and lay down a fresh Day-0 anchor.
            if ($stale) {
                Log::info('ResumePayments: cancel cooldown reset (resolve-then-recancel)', [
                    'llg_id' => $llgId,
                    'stale_anchor' => $cancellationDate,
                    'nsf_anchor_date' => $nsfAnchorDate,
                ]);
            }
        }

        if (!$dryRun) {
            $insert = "INSERT INTO TblEnrollmentCancellations (LLG_ID, Cancellation_Date) VALUES (?, ?)";
            $sqlConnector->querySqlServer($insert, [$llgId, $today]);
        }

        return ['day' => 0];
    }

    /**
     * Count business days (Mon–Fri) strictly AFTER $startDate up to and including
     * $endDate — the cancel-cooldown clock, which skips weekends (Jacob 2026-07-02).
     * Returns the 0-indexed internal $day; cancel fires at $day >= 5 (displayed Day 6).
     * e.g. anchor Thu = internal 0 (Day 1) → Fri 1 (Day 2) → (Sat/Sun no change) → Mon 2
     * (Day 3) → Tue 3 (Day 4) → Wed 4 (Day 5) → Thu 5 = cancel (Day 6). Inputs are Y-m-d
     * (a trailing time component is ignored).
     */
    private function businessDaysBetween(string $startDate, string $endDate): int
    {
        try {
            $start = new \DateTimeImmutable(substr(trim($startDate), 0, 10));
            $end = new \DateTimeImmutable(substr(trim($endDate), 0, 10));
        } catch (\Throwable $e) {
            return 0;
        }

        if ($end <= $start) {
            return 0;
        }

        $count = 0;
        $cursor = $start->modify('+1 day');
        while ($cursor <= $end) {
            if ((int) $cursor->format('N') <= 5) { // 1=Mon .. 5=Fri
                $count++;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $count;
    }

    /**
     * Phase 6 — Build the "Status Changes" Excel and send the recap email.
     * Recipients + two-section body live in {@see Formatter}. In dry-run the
     * workbook is built (to prove it works) but no email is sent.
     *
     * @param list<array{llg_id:string,name:string,stage:string,days:int,debt:float}> $statusChanges
     */
    private function sendRecap(DBConnector $connector, string $company, array $statusChanges, bool $dryRun): void
    {
        try {
            // --cancels-only runs skip the NSF step, so their report is a pure cancels
            // report (NSF stages hidden, "System Cancels" subject). The full run keeps
            // the standard NSF format.
            (new Formatter())->sendRecap($connector, $statusChanges, $company, $dryRun, $this, (bool) $this->option('cancels-only'));
        } catch (\Throwable $e) {
            $this->error("[{$company}] recap email failed: " . $e->getMessage());
            Log::error('GenerateResumePayments: sendRecap failed', [
                'company' => $company,
                'exception' => $e,
            ]);
        }
    }

    private function initializeSqlServerConnector(): DBConnector
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
