<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateAdvanceRequest extends Command
{
    protected $signature = 'Generate:advance-request
                            {--month= : Reporting month as YYYY-MM (defaults to previous calendar month)}
                            {--dry-run : Calculate and display results without sending email}
                            {--send-live : Send to the production recipients instead of the test recipient}';

    protected $description = 'Calculate and email the monthly LDR and Progress Law advance request.';

    private const TEST_RECIPIENT = 'oduai@libertydebtrelief.com';
    private const LDR_SENDER = 'NGF@libertydebtrelief.com';
    private const PROGRESS_LAW_SENDER = 'NGF@progresslaw.com';
    private const ADVANCE_TOTAL = 1000000.0;

    public function handle(): int
    {
        $window = $this->resolveMonthWindow();
        $this->info("[INFO] Advance request period: {$window['label']} ({$window['start']} through {$window['end_exclusive']})");

        try {
            $azure = DBConnector::fromEnvironment('ldr');
            $azure->initializeSqlServer();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Azure SQL Server: ' . $e->getMessage());
            Log::error('GenerateAdvanceRequest: Azure initialization failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $azureRows = $this->fetchAzureEnrollments($azure, $window);
            $tranche = $this->fetchNextTranche($azure);

            $capturedIds = [];
            $amounts = ['LDR' => 0.0, 'Progress Law' => 0.0];
            foreach ($azureRows as $row) {
                $id = $this->normalizeLlgId($row['LLG_ID'] ?? '');
                if ($id !== '') {
                    $capturedIds[$id] = true;
                }
                $program = (($row['PROGRAM'] ?? '') === 'Progress Law') ? 'Progress Law' : 'LDR';
                $amounts[$program] += (float) ($row['DEBT'] ?? 0);
            }

            $this->info(sprintf('[INFO] Azure: LDR $%0.2f | Progress Law $%0.2f | captured enrollments %d',
                $amounts['LDR'], $amounts['Progress Law'], count($capturedIds)));

            foreach ([
                ['environment' => 'ldr', 'program' => 'LDR', 'statuses' => [377644, 377643]],
                ['environment' => 'plaw', 'program' => 'Progress Law', 'statuses' => [377681, 377685, 377680]],
            ] as $source) {
                $snowflake = DBConnector::fromEnvironment($source['environment']);
                $rows = $this->fetchSnowflakeEnrollments(
                    $snowflake,
                    $window,
                    $source['statuses'],
                    $source['program']
                );

                $additional = 0.0;
                foreach ($rows as $row) {
                    $id = $this->normalizeLlgId('LLG-' . ($row['CONTACT_ID'] ?? ''));
                    if ($id !== '' && isset($capturedIds[$id])) {
                        continue;
                    }
                    $additional += (float) ($row['TOTAL_DEBT'] ?? 0);
                }

                $amounts[$source['program']] += $additional;
                $this->info(sprintf('[INFO] Snowflake %s: additional $%0.2f from %d qualifying contacts.',
                    $source['program'], $additional, count($rows)));
            }
        } catch (\Throwable $e) {
            $this->error('Advance request calculation failed: ' . $e->getMessage());
            Log::error('GenerateAdvanceRequest: calculation failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $allocation = $this->calculateAllocation($amounts['LDR'], $amounts['Progress Law']);
        $this->info(sprintf('[INFO] Final debt: LDR $%0.2f | Progress Law $%0.2f', $amounts['LDR'], $amounts['Progress Law']));
        $this->info(sprintf('[INFO] Allocation: LDR $%0.2f | Progress Law $%0.2f | Tranche %s',
            $allocation['ldr'], $allocation['progress_law'], $tranche));

        if ((bool) $this->option('dry-run')) {
            $this->info('[DRY RUN] No email sent.');
            return Command::SUCCESS;
        }

        $isLive = (bool) $this->option('send-live');
        $email = new EmailSenderService();
        $monthLabel = $window['label'];
        $sent = true;

        foreach ([
            [
                'name' => 'LDR',
                'subject' => 'LDR Advance Request',
                'amount' => $allocation['ldr'],
                'to' => ['sam@libertydebtrelief.com', 'omar@libertydebtrelief.com', 'james@nexgenfi.com'],
                'cc' => ['Jacob@libertydebtrelief.com'],
                'sender' => self::LDR_SENDER,
            ],
            [
                'name' => 'Progress Law',
                'subject' => 'Progress Law Advance Request',
                'amount' => $allocation['progress_law'],
                'to' => ['aaron@progresslaw.com', 'eric@progresslaw.com', 'james@nexgenfi.com'],
                'cc' => ['jacob@progresslaw.com'],
                'sender' => self::PROGRESS_LAW_SENDER,
            ],
        ] as $request) {
            $subject = ($isLive ? '' : '[TEST] ') . $request['subject'];
            $body = $this->buildEmailBody($request['name'], $request['amount'], $tranche, $monthLabel);
            $to = $isLive ? $request['to'] : [self::TEST_RECIPIENT];
            $cc = $isLive ? $request['cc'] : [];
            $wasSent = $email->sendMailHtml($subject, $body, $to, $cc, [], [], $request['sender']);
            $sent = $sent && $wasSent;

            if ($wasSent) {
                $this->info(sprintf('[INFO] %s advance request sent to %s.', $request['name'], implode(', ', $to)));
            } else {
                $this->error("[ERROR] {$request['name']} advance request email failed.");
            }
        }

        return $sent ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveMonthWindow(): array
    {
        $month = $this->option('month');
        if ($month !== null && !preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            throw new \InvalidArgumentException("Invalid --month value '{$month}', expected YYYY-MM.");
        }

        $start = $month !== null
            ? \Carbon\Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        return [
            'label' => $start->format('F Y'),
            'start' => $start->format('Y-m-d'),
            'end_exclusive' => $start->copy()->addMonthNoOverflow()->format('Y-m-d'),
        ];
    }

    private function fetchAzureEnrollments(DBConnector $connector, array $window): array
    {
        $start = $this->esc($window['start']);
        $end = $this->esc($window['end_exclusive']);
        $result = $connector->querySqlServer("
            SELECT
                LLG_ID,
                CASE WHEN Enrollment_Plan LIKE '%Progress%' THEN 'Progress Law' ELSE 'LDR' END AS PROGRAM,
                SUM(CAST(Debt_Amount AS DECIMAL(19, 4))) AS DEBT
            FROM dbo.TblEnrollment
            WHERE Enrollment_Status IN ('LDR Enrolled', 'ProLaw Enrolled')
              AND Cancel_Date IS NULL
              AND NSF_Date IS NULL
              AND Submitted_Date >= '{$start}'
              AND Submitted_Date < '{$end}'
              AND LLG_ID IS NOT NULL
            GROUP BY
                LLG_ID,
                CASE WHEN Enrollment_Plan LIKE '%Progress%' THEN 'Progress Law' ELSE 'LDR' END
        ");

        $this->assertQuerySucceeded($result, 'Azure enrollment calculation');
        return $result['data'] ?? [];
    }

    private function fetchNextTranche(DBConnector $connector): string
    {
        $result = $connector->querySqlServer(
            'SELECT COALESCE(MAX(Tranche), 0) + 1 AS NEXT_TRANCHE FROM dbo.TblDebtTrancheSales'
        );
        $this->assertQuerySucceeded($result, 'next tranche calculation');
        return (string) ($result['data'][0]['NEXT_TRANCHE'] ?? $result['data'][0]['Next_Tranche'] ?? 1);
    }

    private function fetchSnowflakeEnrollments(
        DBConnector $connector,
        array $window,
        array $statuses,
        string $program
    ): array {
        $start = $this->esc($window['start']);
        $end = $this->esc($window['end_exclusive']);
        $statusList = implode(', ', array_map('intval', $statuses));
        $sql = "
            WITH qualifying_contacts AS (
                SELECT ID
                FROM CONTACTS
                WHERE CREATED >= '{$start}'
                  AND CREATED < '{$end}'
                  AND DROPPED = 0
            ), debt_totals AS (
                SELECT d.CONTACT_ID, SUM(d.ORIGINAL_DEBT_AMOUNT) AS TOTAL_DEBT
                FROM DEBTS AS d
                INNER JOIN qualifying_contacts AS c ON c.ID = d.CONTACT_ID
                WHERE d.ENROLLED = 1
                GROUP BY d.CONTACT_ID
            ), current_status AS (
                SELECT cs.CONTACT_ID, MAX_BY(cs.STATUS_ID, cs.STAMP) AS STATUS_ID
                FROM CONTACTS_STATUS AS cs
                INNER JOIN qualifying_contacts AS c ON c.ID = cs.CONTACT_ID
                WHERE cs.STATUS_ID > 0
                GROUP BY cs.CONTACT_ID
            )
            SELECT c.ID AS CONTACT_ID, d.TOTAL_DEBT, '{$program}' AS PROGRAM
            FROM qualifying_contacts AS c
            INNER JOIN debt_totals AS d ON c.ID = d.CONTACT_ID
            INNER JOIN current_status AS cs ON c.ID = cs.CONTACT_ID
            WHERE cs.STATUS_ID IN ({$statusList})
        ";

        $result = $connector->query($sql);
        return $result['data'] ?? [];
    }

    private function calculateAllocation(float $ldrDebt, float $progressLawDebt): array
    {
        $total = $ldrDebt + $progressLawDebt;
        if ($total <= 0) {
            return ['ldr' => 0.0, 'progress_law' => self::ADVANCE_TOTAL];
        }

        $ldr = ceil(($ldrDebt / $total * self::ADVANCE_TOTAL) / 1000) * 1000;
        $ldr = min(self::ADVANCE_TOTAL, $ldr);

        return ['ldr' => $ldr, 'progress_law' => self::ADVANCE_TOTAL - $ldr];
    }

    private function buildEmailBody(string $program, float $amount, string $tranche, string $month): string
    {
        $formattedAmount = '$' . number_format($amount, 2);

        return '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.6; color: #222;">'
            . '<p style="margin: 0 0 18px 0;">James,</p>'
            . '<p style="margin: 0 0 18px 0;">'
            . $program . ' is requesting an advance payment of ' . $formattedAmount
            . ' toward the sale of Tranche ' . $tranche . ' for ' . $month
            . ' enrollments, which will be processed later this month.'
            . '</p>'
            . '<p style="margin: 0 0 18px 0;">'
            . 'Please process this request and wire the funds at your earliest convenience.'
            . '</p>'
            . '<p style="margin: 0;">Thanks</p>'
            . '</div>';
    }

    private function normalizeLlgId(mixed $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '' : (str_starts_with(strtoupper($value), 'LLG-') ? strtoupper($value) : 'LLG-' . $value);
    }

    private function assertQuerySucceeded(array $result, string $operation): void
    {
        if (($result['success'] ?? true) === false) {
            throw new \RuntimeException("{$operation} failed: " . ($result['error'] ?? 'unknown database error'));
        }
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
