<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\ReferralCommissions;

use Cmd\Reports\Pmod\Services\DppDataClient;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\GraphMailboxClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Referral commissions for Monevo personal-loan fundings — the port of `ProcessReferralCommissions`
 * and `ProcessReferralCommissionsCCS` in CMD LDR.xlsm, together with the two Outlook rules that
 * fired them.
 *
 * Monevo emails "MONEVO US PARTNER FUND DETAILS LIBERTY LENDING" with the workbook
 * "Monevo US Partner Fund Details.xlsx" attached to both automation mailboxes. The workbook ran:
 *
 *   automation@libertydebtrelief.com  -> ProcessReferralCommissions           (the `lt` pass)
 *   automation@lendingtower.com       -> ProcessReferralCommissions
 *                                        + ProcessReferralCommissionsCCS      (the `lt` and `ccs` passes)
 *
 * then marked the message read and moved it to Inbox\Archive. `MAILBOXES` reproduces that map.
 *
 * Per funded row (see MonevoFundDetailsParser for the columns), each pass does exactly what its
 * VBA did, in the same order:
 *
 *   1. Skip the row unless the contact exists — `lt`: LDR `TblContacts` by `LLG_ID = 'LLG-<P>'`;
 *      `ccs`: the CCS database's `TblContacts` by `CID = <P>`.
 *   2. Agent PK = `TblEmployees.PK` by `Employee_Name` (`lt`: LDR; `ccs`: the CCS database),
 *      `Val()`-style — 0 when the name is unknown. Enrollment status from LDR `TblEnrollment`.
 *   3. Already processed? `TblPayrollAdjustments` with `Category = 'Commission'`, the LLG id in
 *      `Notes`, and `Agent_ID` = the agent PK (`lt`) or 0 (`ccs`). The workbook painted those rows
 *      red and did nothing else. That row is the idempotency key, so re-running a file is safe.
 *   4. INSERT `TblPayrollAdjustments`: Payroll_Date = the 10th of the month after the funding date
 *      (the month after that if the 10th has already passed); Amount = 50 when the agent PK is a
 *      USA employee in LDR `TblEmployees`, otherwise 0; Agent_ID = the PK (`lt`) or 0 (`ccs`).
 *   5. CRM: `client_status` = "Funded" (`lt`) / "Funded Completed" (`ccs`) unless the enrollment
 *      status starts with "LDR Enrolled" or "PLAW Enrolled"; then `notebody` with the funding note.
 *   6. INSERT `TblFundings` (Source = Monevo; `Agent` holds the employee PK, as the VBA stored it).
 *   7. CRM: `funded_by`, `loan_amount`, `apr`, `interest_rate`, `loan_term`.
 *
 * CRM writes go through DppDataClient (the DPP "post" endpoint the VBA's UpdateCRMDataLT /
 * UpdateCRMDataCCS used) with the `lt` / `ccs` keys — DPP_POST_API_KEY_LT / DPP_POST_API_KEY_CCS.
 *
 * Two things the VBA does that look like bugs are kept on purpose, because production data was
 * written by them for years and this must line up with it: the CCS pass checks the CCS employee PK
 * against LDR's `TblEmployees` for the USA test, and an `lt` row whose agent is not in
 * `TblEmployees` gets `Agent_ID = 0` and therefore shares its duplicate check with the `ccs` pass.
 *
 * --dry-run reads the file and runs every lookup, but writes nothing: no SQL, no CRM, and the
 * message stays unread in the Inbox.
 */
class ProcessReferralCommissions extends Command
{
    protected $signature = 'referral-commissions:process
        {--file= : Process a saved "Monevo US Partner Fund Details.xlsx" instead of polling the mailboxes}
        {--passes=lt,ccs : Passes to run for --file: lt (ProcessReferralCommissions) and/or ccs (ProcessReferralCommissionsCCS). When polling, each mailbox runs the passes its Outlook rule did}
        {--mailbox= : Poll only this automation mailbox (default: every one in MAILBOXES)}
        {--keep-in-inbox : After processing a message, do not mark it read or move it to Inbox\Archive}
        {--dry-run : Read the file and every lookup, write nothing (no SQL, no CRM, mail left where it is)}';

    protected $description = 'Import Monevo funded-loan referrals: payroll commission rows, TblFundings and CRM funding fields (replaces the ProcessReferralCommissions VBA and its Outlook rules).';

    /**
     * Subject prefix. The suffix is the company the mailbox belongs to — "… Liberty Lending" on the
     * LDR mailbox, "… Lending Tower" on LT (seen 16 Sep 2026) — so only the common part is matched.
     * Monevo also sends "Monevo US Offer Detail …" and "Monevo US Affiliate Lead Data …" daily;
     * the prefix keeps those out.
     */
    public const SUBJECT = 'Monevo US Partner Fund Details';
    /** Attachment name prefix; matched case-insensitively with an .xlsx extension. */
    public const ATTACHMENT = 'Monevo US Partner Fund Details';
    public const ARCHIVE_FOLDER = 'Archive';
    public const SOURCE = 'Monevo';

    /** The flat commission a USA agent earns per funded referral (VBA: `Commission = 50`). */
    public const USA_COMMISSION = 50.0;

    /**
     * The mailboxes polled, the passes each runs, and the Graph credentials prefix that reaches it.
     *
     * The workbook watched both automation mailboxes, but since the company split only Lending
     * Tower's still receives Monevo's mail (LDR's last one is Nov 2025), and Jacob asked on
     * 16 Sep 2026 to monitor LT only. It is in a separate Microsoft tenant — kept separate on
     * purpose — so it has its own app registration under `GRAPH_LT_*`.
     */
    public const MAILBOXES = [
        'automation@lendingtower.com' => ['passes' => ['lt', 'ccs'], 'graph' => 'GRAPH_LT'],
    ];

    private const PASSES = [
        'lt' => ['label' => 'ProcessReferralCommissions (Lending Tower)', 'tenant' => 'lt', 'funded_status' => 'Funded'],
        'ccs' => ['label' => 'ProcessReferralCommissionsCCS', 'tenant' => 'ccs', 'funded_status' => 'Funded Completed'],
    ];

    protected bool $dryRun = false;

    protected ?DBConnector $ldr = null;
    protected ?PDO $ccs = null;
    protected ?DppDataClient $dpp = null;
    /** @var array<string, GraphMailboxClient> keyed by credentials prefix */
    protected array $mail = [];

    /** @var array<string, int> */
    protected array $counts = [];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        if ($this->dryRun) {
            $this->warn('[WARN] --dry-run: nothing will be written and no mail will be moved.');
        }

        try {
            $file = trim((string) ($this->option('file') ?? ''));
            if ($file !== '') {
                return $this->processFile($file, $this->passesOption(), 'file ' . basename($file));
            }

            return $this->pollMailboxes();
        } catch (Throwable $e) {
            $this->error('[ERROR] ' . $e->getMessage());
            Log::error('ProcessReferralCommissions failed', ['exception' => $e]);

            return Command::FAILURE;
        }
    }

    // ─── Mailboxes ────────────────────────────────────────────────────────

    protected function pollMailboxes(): int
    {
        $only = strtolower(trim((string) ($this->option('mailbox') ?? '')));
        $mailboxes = self::MAILBOXES;
        if ($only !== '') {
            if (!isset($mailboxes[$only])) {
                $this->error("Unknown mailbox {$only}. Known: " . implode(', ', array_keys($mailboxes)));

                return Command::FAILURE;
            }
            $mailboxes = [$only => $mailboxes[$only]];
        }

        $failed = false;
        $processedMessages = 0;
        foreach ($mailboxes as $mailbox => $config) {
            $passes = $config['passes'];
            $prefix = $config['graph'];
            if (!GraphMailboxClient::isConfigured($prefix)) {
                $this->warn("[WARN] {$mailbox}: skipped — {$prefix}_TENANT_ID / {$prefix}_CLIENT_ID / {$prefix}_CLIENT_SECRET are not set.");
                continue;
            }
            $this->info("[INFO] {$mailbox}: looking for \"" . self::SUBJECT . '…" with ' . self::ATTACHMENT . '*.xlsx');
            $messages = $this->mail($prefix)->listInboxMessages($mailbox, self::SUBJECT);
            if ($messages === []) {
                $this->line('  nothing waiting.');
                continue;
            }

            foreach ($messages as $message) {
                $received = substr($message['receivedDateTime'], 0, 19);
                $attachment = null;
                if ($message['hasAttachments']) {
                    foreach ($this->mail($prefix)->listAttachments($mailbox, $message['id']) as $candidate) {
                        if (self::isFundDetailsWorkbook($candidate['name'])) {
                            $attachment = $candidate;
                            break;
                        }
                    }
                }
                if ($attachment === null) {
                    // The VBA left such a message in the Inbox untouched (its loop found nothing to save
                    // and never reached Item.Move). Same here.
                    $this->warn("  [WARN] {$received} \"{$message['subject']}\" has no " . self::ATTACHMENT . '*.xlsx — left in the Inbox.');
                    continue;
                }

                $path = $this->downloadPath($mailbox, $received);
                $this->mail($prefix)->downloadAttachment($mailbox, $message['id'], $attachment['id'], $path);
                $this->info("  {$received} \"{$message['subject']}\" -> {$path}");

                $result = $this->processFile($path, $passes, "{$mailbox} message received {$received}");
                if ($result !== Command::SUCCESS) {
                    $failed = true;
                    $this->warn('  [WARN] processing failed; the message stays in the Inbox for the next run.');
                    continue;
                }
                $processedMessages++;

                if ($this->dryRun || $this->option('keep-in-inbox')) {
                    $this->line('  message left in the Inbox (' . ($this->dryRun ? '--dry-run' : '--keep-in-inbox') . ').');
                    continue;
                }
                // The rows are written by now; failing to archive must not abort the remaining
                // messages (18 Sep 2026: a 403 here — the LT app had Mail.Read, not ReadWrite —
                // stopped the run after the first of five files). Left in the Inbox, the message is
                // re-read next run and every row is skipped as already processed.
                try {
                    $this->mail($prefix)->markRead($mailbox, $message['id']);
                    $this->mail($prefix)->moveToInboxSubfolder($mailbox, $message['id'], self::ARCHIVE_FOLDER);
                    $this->line('  marked read and moved to Inbox\\' . self::ARCHIVE_FOLDER . '.');
                } catch (Throwable $e) {
                    $failed = true;
                    $this->error('  [ERROR] processed, but could not archive the message (it stays in the Inbox): ' . $e->getMessage());
                    Log::error('ProcessReferralCommissions: archive failed', ['mailbox' => $mailbox, 'message' => $message['id'], 'exception' => $e]);
                }
            }
        }

        $this->info("[INFO] {$processedMessages} message(s) processed.");

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    public static function isFundDetailsWorkbook(string $name): bool
    {
        $name = trim($name);

        return stripos($name, self::ATTACHMENT) === 0 && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xlsx';
    }

    protected function downloadPath(string $mailbox, string $received): string
    {
        $stamp = preg_replace('/[^0-9]/', '', $received) ?: date('YmdHis');
        $box = substr($mailbox, 0, (int) strpos($mailbox, '@'));

        return storage_path('app/referral-commissions/' . date('Y-m') . "/{$stamp}_{$box}_" . self::ATTACHMENT . '.xlsx');
    }

    // ─── A file, through each pass ────────────────────────────────────────

    /**
     * @param list<string> $passes
     */
    protected function processFile(string $path, array $passes, string $origin): int
    {
        if (!is_file($path)) {
            $this->error("File not found: {$path}");

            return Command::FAILURE;
        }

        $parsed = MonevoFundDetailsParser::parse($path);
        $rows = $parsed['rows'];

        $this->info(sprintf('[INFO] %s: %d funded row(s)%s.', $origin, count($rows), $parsed['processed_date_removed'] ? ' (column B "Processed Date" removed, as the VBA did)' : ''));
        $this->line('  columns read: ' . implode(' | ', array_map(
            static fn (int $index, string $label): string => $label . ' = "' . ($parsed['headers'][$index] ?? '') . '"',
            array_keys(MonevoFundDetailsParser::COLUMN_LABELS),
            MonevoFundDetailsParser::COLUMN_LABELS
        )));
        foreach ($parsed['skipped'] as $note) {
            $this->warn("  [WARN] {$note}");
        }
        if ($rows === []) {
            $this->warn('[WARN] No funded rows in the file; nothing to do.');

            return Command::SUCCESS;
        }

        $today = now()->format('Y-m-d');
        $failed = false;
        foreach ($passes as $pass) {
            if (!isset(self::PASSES[$pass])) {
                $this->error("Unknown pass {$pass}. Known: " . implode(', ', array_keys(self::PASSES)));

                return Command::FAILURE;
            }
            $this->newLine();
            $this->info('[INFO] Pass ' . self::PASSES[$pass]['label'] . ($this->dryRun ? ' (dry run)' : '') . '.');
            if (!$this->dryRun) {
                // Fail before the first row rather than after the first payroll insert.
                $this->dpp()->assertConfigured(self::PASSES[$pass]['tenant']);
            }

            $this->counts =['processed' => 0, 'already' => 0, 'not_in_contacts' => 0, 'invalid' => 0, 'errors' => 0];
            foreach ($rows as $row) {
                try {
                    $outcome = $this->processRow($pass, $row, $today);
                } catch (Throwable $e) {
                    $outcome = 'errors';
                    $this->error("  row {$row['row']} LLG-{$row['client_id']}: {$e->getMessage()}");
                    Log::error('ProcessReferralCommissions: row failed', ['pass' => $pass, 'row' => $row, 'exception' => $e]);
                }
                $this->counts[$outcome]++;
            }

            $this->info(sprintf(
                '[%s] %s: %d processed, %d already processed, %d not in TblContacts, %d invalid, %d error(s).',
                $this->counts['errors'] > 0 ? 'WARN' : 'SUCCESS',
                $pass,
                $this->counts['processed'],
                $this->counts['already'],
                $this->counts['not_in_contacts'],
                $this->counts['invalid'],
                $this->counts['errors']
            ));
            Log::info('ProcessReferralCommissions: pass finished.', ['pass' => $pass, 'origin' => $origin, 'dry_run' => $this->dryRun] + $this->counts);
            $failed = $failed || $this->counts['errors'] > 0;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    // ─── One row, one pass ────────────────────────────────────────────────

    /**
     * @param array{row: int, client_id: string, funding_date: ?string, commission: float, loan_amount: ?float, loan_amount_text: string, rate: ?float, rate_text: string, term: ?int, term_text: string, lender: string} $row
     * @return 'processed'|'already'|'not_in_contacts'|'invalid'
     */
    protected function processRow(string $pass, array $row, string $today): string
    {
        $id = $row['client_id'];
        $llg = 'LLG-' . $id;
        $tag = "  row {$row['row']} {$llg}";

        // 1. The contact must exist (VBA: rows without one are deleted before anything else).
        $contact = $pass === 'ccs' ? $this->ccsContact($id) : $this->ldrContact($llg);
        if ($contact === null) {
            $this->line("{$tag}: not in TblContacts — skipped.");

            return 'not_in_contacts';
        }

        if ($row['funding_date'] === null) {
            $this->warn("{$tag}: column A is not a date — skipped.");

            return 'invalid';
        }

        // 2. Agent PK by name (Val(): 0 when unknown) and the LDR enrollment status.
        $agentName = (string) ($contact['Agent'] ?? '');
        $agentPk = $pass === 'ccs' ? $this->ccsEmployeePk($agentName) : $this->ldrEmployeePk($agentName);
        $enrollmentStatus = $this->ldrEnrollmentStatus($llg);
        $payrollAgentId = $pass === 'ccs' ? 0 : $agentPk;

        // 3. Already processed?
        if ($this->alreadyProcessed($payrollAgentId, $llg)) {
            $this->line("{$tag}: already processed (TblPayrollAdjustments, Agent_ID {$payrollAgentId}) — skipped.");

            return 'already';
        }

        $client = trim((string) ($contact['Client'] ?? ''));
        $note = self::fundingNote($row);
        $loanFormatted = self::dollars($row['loan_amount']);
        $payrollDate = self::payrollDate($row['funding_date'], $today);
        $amount = $this->ldrEmployeeLocation($agentPk) === 'USA' ? self::USA_COMMISSION : 0.0;
        $payrollNotes = "Funded - {$llg} - {$client} - {$loanFormatted} - {$row['lender']}";
        $tenant = self::PASSES[$pass]['tenant'];

        $this->line(sprintf(
            '%s: %s / agent "%s" (PK %d, %s) / %s %s / payroll %s $%s%s',
            $tag,
            $client,
            $agentName,
            $agentPk,
            $agentPk === 0 ? 'not in TblEmployees' : ($amount > 0 ? 'USA' : 'non-USA'),
            $row['lender'],
            $loanFormatted,
            $payrollDate,
            number_format($amount, 2),
            $this->dryRun ? '  [DRY-RUN]' : ''
        ));

        // 4. Payroll adjustment.
        $this->ldrExecute(
            'INSERT INTO TblPayrollAdjustments (Agent_ID, Category, Payroll_Date, Amount, Notes) VALUES (?, ?, ?, ?, ?)',
            [$payrollAgentId, 'Commission', $payrollDate, $amount, $payrollNotes],
            "INSERT TblPayrollAdjustments Agent_ID={$payrollAgentId} Payroll_Date={$payrollDate} Amount={$amount}"
        );

        // 5. CRM status (unless enrolled) and the note. VBA `Like` is case-sensitive.
        if (!str_starts_with($enrollmentStatus, 'LDR Enrolled') && !str_starts_with($enrollmentStatus, 'PLAW Enrolled')) {
            $this->crmUpdate($tenant, $id, 'client_status', self::PASSES[$pass]['funded_status']);
        }
        // The workbook posts note line breaks as "%0A" (Global Const PostNewLine); LF here becomes exactly that.
        $this->crmUpdate($tenant, $id, 'notebody', str_replace("\r\n", "\n", $note));

        // 6. TblFundings. `Agent` is the employee PK as text — that is what the VBA stored.
        $this->ldrExecute(
            'INSERT INTO TblFundings (Client, Loan_Amount, Interest_Rate, APR, Term, Phone, Email, City, State, Agent, LLG_ID, Funding_Date, Source, Lender, Commission, Import_Date, Notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $client,
                $row['loan_amount'],
                $row['rate'],
                $row['rate'],
                $row['term'],
                self::numericOnly((string) ($contact['Phone'] ?? '')) ?: null,
                (string) ($contact['Email'] ?? ''),
                (string) ($contact['City'] ?? ''),
                (string) ($contact['State'] ?? ''),
                (string) $agentPk,
                $llg,
                $row['funding_date'],
                self::SOURCE,
                $row['lender'],
                $row['commission'],
                $today,
                $note,
            ],
            "INSERT TblFundings {$llg} {$loanFormatted} {$row['lender']} commission {$row['commission']}"
        );

        // 7. The loan fields on the CRM contact.
        $this->crmUpdate($tenant, $id, 'funded_by', $row['lender']);
        $this->crmUpdate($tenant, $id, 'loan_amount', $row['loan_amount_text']);
        $this->crmUpdate($tenant, $id, 'apr', $row['rate_text'] . '%');
        $this->crmUpdate($tenant, $id, 'interest_rate', $row['rate_text'] . '%');
        $this->crmUpdate($tenant, $id, 'loan_term', $row['term_text']);

        return 'processed';
    }

    // ─── The rules, as pure functions ─────────────────────────────────────

    /**
     * VBA: `DateSerial(Year(A), Month(A) + 1, 10)`, and the month after that when that 10th is
     * already before today (strictly: a payroll date equal to today stands).
     */
    public static function payrollDate(string $fundingDate, string $today): string
    {
        $tenthOfNextMonth = static fn (\DateTimeImmutable $d): \DateTimeImmutable => $d->modify('first day of next month')->modify('+9 days');

        $payroll = $tenthOfNextMonth(new \DateTimeImmutable($fundingDate));
        if ($payroll < new \DateTimeImmutable($today)) {
            $payroll = $tenthOfNextMonth($payroll);
        }

        return $payroll->format('Y-m-d');
    }

    /**
     * The note the VBA built in column AF and posted as `notebody` (and stored in TblFundings.Notes).
     *
     * @param array{loan_amount: ?float, rate_text: string, term_text: string, lender: string} $row
     */
    public static function fundingNote(array $row): string
    {
        return 'Personal Loan Funded By: ' . $row['lender'] . "\r\n"
            . 'Final Loan Amount: ' . self::dollars($row['loan_amount']) . "\r\n"
            . 'APR: ' . $row['rate_text'] . "%\r\n"
            . 'Interest Rate: ' . $row['rate_text'] . "%\r\n"
            . 'Loan Term: ' . $row['term_text'] . ' Months';
    }

    /** VBA `Format(x, "$#,##0")`. */
    public static function dollars(?float $amount): string
    {
        return '$' . number_format((float) $amount, 0, '.', ',');
    }

    public static function numericOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }

    // ─── Lookups ──────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    protected function ldrContact(string $llg): ?array
    {
        // TblContacts has carried duplicate LLG_IDs; the VBA took whichever row came back first.
        $rows = $this->ldrSelect(
            'SELECT TOP 1 Client, Agent, Status, LLG_ID, Email, City, State, Phone FROM TblContacts WHERE LLG_ID = ? ORDER BY PK',
            [$llg]
        );

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    protected function ccsContact(string $cid): ?array
    {
        $rows = $this->ccsSelect(
            'SELECT TOP 1 Client, Agent, Status, CID, Email, City, State, Phone FROM TblContacts WHERE CID = ?',
            [$cid]
        );

        return $rows[0] ?? null;
    }

    protected function ldrEmployeePk(string $employeeName): int
    {
        $rows = $this->ldrSelect('SELECT TOP 1 PK FROM TblEmployees WHERE Employee_Name = ? ORDER BY PK', [$employeeName]);

        return (int) ($rows[0]['PK'] ?? 0);
    }

    protected function ccsEmployeePk(string $employeeName): int
    {
        $rows = $this->ccsSelect('SELECT TOP 1 PK FROM TblEmployees WHERE Employee_Name = ? ORDER BY PK', [$employeeName]);

        return (int) ($rows[0]['PK'] ?? 0);
    }

    /** Always LDR's TblEmployees — also on the CCS pass, exactly as the VBA did it. */
    protected function ldrEmployeeLocation(int $pk): string
    {
        $rows = $this->ldrSelect('SELECT Location FROM TblEmployees WHERE PK = ?', [$pk]);

        return trim((string) ($rows[0]['Location'] ?? ''));
    }

    protected function ldrEnrollmentStatus(string $llg): string
    {
        $rows = $this->ldrSelect('SELECT TOP 1 Enrollment_Status FROM TblEnrollment WHERE LLG_ID = ?', [$llg]);

        return (string) ($rows[0]['Enrollment_Status'] ?? '');
    }

    protected function alreadyProcessed(int $agentId, string $llg): bool
    {
        $rows = $this->ldrSelect(
            "SELECT COUNT(*) AS n FROM TblPayrollAdjustments WHERE Agent_ID = ? AND Category = 'Commission' AND Notes LIKE ?",
            [$agentId, "%{$llg}%"]
        );

        return (int) ($rows[0]['n'] ?? 0) > 0;
    }

    // ─── I/O seams (overridden in tests) ──────────────────────────────────

    /** @return list<array<string, mixed>> */
    protected function ldrSelect(string $sql, array $params): array
    {
        $result = $this->ldr()->querySqlServer($sql, $params);
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('LDR query failed: ' . ($result['error'] ?? 'unknown error'));
        }

        return $result['data'] ?? [];
    }

    protected function ldrExecute(string $sql, array $params, string $describe): void
    {
        if ($this->dryRun) {
            $this->line("    [DRY-RUN] {$describe}");

            return;
        }
        $result = $this->ldr()->querySqlServer($sql, $params);
        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException("{$describe} failed: " . ($result['error'] ?? 'unknown error'));
        }
    }

    /** @return list<array<string, mixed>> */
    protected function ccsSelect(string $sql, array $params): array
    {
        $statement = $this->ccs()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    protected function crmUpdate(string $tenant, string $contactId, string $field, string $value): void
    {
        if ($this->dryRun) {
            $this->line("    [DRY-RUN] CRM {$tenant} {$contactId} {$field} = " . str_replace(["\r\n", "\n"], ' | ', $value));

            return;
        }
        if (!$this->dpp()->updateRecord($tenant, $contactId, $field, $value)) {
            throw new \RuntimeException("CRM update {$field} failed for {$tenant} contact {$contactId}");
        }
    }

    protected function ldr(): DBConnector
    {
        if ($this->ldr === null) {
            $this->ldr = DBConnector::fromEnvironment('ldr');
            $this->ldr->initializeSqlServer();
            $server = $this->ldr->querySqlServer('SELECT @@SERVERNAME AS s')['data'][0]['s'] ?? 'unknown';
            $this->info("[INFO] LDR SQL Server: {$server}.");
        }

        return $this->ldr;
    }

    /** The CCS database, opened the way SyncContactsCCS does (DBConnector only knows the CMD server). */
    protected function ccs(): PDO
    {
        if ($this->ccs === null) {
            $host = (string) env('CCS_DB_HOST', '');
            $port = (string) env('CCS_DB_PORT', '1433');
            $database = (string) env('CCS_DB_DATABASE', '');
            if ($host === '' || $database === '') {
                throw new \RuntimeException('CCS SQL Server credentials are not configured. Set CCS_DB_HOST and CCS_DB_DATABASE in .env.');
            }
            $this->ccs = new PDO(
                "sqlsrv:Server={$host},{$port};Database={$database};TrustServerCertificate=true",
                (string) env('CCS_DB_USERNAME', ''),
                (string) env('CCS_DB_PASSWORD', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $this->info("[INFO] CCS SQL Server: {$host}.");
        }

        return $this->ccs;
    }

    protected function dpp(): DppDataClient
    {
        return $this->dpp ??= DppDataClient::fromConfig();
    }

    protected function mail(string $prefix): GraphMailboxClient
    {
        return $this->mail[$prefix] ??= GraphMailboxClient::fromEnvironment($prefix);
    }

    /** @return list<string> */
    private function passesOption(): array
    {
        $passes = array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($this->option('passes') ?? 'lt,ccs'))))));

        return $passes === [] ? ['lt', 'ccs'] : $passes;
    }
}
