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
 *              enrolled + not graduated, R-codes R01/R02/R03/R04/R07/R08/R09/R10/R11/R15/R16/R20).
 *   Phase 2  — Per contact: walk draft history to find latest R-code, count NSFs by
 *              distinct calendar month, compute age (days) from earliest NSF.
 *   Phase 3  — Recent-successful-draft adjustment (subtract count of cleared drafts in last 3 days).
 *   Phase 4  — Decide CRM action by (R-code, age) bucket; call Forth via gateway:
 *                 set client_status, click Resume Payments, log to TblResumePayments.
 *              Skip contacts whose status contains Dropped/LUSA-FUNDED/System Cancel/etc.
 *   Phase 5  — System Cancel for contacts with age > 105 days:
 *                 Day 0 → insert TblEnrollmentCancellations and stop (4-day cool-down)
 *                 Day 4+ → assemble Cancel Program from primitives:
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
        {--probe-cancel= : Diagnostic only — drive the cancel flow for ONE contact id and print which selectors exist, WITHOUT clicking save (commits nothing). Tenant = first --company (default LDR).}
        {--probe-resume= : Diagnostic only — call the Forth CRM resume-payments API for ONE contact id and print the raw status/body (read-only transaction list + a resume attempt). Run on TEST files only — a 2xx performs a real resume. Tenant = first --company (default LDR).}';

    protected $description = 'Process NSF contacts for LDR and Progress Law: update statuses, resume drafts, and execute system cancels per the ResumePayments VBA workflow.';

    private const PROCESSED_R_CODES = ['R01', 'R02', 'R03', 'R04', 'R07', 'R08', 'R09', 'R10', 'R11', 'R15', 'R16', 'R20'];

    private const SYSTEM_CANCEL_AGE_DAYS = 105;
    private const CANCEL_COOLDOWN_DAYS = 4;

    /** DPP "post" data-API client for client_status / notebody writes (Phase 4). */
    private DppDataClient $dppClient;

    /** Headless-browser client for the #resumebtn (Phase 4) and #cancelbtn (Phase 5) flows. */
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
        $this->dppClient = $dppClient;
        $this->dppSelenium = $dppSelenium;

        $probeCancelId = (string) ($this->option('probe-cancel') ?? '');
        if ($probeCancelId !== '') {
            return $this->runProbeCancel($probeCancelId);
        }

        $probeResumeId = (string) ($this->option('probe-resume') ?? '');
        if ($probeResumeId !== '') {
            return $this->runProbeResume($gateway, $probeResumeId);
        }

        $dryRun = (bool) $this->option('dry-run');
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

                $states = $this->computeNsfStates($snowflake, $rows);
                $this->applyRecentDraftAdjustment($snowflake, $states);

                $states = $this->applyRunFilters($states);
                if ($this->hasRunFilters()) {
                    $this->info(sprintf('[INFO] [%s] After --contact-id/--limit filter: %d', $company, count($states)));
                }

                $statusChanges = $this->processContacts($gateway, $snowflake, $sqlConnector, $company, $states, $dryRun);
                $this->info(sprintf('[INFO] [%s] Phase 4 status changes: %d', $company, count($statusChanges)));
                $this->processSystemCancels($gateway, $snowflake, $sqlConnector, $company, $states, $statusChanges, $dryRun);

                $this->sendRecap($company, $statusChanges, $dryRun);
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

        $this->info("[INFO] Probe-resume for contact {$contactId} as {$company} — TEST FILES ONLY (a 2xx performs a real resume).");

        try {
            $report = $gateway->probeResumePayments($tenant, $contactId);
        } catch (\Throwable $e) {
            $this->error('Probe failed: ' . $e->getMessage());
            Log::error('GenerateResumePayments: probe-resume failed', ['contact_id' => $contactId, 'exception' => $e]);

            return Command::FAILURE;
        }

        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
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
     * Apply --contact-id / --limit to the computed states so a first live run can
     * be scoped to one or a few contacts. Both phases (4 and 5) see the same set.
     *
     * @param list<array<string, mixed>> $states
     * @return list<array<string, mixed>>
     */
    private function applyRunFilters(array $states): array
    {
        $only = $this->runContactIds();
        if ($only !== []) {
            $set = array_flip($only);
            $states = array_values(array_filter(
                $states,
                static fn(array $s): bool => isset($set[(string) ($s['CONTACT_ID'] ?? '')]),
            ));
        }

        $limit = $this->option('limit');
        if ($limit !== null && (int) $limit > 0) {
            $states = array_slice($states, 0, (int) $limit);
        }

        return $states;
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
     * Phase 4 — For each contact, decide and execute the CRM action via the gateway.
     * Returns the list of (LLG_ID, name, status) status changes to include in the email.
     *
     * @param list<array<string, mixed>> $states
     * @return list<array{llg_id:string,name:string,status:string}>
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
                $statusChanges[] = $this->row($cid, $name, "{$plan} Enrolled (NSF Cleared)");

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
                    $statusChanges[] = $this->row($cid, $name, $status);
                } else {
                    $statusChanges[] = $this->row($cid, $name, "{$status} (Unable to Resume)");
                }

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
            $statusChanges[] = $this->row($cid, $name, $status);
        }

        return $statusChanges;
    }

    /**
     * @return array{llg_id:string,name:string,status:string}
     */
    private function row(string $contactId, string $name, string $status): array
    {
        return ['llg_id' => "LLG-{$contactId}", 'name' => $name, 'status' => $status];
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
     * VBA plan-detection ladder (identical effect in both LDR and PLAW macros):
     * LDR / "LT L" prefix -> LDR; contains "Progress" -> ProLaw; PLAW prefix ->
     * PLAW; anything else -> null (VBA `Stop`, we skip the contact).
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
            return 'PLAW';
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
            'R03', 'R04', 'R16', 'R20' => ['Invalid Bank', false],
            'R07', 'R08' => ['Payment Stopped', false],
            'R10', 'R11' => ['Unauthorized', false],
            default => null,
        };
    }

    private function tryResume(string $tenant, string $contactId, bool $dryRun): bool
    {
        try {
            $result = $this->dppSelenium->resumePayments($tenant, $contactId, $dryRun);
            $ok = ($result['status'] ?? '') === 'success';

            if (!$ok) {
                // Button absent / not actionable → VBA's "(Unable to Resume)".
                Log::warning('ResumePayments: not resumed (Unable to Resume)', [
                    'tenant' => $tenant,
                    'contact_id' => $contactId,
                    'message' => $result['message'] ?? '',
                ]);
            }

            return $ok;
        } catch (\Throwable $e) {
            // Browser/login failure (incl. Panther not installed yet) → treat as
            // "Unable to Resume" so the status write still proceeds.
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
     * Day counting (per Jacob 2026-06-18):
     *   "First it will check the cancels tables and if there is no match it will
     *    add it then this is day 0. If there is a match it will check today minus
     *    that date for the numbers of days. It will be on the report as Day 0,
     *    Day 1, Day 2 etc."
     *
     * Uses only the existing columns (LLG_ID, Cancellation_Date) — no schema change.
     * Re-cancel is prevented by cancelProgram()'s #cancelbtn check, not a DB flag.
     *
     * Behavior:
     *   - Skip contacts where is_current=true or age_days <= 105.
     *   - Look up the latest TblEnrollmentCancellations row for LLG-{contactId}.
     *       - No row → INSERT (Cancellation_Date = today), emit "System Cancel Pending - Day 0".
     *       - Row exists, Day 0..3 → emit "System Cancel Pending - Day N".
     *       - Row exists, Day >= 4 → with --execute-cancels, run the browser cancel
     *         (see executeSystemCancel); otherwise emit "System Cancel Ready - Day N".
     *
     * @param list<array<string, mixed>> $states
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
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
        $today = date('Y-m-d');
        $executeCancels = (bool) $this->option('execute-cancels');
        $maxCancels = (int) ($this->option('max-cancels') ?? 0);
        $processDate = $this->nextBusinessDay();
        $tenant = strtolower($company);
        $systemAccount = $this->systemAccountFor($company);

        // Pass 1 — day-count every age>105 contact (insert Day-0 rows, emit Day 0–3
        // / "Ready" warnings) and collect the ones that will actually be cancelled.
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

            $day = $this->resolveCancelDay($sqlConnector, $llgId, $today, $dryRun)['day'];

            if ($day < self::CANCEL_COOLDOWN_DAYS) {
                $statusChanges[] = ['llg_id' => $llgId, 'name' => $name, 'status' => "System Cancel Pending - Day {$day}"];
                continue;
            }

            // Day >= 4: ready to cancel.
            $cancelInfo = $this->systemCancelInfo((string) ($state['current_rcode'] ?? ''));

            // Without --execute-cancels (the default daily cron), or for an R-code we
            // don't map, just report the contact as ready and execute nothing.
            if (!$executeCancels || $cancelInfo === null) {
                $statusChanges[] = ['llg_id' => $llgId, 'name' => $name, 'status' => "System Cancel Ready - Day {$day}"];
                continue;
            }

            $toCancel[] = ['contact_id' => $contactId, 'llg_id' => $llgId, 'name' => $name, 'day' => $day, 'info' => $cancelInfo];
        }

        if ($toCancel === []) {
            return;
        }

        // Batch-fetch the Snowflake data for all to-cancel contacts in one shot each
        // (instead of ~5 round-trips per contact).
        $contactIds = array_column($toCancel, 'contact_id');
        $balances = $this->fetchBalances($snowflake, $contactIds);
        $epfs = $this->fetchPendingSums($snowflake, $contactIds, 'PF');
        $settlements = $this->fetchPendingSums($snowflake, $contactIds, 'S');
        $englishFlags = $this->fetchEnglishFlags($snowflake, $contactIds);

        // Pass 2 — execute, honoring the --max-cancels safety cap.
        $processed = 0;
        foreach ($toCancel as $item) {
            if ($maxCancels > 0 && $processed >= $maxCancels) {
                $statusChanges[] = ['llg_id' => $item['llg_id'], 'name' => $item['name'], 'status' => "System Cancel Deferred (cap) - Day {$item['day']}"];
                continue;
            }
            $processed++;

            $cid = $item['contact_id'];
            $this->executeSystemCancel(
                $tenant,
                $cid,
                $item['llg_id'],
                $item['name'],
                $item['info'],
                $balances[$cid] ?? 0.0,
                $epfs[$cid] ?? 0.0,
                $settlements[$cid] ?? 0.0,
                $englishFlags[$cid] ?? true,
                $systemAccount,
                $processDate,
                $today,
                $dryRun,
                $statusChanges,
            );
        }
    }

    /**
     * Day-4+ System Cancel for one contact (balances/language pre-fetched in batch
     * by the caller): add the cancel note, drive the browser cancel
     * (DppSeleniumService), then on the result set the final status and email
     * setforth for a negative balance. Mirrors the VBA's cancel block. Re-cancel is
     * prevented by cancelProgram()'s #cancelbtn check, so no DB tracking is needed.
     *
     * @param array{0:string,1:string,2:string} $cancelInfo [statusTitle, reasonEn, reasonEs]
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
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
        bool $dryRun,
        array &$statusChanges
    ): void {
        [$statusTitle, $reasonEn, $reasonEs] = $cancelInfo;
        $reason = $english ? $reasonEn : $reasonEs;

        if ($dryRun) {
            Log::info('ResumePayments: DRY RUN - would System Cancel', [
                'contact_id' => $contactId,
                'status' => $statusTitle,
                'balance' => $balance,
                'epf' => $epf,
                'scheduled_settlements' => $settlements,
            ]);
            $statusChanges[] = ['llg_id' => $llgId, 'name' => $name, 'status' => "DRY RUN: {$statusTitle}"];
            return;
        }

        // Cancel note (DPP post) before driving the browser, matching the VBA.
        $this->dppClient->addNote($tenant, $contactId, "System Cancel ({$reason}-3)", false);

        try {
            $result = $this->dppSelenium->cancelProgram($tenant, $contactId, [
                'balance' => $balance,
                'epf' => $epf,
                'scheduled_settlements' => $settlements,
                'process_date' => $processDate,
                'today' => $today,
                'system_account' => $systemAccount,
                'drop_reason' => 'Unable to Resolve NSF',
                'note' => 'Attempted to resume payments 4 times.',
            ]);
        } catch (\Throwable $e) {
            // One stuck contact must not abort the rest of the company's Phase 5 loop.
            Log::warning('ResumePayments: cancelProgram threw', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            $statusChanges[] = ['llg_id' => $llgId, 'name' => $name, 'status' => 'System Cancel failed: ' . $e->getMessage()];
            return;
        }

        $status = (string) ($result['status'] ?? 'failed');

        if ($status === 'manual_audit') {
            $this->emailCancellationAudit($contactId);
            $statusChanges[] = ['llg_id' => $llgId, 'name' => $name, 'status' => 'Manual Cancel Audit Required.'];
            return;
        }

        if ($status === 'success') {
            $this->dppClient->setClientStatus($tenant, $contactId, $statusTitle, false);

            if (($result['balance_branch'] ?? '') === 'negative') {
                $this->emailReverseFees($contactId, $name);
            }

            $statusChanges[] = ['llg_id' => $llgId, 'name' => $name, 'status' => $statusTitle];
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

        // Any other failure → report it; it retries on the next run (like the VBA).
        $statusChanges[] = ['llg_id' => $llgId, 'name' => $name, 'status' => 'System Cancel failed: ' . ($result['message'] ?? '')];
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
            'R03', 'R04', 'R16', 'R20' => ['System Cancel (Invalid Bank-3)', 'Invalid Bank', 'Banco Invalido'],
            'R07' => ['System Cancel (Payment Stopped-3)', 'Payment Stopped', 'Pago Detenido'],
            'R10', 'R11' => ['System Cancel (Unauthorized-3)', 'Payment Unauthorized', 'Autorización Necesaria'],
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

    private function emailCancellationAudit(string $contactId): void
    {
        (new EmailSenderService())->sendMailHtml(
            'Cancellation Audit',
            "Client {$contactId} is pending cancellation but has a balance and pending settlements. Please review and process manually.",
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
     * @return array{day:int}
     */
    private function resolveCancelDay(DBConnector $sqlConnector, string $llgId, string $today, bool $dryRun): array
    {
        $select = "SELECT TOP 1 Cancellation_Date
                   FROM TblEnrollmentCancellations
                   WHERE LLG_ID = ?
                   ORDER BY Cancellation_Date DESC";

        $result = $sqlConnector->querySqlServer($select, [$llgId]);

        if (!empty($result['data'])) {
            $cancellationDate = (string) ($result['data'][0]['Cancellation_Date'] ?? '');

            $day = 0;
            if ($cancellationDate !== '') {
                $day = (int) floor((strtotime($today) - strtotime($cancellationDate)) / 86400);
                $day = max(0, $day);
            }

            return ['day' => $day];
        }

        if (!$dryRun) {
            $insert = "INSERT INTO TblEnrollmentCancellations (LLG_ID, Cancellation_Date) VALUES (?, ?)";
            $sqlConnector->querySqlServer($insert, [$llgId, $today]);
        }

        return ['day' => 0];
    }

    /**
     * Phase 6 — Build the "Status Changes" Excel and send the recap email.
     * Recipients + two-section body live in {@see Formatter}. In dry-run the
     * workbook is built (to prove it works) but no email is sent.
     *
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     */
    private function sendRecap(string $company, array $statusChanges, bool $dryRun): void
    {
        try {
            (new Formatter())->sendRecap($statusChanges, $company, $dryRun, $this);
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
