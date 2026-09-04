<?php

namespace Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\CommissionAgentEmailFiles;
use Cmd\Reports\Services\CommissionResultsWriter;
use Cmd\Reports\Services\CommissionRosterProvider;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Services\UnassignedCommissionAgents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention Bonus Commission Report – both LDR and PLAW.
 *
 * Faithful port of VBA GenerateRetentionBonusCommission.
 * Queries Snowflake for retained clients in the previous month,
 * cross-references SQL Server TblEnrollment for payment/agent data,
 * checks TblSalesAgentViolations for deductions.
 *
 * LDR:  CUSTOM_IDs 742096/742101/742105, recon status_id 377650
 * PLAW: CUSTOM_IDs 742097/742102/742106, recon status_id 377687
 */
class GenerateRetentionBonusCommission extends Command
{
    protected $signature = 'reports:generate-retention-bonus-commission
                            {source=both : ldr | plaw | both}
                            {period? : Period start date YYYY-MM-01; defaults to first day of last month}
                            {--no-email : Build workbooks only, skip email}
                            {--test-recipient= : Send EVERY email (All + agent copies) only to this address}';

    protected $description = 'Generate Retention Bonus Commission report for LDR and/or PLAW.';

    private const SOURCE_CONFIG = [
        'ldr' => [
            'display'           => 'LDR',
            'custom_agent'      => 742096,
            'custom_date'       => 742101,
            'custom_results'    => 742105,
            'recon_status_id'   => 377650,
            'base_months_back'  => 4,
        ],
        'plaw' => [
            'display'           => 'Progress Law',
            'custom_agent'      => 742097,
            'custom_date'       => 742102,
            'custom_results'    => 742106,
            'recon_status_id'   => 377687,
            'base_months_back'  => 4,
        ],
    ];

    public function handle(): int
    {
        $arg     = strtolower((string)$this->argument('source'));
        $sources = ($arg === 'both') ? ['ldr', 'plaw'] : [$arg];
        $period  = trim((string) $this->argument('period'));
        if ($period !== '' && !$this->isValidPeriodStart($period)) {
            $this->error('period must be a valid YYYY-MM-01 date.');
            return Command::FAILURE;
        }
        foreach ($sources as $src) {
            if (!isset(self::SOURCE_CONFIG[$src])) {
                $this->error("Unknown source: $src");
                return Command::FAILURE;
            }
            $this->runForSource($src, $period ?: null);
        }
        return Command::SUCCESS;
    }

    private function isValidPeriodStart(string $period): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $period);

        return $date !== false && $date->format('Y-m-d') === $period && $date->format('d') === '01';
    }

    private function runForSource(string $source, ?string $periodStart = null): void
    {
        $cfg     = self::SOURCE_CONFIG[$source];
        $display = $cfg['display'];
        $this->info("[INFO] GenerateRetentionBonusCommission – $display");

        $reportStartDate = $periodStart ?: date('Y-m-01', strtotime('first day of last month'));
        $endDate         = date('Y-m-t', strtotime($reportStartDate));
        $baseStartDate   = date('Y-m-01', strtotime('-' . (int) $cfg['base_months_back'] . ' months', strtotime($reportStartDate)));
        $this->info("[INFO] Base period: $baseStartDate → $endDate; report cutoff period: $reportStartDate → $endDate");

        try {
            $sf  = DBConnector::fromEnvironment($source);
            $sql = $this->initSqlServer($source);
        } catch (\Throwable $e) {
            $this->error("[$display] Connector init: " . $e->getMessage());
            return;
        }

        try {
            // STEP 1 - base data mirrors the VBA raw userfield joins.
            $rows = $this->fetchBase($sf, $cfg, $baseStartDate, $endDate);
            $this->info("[INFO] [$display] Base rows: " . count($rows));

            $ids = array_filter(array_map(fn($r) => (int) $this->rowValue($r, 'ID', 0), $rows));
            $idList = empty($ids) ? '0' : implode(',', $ids);

            // STEP 2 – reconsideration dates
            $reconMap = $this->fetchReconsiderationDates($sf, $cfg['recon_status_id'], $idList);
            foreach ($rows as &$row) {
                $id = (string) $this->rowValue($row, 'ID', '');
                if (!empty($reconMap[$id])) {
                    $row['RECONSIDERATION_DATE'] = $reconMap[$id];
                } else {
                    $dates = array_filter([
                        $this->dateValue($this->rowValue($row, 'RETENTION_DATE')),
                        $this->dateValue($this->rowValue($row, 'DROPPED_DATE')),
                    ]);
                    $row['RECONSIDERATION_DATE'] = $dates ? min($dates) : null;
                }
            }
            unset($row);

            // STEP 3 – retained dates
            $retainedMap = $this->fetchRetainedDates($sf, $idList);
            foreach ($rows as &$row) {
                $recon = $this->dateValue($row['RECONSIDERATION_DATE'] ?? null);
                $row['RETAINED_DATE'] = null;
                $id = (string) $this->rowValue($row, 'ID', '');
                if ($recon && !empty($retainedMap[$id])) {
                    foreach ($retainedMap[$id] as $rd) {
                        $retainedDate = $this->dateValue($rd);
                        if ($retainedDate && $retainedDate >= $recon) { $row['RETAINED_DATE'] = $retainedDate; break; }
                    }
                }
            }
            unset($row);

            // STEP 4 – SQL Server enrollment data in one batched query
            $llgIds = array_values(array_unique(array_filter(
                array_map(fn ($r) => 'LLG-' . (string) $this->rowValue($r, 'ID', ''), $rows),
                fn (string $id): bool => $id !== 'LLG-'
            )));
            $enrollmentMap = $this->fetchEnrollmentMap($sql, $llgIds);

            foreach ($rows as &$row) {
                $id   = (string) $this->rowValue($row, 'ID', '');
                $en   = $enrollmentMap['LLG-' . $id] ?? null;
                $row['FIRST_PAYMENT_CLEARED_DATE'] = $en ? $this->dateValue($this->rowValue($en, 'first_payment_cleared_date')) : null;
                $row['PAYMENTS']                   = $en ? (int) $this->rowValue($en, 'payments', 0) : 0;
                $row['AGENT']                      = $en ? $this->rowValue($en, 'agent') : null;
                $row['COMMISSION_RATE']            = $en ? (float) $this->rowValue($en, 'commission_rate', 0) : 0;
            }
            unset($row);

            // VBA: remove rows with Payments < 2
            $rows = array_values(array_filter($rows, fn($r) => (int) $this->rowValue($r, 'PAYMENTS', 0) >= 2));

            // STEP 5 – calculate cutoff (first payment + 3 months) and filter
            foreach ($rows as &$row) {
                $fp = $this->dateValue($row['FIRST_PAYMENT_CLEARED_DATE'] ?? null);
                if ($fp) {
                    $row['CUTOFF'] = date('Y-m-d', strtotime('+3 months', strtotime($fp)));
                } else {
                    $row['CUTOFF'] = null;
                }
            }
            unset($row);

            // VBA filter conditions
            $rows = array_values(array_filter($rows, function($row) use ($reportStartDate, $endDate) {
                $retenDate = $this->dateValue($this->rowValue($row, 'RETENTION_DATE'));
                $dropped   = $this->dateValue($this->rowValue($row, 'DROPPED_DATE'));
                $cutoff    = $this->dateValue($row['CUTOFF'] ?? null);

                // If retention_date > cutoff → remove
                if (!$cutoff) return false;
                if ($retenDate && $cutoff && $retenDate > $cutoff) return false;
                // If dropped and dropped <= cutoff → remove
                if ($dropped && $cutoff && $dropped <= $cutoff) return false;
                // Expected workbook is for clients whose cutoff lands in the report month.
                if ($cutoff && ($cutoff < $reportStartDate || $cutoff > $endDate)) return false;
                // If payments < 3 → remove
                if ((int) $this->rowValue($row, 'PAYMENTS', 0) < 3) return false;

                return true;
            }));
            $this->info("[INFO] [$display] Eligible rows after filtering: " . count($rows));

            // STEP 6 – commission and violation deductions in one batched query
            $eligibleIds = array_values(array_unique(array_filter(
                array_map(fn ($r) => (string) $this->rowValue($r, 'ID', ''), $rows),
                fn (string $id): bool => $id !== ''
            )));
            $violationMap = $this->fetchViolationMap($sql, $eligibleIds);

            foreach ($rows as &$row) {
                $id   = (string) $this->rowValue($row, 'ID', '');
                $debt = (float) $this->rowValue($row, 'ENROLLED_DEBT', 0);
                $rate = (float) $this->rowValue($row, 'COMMISSION_RATE', 0);

                if ($rate == 0) {
                    // Default rate: 0.38%
                    $row['COMMISSION_RATE']    = 0.38;
                    $row['VIOLATIONS']         = 0;
                    $row['RETENTION_COMMISSION'] = round($debt * 0.38 / 100 / 2, 2);
                    $row['AGENT_DEDUCTION']    = '';
                } else {
                    $pts = $violationMap[$id] ?? 0;
                    $violations = min($pts / 10, 1.0);
                    $row['VIOLATIONS'] = $violations;

                    $base             = round($debt * $rate / 100 / 2, 2);
                    $adjusted         = round($debt * $rate / 100 * (1 - $violations), 2);
                    $row['RETENTION_COMMISSION'] = $base;
                    $row['AGENT_DEDUCTION']      = min($adjusted, $base);
                }
            }
            unset($row);

            // Aurora Payroll Review is the roster eligibility gate. Persist the
            // complete raw source feed here so a legacy or hard-coded roster can
            // never hide a valid retention employee before payroll evaluates it.

            // Persist per-agent retention BONUS COMMISSION to Azure for the Commission Review app
            // (best-effort; never blocks the report). Aggregates per-contact RETENTION_COMMISSION by agent.
            $bonusByAgent = [];
            foreach ($rows as $r) {
                $agent = trim((string) ($r['RETENTION_AGENT'] ?? ''));
                if ($agent === '') continue;
                $bonusByAgent[$agent] = ($bonusByAgent[$agent] ?? 0) + (float) ($r['RETENTION_COMMISSION'] ?? 0);
            }
            $bonusResults = [];
            foreach ($bonusByAgent as $agentName => $amount) {
                $bonusResults[] = ['agent' => $agentName, 'amount' => round($amount, 2)];
            }
            // A re-run must clear a previously calculated bonus when an agent no
            // longer qualifies. resetColumn() zeroes the whole Bonus_Commission
            // column for the period first, then persist() writes only the agents
            // who earned a bonus this run — so stale bonuses are cleared without
            // needing a separate roster list.
            CommissionResultsWriter::resetColumn($sql, 'retention', $source, $reportStartDate, 'Bonus_Commission');
            CommissionResultsWriter::persist($sql, 'retention', $source, $reportStartDate, 'Bonus_Commission', $bonusResults);

            // Build and send workbooks
            $agentNames  = array_values(array_unique(array_filter(
                array_map(fn ($r) => (string) ($r['RETENTION_AGENT'] ?? ''), $rows)
            )));
            sort($agentNames, SORT_STRING | SORT_FLAG_CASE);

            // Jacob, 2026-09-03: "the summary names come from the roster. And anyone we have data
            // for that is not on the roster is listed separately."
            //
            // Until now the summary was keyed on RETENTION_AGENT — the CRM userfield, verbatim —
            // so whatever someone typed into the CRM became a summary row. That is how "Andrea
            // MendozE" appeared as its own agent. The roster decides the summary now.
            //
            // fromRoster() returns null when the roster is empty OR unreachable, and the two are
            // worth separating: this report has no built-in agent list to fall back on, so a broken
            // roster would otherwise mean emailing a blank summary. Null keeps the previous
            // CRM-derived behaviour and warns, which is wrong-but-familiar rather than silently empty.
            $rosterAgents = CommissionRosterProvider::fromRoster($sql, 'retention', $source);
            if ($rosterAgents === null) {
                $this->warn(
                    "[WARN] [$display] The retention roster in Azure (dbo.TblCommissionRoster) is empty or "
                    . 'unreachable. Falling back to the CRM agent names — the summary is NOT roster-driven '
                    . 'for this run. Check that the Commission Review roster is mirroring to Azure.'
                );
                Log::warning("GenerateRetentionBonusCommission[$display]: retention roster unavailable; summary fell back to CRM names.");
            } else {
                $this->info("[INFO] [$display] Roster agents: " . count($rosterAgents));
            }

            // Per-agent roster source, so the company-mismatch flag is judged against the brand each
            // agent is actually pinned to. An agent rostered to 'both' never flags.
            $rosterSources = CommissionRosterProvider::rosterSources($sql, 'retention', $source);

            // The employee lookup has to cover roster members too, not just people with data —
            // a roster member with no retention activity this month still needs their company
            // checked and still belongs on the summary at $0.
            $employeeLookupNames = $rosterAgents === null
                ? $agentNames
                : array_values(array_unique(array_merge($agentNames, $rosterAgents)));
            $employeeMap = $this->fetchEmployeeMap($sql, $employeeLookupNames);

            $unassigned = $this->unassignedAgents($rows, $rosterAgents);
            if ($unassigned !== []) {
                $this->warn(
                    "[WARN] [$display] " . count($unassigned) . ' agent(s) earned commission this period but are '
                    . 'not on the retention roster: ' . implode(', ', array_column($unassigned, 'agent'))
                );
            }

            $formatter = new BonusFormatter();
            $allFile = $formatter->buildWorkbook(
                $rows, $display, $reportStartDate, $endDate, $employeeMap, null, $rosterSources, $rosterAgents, $unassigned
            );

            if ($allFile) {
                $this->info("[INFO] [$display] Workbook: {$allFile['filename']}");

                // Per-agent workbooks are no longer built or emailed.
                // Jacob, 2026-09-04: "don't sent the individual ones since we can send from Debt
                // Plete" — Commission Review's Send / Send All emails each agent their own statement,
                // so this loop was a second, competing delivery path. It also produced a workbook per
                // CRM name, which meant a file titled after the "ANDREA MENDOZE" typo.
                // Nothing else consumed them: GenerateRetentionManagerCommission reads only the
                // " - All.xlsx" snapshot.
                $files = [$allFile];

                if ($this->option('no-email')) {
                    $this->info("[INFO] [$display] --no-email set; skipping email send.");
                } else {
                    $this->sendReport($sql, $files, $display, $unassigned, $rosterAgents === null);
                    foreach ($files as $f) {
                        if (file_exists($f['path'])) {
                            @unlink($f['path']);
                        }
                    }
                }
            }

        } catch (\Throwable $e) {
            $this->error("[$display] Failed: " . $e->getMessage());
            Log::error("GenerateRetentionBonusCommission[$display]: failed", ['ex' => $e]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    /** @param string[] $llgIds @return array<string,array<string,mixed>> */
    private function fetchEnrollmentMap(DBConnector $sql, array $llgIds): array
    {
        $map = [];
        foreach (array_chunk($llgIds, 500) as $chunk) {
            $inList = implode(',', array_map(
                fn (string $id): string => "'" . str_replace("'", "''", $id) . "'",
                $chunk
            ));
            $res = $sql->querySqlServer(
                "SELECT LLG_ID, First_Payment_Cleared_Date AS first_payment_cleared_date,
                        Payments AS payments, Agent AS agent, Commission_Rate AS commission_rate
                 FROM TblEnrollment WHERE LLG_ID IN ($inList)"
            );
            foreach ($res['data'] ?? [] as $row) {
                $key = (string) $this->rowValue($row, 'LLG_ID', '');
                if ($key !== '') {
                    $map[$key] = $row;
                }
            }
        }
        return $map;
    }

    /** @param string[] $ids @return array<string,float> */
    private function fetchViolationMap(DBConnector $sql, array $ids): array
    {
        $map = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $inList = implode(',', array_map(
                fn (string $id): string => "'" . str_replace("'", "''", $id) . "'",
                $chunk
            ));
            $res = $sql->querySqlServer(
                "SELECT CID, ISNULL(SUM(Points),0) AS pts
                 FROM TblSalesAgentViolations
                 WHERE CID IN ($inList)
                 GROUP BY CID"
            );
            foreach ($res['data'] ?? [] as $row) {
                $key = (string) $this->rowValue($row, 'CID', '');
                if ($key !== '') {
                    $map[$key] = (float) $this->rowValue($row, 'pts', 0);
                }
            }
        }
        return $map;
    }

    private function fetchBase(DBConnector $sf, array $cfg, string $start, string $end): array
    {
        $ca = (int)$cfg['custom_agent'];
        $cd = (int)$cfg['custom_date'];
        $cr = (int)$cfg['custom_results'];
        $nextDay = date('Y-m-d', strtotime('+1 day', strtotime($end)));
        $sql = "
            SELECT
                c.ID,
                CONCAT(c.FIRSTNAME,' ',c.LASTNAME)  AS CLIENT,
                cu1.F_STRING AS RETENTION_AGENT,
                LEFT(cu2.F_DATE, 10) AS RETENTION_DATE,
                cu3.F_STRING AS IMMEDIATE_RESULTS,
                d.ENROLLED_DEBT,
                TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.DROPPED_DATE) AS DATE), 'YYYY-MM-DD') AS DROPPED_DATE
            FROM CONTACTS c
            LEFT JOIN CONTACTS_USERFIELDS cu1 ON c.ID = cu1.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_DATE
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = $cd
            ) cu2 ON c.ID = cu2.CONTACT_ID
            LEFT JOIN CONTACTS_USERFIELDS cu3 ON c.ID = cu3.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS WHERE ENROLLED=1 AND _FIVETRAN_DELETED=FALSE GROUP BY CONTACT_ID
            ) d ON c.ID=d.CONTACT_ID
            WHERE cu1.CUSTOM_ID = $ca
              AND cu3.CUSTOM_ID = $cr
              AND cu3.F_STRING = 'Retained'
              AND cu2.F_DATE >= '$start'
              AND cu2.F_DATE < '$nextDay'
            ORDER BY cu1.F_STRING ASC
        ";
        $res = $sf->query($sql);
        return $res['data'] ?? [];
    }

    private function rowValue(array $row, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $lowerKey = strtolower($key);
        foreach ($row as $rowKey => $value) {
            if (strtolower((string) $rowKey) === $lowerKey) {
                return $value;
            }
        }

        return $default;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;

            return $timestamp > 0 ? date('Y-m-d', $timestamp) : null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function fetchReconsiderationDates(DBConnector $sf, int $statusId, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) AS DATE), 'YYYY-MM-DD') AS RECON_DATE
            FROM CONTACTS_STATUS cs WHERE cs.STATUS_ID=$statusId
             AND cs.CONTACT_ID IN ($idList)
            ORDER BY cs.CONTACT_ID ASC, CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) ASC
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $id = (string)$r['CONTACT_ID'];
            if (!isset($map[$id])) $map[$id] = $r['RECON_DATE'];
        }
        return $map;
    }

    private function fetchRetainedDates(DBConnector $sf, string $idList): array
    {
        $sql = "
            SELECT cs.CONTACT_ID, TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) AS DATE), 'YYYY-MM-DD') AS RETAINED_DATE
            FROM CONTACTS_STATUS cs
            LEFT JOIN CONTACTS_LEAD_STATUS cls ON cs.STATUS_ID=cls.ID
            WHERE UPPER(cls.TITLE) LIKE '%ENROLLED%' AND UPPER(cls.TITLE) NOT LIKE '%RECONSIDERATION%'
             AND cs.CONTACT_ID IN ($idList)
            ORDER BY cs.CONTACT_ID ASC, CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) ASC
        ";
        $res = $sf->query($sql);
        $map = [];
        foreach ($res['data'] ?? [] as $r) {
            $map[(string)$r['CONTACT_ID']][] = $r['RETAINED_DATE'];
        }
        return $map;
    }

    /**
     * Email All to the report distribution list; one email per agent workbook to Rama only.
     *
     * @param array<int,array{filename:string,path:string}>      $files
     * @param array<int,array{agent:string,amount:float}>         $unassigned Earners missing from the roster.
     * @param bool                                                $rosterUnavailable
     */
    private function sendReport(
        DBConnector $sql,
        array $files,
        string $display,
        array $unassigned = [],
        bool $rosterUnavailable = false
    ): void {
        $email = new EmailSenderService();
        $reportNames = ['RetentionBonusCommission', 'Retention Bonus Commission'];
        $baseSubject = "Retention Bonus Commission - $display";
        // HTML on BOTH paths. --test-recipient sends via sendMailHtml while the real send used
        // sendMailUsingTblReports (plain text), so a padded text block rendered correctly to the
        // list but collapsed onto one line in the test copy — meaning the test never showed what
        // Jacob would actually receive.
        $baseBody = '<p>See attached Retention Bonus Commission - ' . htmlspecialchars($display) . '.</p>'
            . UnassignedCommissionAgents::emailBlockHtml($unassigned, $rosterUnavailable, 'retention roster');

        // --test-recipient: redirect EVERY email for this run to one address.
        $testTo = trim((string) ($this->option('test-recipient') ?: ''));

        $parts = CommissionAgentEmailFiles::partition($files);
        foreach ($parts['missing'] as $missingName) {
            $this->warn("[WARN] [$display] Attachment missing: {$missingName}");
        }
        $allFiles = $parts['all'];
        $agentFiles = $parts['agents'];

        if ($allFiles !== []) {
            $attachments = CommissionAgentEmailFiles::toAttachments($allFiles);
            if ($testTo !== '') {
                $this->info("[INFO] [$display] --test-recipient set — All report only to $testTo");
                $email->sendMailHtml($baseSubject, $baseBody, [$testTo], [], [], $attachments);
            } else {
                $sent = $email->sendMailUsingTblReportsHtml(
                    $sql,
                    $reportNames,
                    [strtoupper($display)],
                    $baseSubject,
                    $baseBody,
                    $attachments,
                    true
                );
                if (!$sent) {
                    $email->sendMailHtml($baseSubject, $baseBody, ['oduai@libertydebtrelief.com'], [], [], $attachments);
                }
            }
            $this->info("[INFO] [$display] All report emailed (" . count($attachments) . " attachment(s)).");
        } else {
            $this->warn("[WARN] [$display] No All workbook to email.");
        }

        // Per-agent copies are not emailed from here any more — Jacob, 2026-09-04: "don't sent the
        // individual ones since we can send from Debt Plete". Commission Review's Send / Send All
        // delivers each agent their own statement, so mailing them here as well was a second,
        // competing delivery path. $agentFiles is expected to be empty; warn rather than send if a
        // caller ever passes one, so a partial revert cannot quietly resume the old behaviour.
        if ($agentFiles !== []) {
            $this->warn(
                "[WARN] [$display] " . count($agentFiles) . ' per-agent workbook(s) were built but not '
                . 'emailed. Agent statements are sent from Commission Review in DebtPlete.'
            );
        }
    }

    /**
     * Agents who earned retention commission this period but are not on the roster.
     * The rule itself lives in UnassignedCommissionAgents so all three agent reports share it.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>|null         $rosterAgents Null when the roster is unavailable.
     * @return array<int,array{agent:string,amount:float}> Highest earner first.
     */
    private function unassignedAgents(array $rows, ?array $rosterAgents): array
    {
        return UnassignedCommissionAgents::fromTotals(
            UnassignedCommissionAgents::totals($rows, 'RETENTION_AGENT', 'RETENTION_COMMISSION'),
            $rosterAgents
        );
    }

    /**
     * The "Unassigned Agents" block appended to the All email body. These emails go out as plain
     * text (sendMailUsingTblReports -> sendMail, contentType Text), so columns are padded.
     *
     * @param array<int,array{agent:string,amount:float}> $unassigned
     */
    private function unassignedEmailBlock(array $unassigned, bool $rosterUnavailable): string
    {
        return UnassignedCommissionAgents::emailBlock($unassigned, $rosterUnavailable, 'retention roster');
    }

    private function fetchEmployeeMap(DBConnector $sql, array $agents): array
    {
        if (empty($agents)) {
            return [];
        }
        $list = implode(',', array_map(fn ($a) => "'" . str_replace("'", "''", $a) . "'", $agents));
        $res  = $sql->querySqlServer(
            "SELECT Employee_Name, Location, Company FROM TblEmployees WHERE Employee_Name IN ($list)"
        );
        $map = [];
        foreach ($res['data'] ?? [] as $row) {
            $name       = strtoupper((string) ($row['Employee_Name'] ?? $row['employee_name'] ?? ''));
            $map[$name] = [
                'location' => (string) ($row['Location'] ?? $row['location'] ?? ''),
                'company' => (string) ($row['Company'] ?? $row['company'] ?? ''),
            ];
        }
        return $map;
    }

    private function initSqlServer(string $source): DBConnector
    {
        $c = DBConnector::fromEnvironment($source);
        $c->initializeSqlServer();
        return $c;
    }
}
