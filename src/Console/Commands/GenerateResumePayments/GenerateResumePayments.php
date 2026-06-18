<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\GenerateResumePayments;

use Cmd\Reports\Pmod\Services\ForthPayPmodExecutionGateway;
use Cmd\Reports\Services\DBConnector;
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
        {--company=* : Limit run to LDR and/or PLAW (default: both)}';

    protected $description = 'Process NSF contacts for LDR and Progress Law: update statuses, resume drafts, and execute system cancels per the ResumePayments VBA workflow.';

    private const PROCESSED_R_CODES = ['R01', 'R02', 'R03', 'R04', 'R07', 'R08', 'R09', 'R10', 'R11', 'R15', 'R16', 'R20'];

    private const SYSTEM_CANCEL_AGE_DAYS = 105;
    private const CANCEL_COOLDOWN_DAYS = 4;

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

    public function handle(ForthPayPmodExecutionGateway $gateway): int
    {
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

                $statusChanges = $this->processContacts($gateway, $snowflake, $sqlConnector, $company, $states, $dryRun);
                $this->processSystemCancels($gateway, $snowflake, $sqlConnector, $company, $states, $statusChanges, $dryRun);

                $this->sendRecap($sqlConnector, $company, $statusChanges);
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
     * @return list<string>
     */
    private function resolveCompanies(): array
    {
        $opt = array_values(array_filter(array_map(
            static fn (mixed $v): string => strtoupper(trim((string) $v)),
            (array) $this->option('company'),
        )));

        return $opt !== [] ? $opt : ['LDR', 'PLAW'];
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

        $contactIds = array_map(static fn (array $r): string => (string) ($r['CONTACT_ID'] ?? ''), $candidates);
        $contactIds = array_values(array_filter($contactIds, static fn (string $v): bool => $v !== ''));
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
        // TODO Phase 4: per-R-code status decision matrix + gateway calls + TblResumePayments insert.
        return [];
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
     * Behavior:
     *   - Skip contacts where is_current=true or age_days <= 105.
     *   - Look up the latest TblEnrollmentCancellations row for LLG-{contactId}.
     *       - No row → INSERT (Cancellation_Date = today), emit "System Cancel Pending - Day 0".
     *       - Row exists, Cancelled_At populated → contact already cancelled, skip silently.
     *       - Row exists, Day 0..3 → emit "System Cancel Pending - Day N".
     *       - Row exists, Day >= 4 → emit "System Cancel Ready - Day N" placeholder.
     *         (Actual Panther-driven cancel execution lands once DppSeleniumService
     *         selectors are recorded — see DppSeleniumService::cancelProgram.)
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

            $dayInfo = $this->resolveCancelDay($sqlConnector, $llgId, $today, $dryRun);

            if ($dayInfo['cancelled_at'] !== null) {
                // Already cancelled in a prior run — nothing to do, no email row.
                continue;
            }

            $day = $dayInfo['day'];

            if ($day < self::CANCEL_COOLDOWN_DAYS) {
                $statusChanges[] = [
                    'llg_id' => $llgId,
                    'name' => $name,
                    'status' => "System Cancel Pending - Day {$day}",
                ];
                continue;
            }

            // Day >= 4: ready to cancel. Until DppSeleniumService selectors are
            // wired, we surface a "Ready" placeholder so the email reader knows
            // the contact is queued for cancel; no Panther call yet.
            $statusChanges[] = [
                'llg_id' => $llgId,
                'name' => $name,
                'status' => "System Cancel Ready - Day {$day}",
            ];
        }
    }

    /**
     * Look up (or insert) the cancel-cooldown anchor row for an LLG_ID.
     *
     * @return array{day:int, cancelled_at:?string, attempts:int}
     */
    private function resolveCancelDay(DBConnector $sqlConnector, string $llgId, string $today, bool $dryRun): array
    {
        $select = "SELECT TOP 1 Cancellation_Date, Cancelled_At, Cancel_Attempts
                   FROM TblEnrollmentCancellations
                   WHERE LLG_ID = ?
                   ORDER BY Cancellation_Date DESC";

        $result = $sqlConnector->querySqlServer($select, [$llgId]);

        if (!empty($result['data'])) {
            $row = $result['data'][0];
            $cancellationDate = (string) ($row['Cancellation_Date'] ?? '');
            $cancelledAt = $row['Cancelled_At'] ?? null;
            $attempts = (int) ($row['Cancel_Attempts'] ?? 0);

            $day = 0;
            if ($cancellationDate !== '') {
                $day = (int) floor((strtotime($today) - strtotime($cancellationDate)) / 86400);
                $day = max(0, $day);
            }

            return [
                'day' => $day,
                'cancelled_at' => $cancelledAt !== '' ? $cancelledAt : null,
                'attempts' => $attempts,
            ];
        }

        if (!$dryRun) {
            $insert = "INSERT INTO TblEnrollmentCancellations (LLG_ID, Cancellation_Date) VALUES (?, ?)";
            $sqlConnector->querySqlServer($insert, [$llgId, $today]);
        }

        return [
            'day' => 0,
            'cancelled_at' => null,
            'attempts' => 0,
        ];
    }

    /**
     * Phase 6 — Build status-changes Excel and send the recap email.
     *
     * @param list<array{llg_id:string,name:string,status:string}> $statusChanges
     */
    private function sendRecap(DBConnector $sqlConnector, string $company, array $statusChanges): void
    {
        // TODO Phase 6: Formatter::buildWorkbook + EmailSenderService.
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
