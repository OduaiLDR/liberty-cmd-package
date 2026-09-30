<?php

namespace Cmd\Reports\Console\Commands\GenerateParamountEpfSummary;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\EmailSenderService;
use Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices\InvoicePdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GenerateParamountEpfSummary extends Command
{
    protected $signature = 'Generate:paramount-epf-summary
                            {--month= : Month to report as YYYY-MM (defaults to last full calendar month)}
                            {--output= : Copy the workbook to this path}
                            {--invoice : Also generate an LDR invoice PDF beside the workbook}
                            {--send-to-me : Send test copies only to oduai@libertydebtrelief.com, with no CC or BCC}
                            {--test-to= : Send only to this review email address, with no CC or BCC}
                            {--jacob-only : Send only to Jacob Wuydts}
                            {--send : Send the report email to the configured Paramount recipients}';

    protected $description = 'Generate the monthly Paramount Law EPF Summary workbook.';

    private const REPORT_NAME = 'LT Invoices ParamountLaw';

    public function handle(): int
    {
        $lock = null;
        try {
            $window = $this->resolveMonthWindow();
            $testTo = trim((string) $this->option('test-to'));
            if ($this->option('send-to-me')) {
                if ($testTo !== '' && strcasecmp($testTo, 'oduai@libertydebtrelief.com') !== 0) {
                    throw new \InvalidArgumentException('--send-to-me cannot be combined with a different --test-to recipient.');
                }
                $testTo = 'oduai@libertydebtrelief.com';
            }
            if ($testTo !== '' && !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('--test-to must be a valid email address.');
            }
            if ($this->option('send') && ($testTo !== '' || $this->option('jacob-only'))) {
                throw new \InvalidArgumentException('Choose either a review recipient or --send, not both.');
            }
            if ($testTo !== '' && $this->option('jacob-only')) {
                throw new \InvalidArgumentException('Choose one review recipient option.');
            }
            $invoiceMode = (bool) $this->option('invoice');
            $billTo = [
                'name' => (string) env('PARAMOUNT_INVOICE_BILL_TO_NAME', 'Paramount Law'),
                'lines' => array_values(array_filter(array_map('trim', explode('|', (string) env('PARAMOUNT_INVOICE_BILL_TO_ADDRESS', ParamountInvoice::BILL_TO_ADDRESS))))),
            ];
            $marker = storage_path('app/private/cmd/paramount-invoices/' . substr($window['start'], 0, 7) . '.sent.json');
            if ($invoiceMode && $this->option('send')) {
                $lock = Cache::lock('paramount-invoice-' . substr($window['start'], 0, 7), 1800);
                if (!$lock->get()) {
                    throw new \RuntimeException('Another Paramount invoice send is in progress.');
                }
                if ($billTo['lines'] === []) {
                    throw new \RuntimeException('Confirm PARAMOUNT_INVOICE_BILL_TO_ADDRESS before sending a live invoice. Local drafts and private reviews are available.');
                }
                if ($window['endExclusive'] > now('America/Los_Angeles')->format('Y-m-d')) {
                    throw new \RuntimeException('Cannot send a live invoice before the billing month ends.');
                }
                if (is_file($marker)) {
                    throw new \RuntimeException('This Paramount invoice has already been sent; review the existing send record.');
                }
                if (!is_dir(dirname($marker)) && !mkdir(dirname($marker), 0775, true) && !is_dir(dirname($marker))) {
                    throw new \RuntimeException('Could not create the invoice send-record directory.');
                }
                if (!is_writable(dirname($marker))) {
                    throw new \RuntimeException('Invoice send-record directory is not writable.');
                }
            }
            $snowflake = DBConnector::fromEnvironment('ldr');
            $rows = $this->fetchRows($snowflake, $window);
            $debtRows = $this->debtTable();
            $clientRows = $this->buildClientRows($rows);
            $totalDebt = round(array_sum(array_column($clientRows, 'tier_debt')), 2);
            [$tier, $payment] = $this->findTier($totalDebt, $debtRows);

            $report = (new Formatter())->buildWorkbook(
                $rows,
                $clientRows,
                $debtRows,
                $totalDebt,
                $tier,
                $payment,
                $window['label']
            );
            $output = $this->copyOutput($report['path']);
            $invoicePath = null;
            if ($invoiceMode) {
                $document = ParamountInvoice::document($window, $totalDebt, $tier, $payment, $billTo);
                $invoicePath = dirname($output ?? $report['path']) . '/' . $document['number'] . '.pdf';
                $pdf = (new InvoicePdf(sys_get_temp_dir() . '/paramount-invoice-pdf'))->render($document);
                if (file_put_contents($invoicePath, $pdf) === false) {
                    throw new \RuntimeException('Could not save the Paramount invoice PDF.');
                }
                $this->info('[INFO] Invoice written to: ' . $invoicePath);
            }
            $this->info('[INFO] Workbook written to: ' . ($output ?? $report['path']));
            $this->info(sprintf('[INFO] EPF debt: $%s; tier: T%d; payment: $%s', number_format($totalDebt, 2), $tier, number_format($payment, 2)));

            if ($this->option('send') || $this->option('jacob-only') || $testTo !== '') {
                $this->sendReport(
                    $report,
                    $window['label'],
                    $totalDebt,
                    $tier,
                    $payment,
                    (bool) $this->option('jacob-only'),
                    $testTo,
                    $invoicePath
                );
                if ($invoiceMode && $this->option('send')) {
                    if (!is_dir(dirname($marker))) { mkdir(dirname($marker), 0775, true); }
                    if (file_put_contents($marker, json_encode(['sent_at' => now()->toIso8601String(),
                        'invoice' => basename($invoicePath), 'payment' => $payment])) === false) {
                        throw new \RuntimeException('Email sent, but send record could not be saved. Do not retry without checking delivery.');
                    }
                }
            } else {
                $this->info('[INFO] Email not sent. Pass --send to send it.');
            }

            if ($output !== null && $output !== $report['path']) {
                @unlink($report['path']);
            }
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Paramount Law EPF Summary failed: ' . $e->getMessage());
            Log::error('GenerateParamountEpfSummary: failed', ['exception' => $e]);
            return Command::FAILURE;
        } finally {
            $lock?->release();
        }
    }

    /** @return array{label:string,start:string,endExclusive:string} */
    private function resolveMonthWindow(): array
    {
        $month = (string) ($this->option('month') ?: now('America/Los_Angeles')->subMonthNoOverflow()->format('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \InvalidArgumentException("Invalid --month value '{$month}', expected YYYY-MM.");
        }
        $start = \Carbon\Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
        return [
            'label' => $start->format('F Y'),
            'start' => $start->format('Y-m-d'),
            'endExclusive' => $start->copy()->addMonthNoOverflow()->format('Y-m-d'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchRows(DBConnector $connector, array $window): array
    {
        $start = $this->esc($window['start']);
        $end = $this->esc($window['endExclusive']);
        $sql = "
            WITH source_rows AS (
                SELECT
                    t.CONTACT_ID,
                    ed.TITLE AS PLAN,
                    t.CLEARED_DATE,
                    s.ORIGINAL_AMOUNT AS DEBT_AMOUNT,
                    LEFT(SUBSTRING(ep.FEE1, CHARINDEX('percent', ep.FEE1) + 8, 28
                        - CHARINDEX('percent', ep.FEE1) + 8), CHARINDEX(',', ep.FEE1,
                        CHARINDEX(',', ep.FEE1, CHARINDEX('percent', ep.FEE1) + 8))
                        - CHARINDEX(',', ep.FEE1, CHARINDEX('percent', ep.FEE1)) - 1) / 100 AS EPF_RATE,
                    t.AMOUNT AS EPF,
                    d.ID AS DEBT_ID,
                    s.CREDITOR_NAME,
                    t.PROCESS_DATE,
                    t.DRAFT_DATE
                FROM TRANSACTIONS AS t
                LEFT JOIN ENROLLMENT_PLAN AS ep ON t.CONTACT_ID = ep.CONTACT_ID
                LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
                LEFT JOIN SETTLEMENTS AS s ON t.LINKED_TO = s.TRANS_ID
                LEFT JOIN SETTLEMENT_OFFERS AS o ON s.OFFER_ID = o.ID
                LEFT JOIN DEBTS AS d ON o.DEBT_ID = d.ID
                WHERE t.TRANS_TYPE = 'PF'
                  AND t.LINKED_TO <> 0
                  AND t.CANCELLED = 0
                  AND t.RETURNED_DATE IS NULL
                  AND s.ORIGINAL_AMOUNT > 0
                  AND t.CLEARED_DATE >= '{$start}'
                  AND t.CLEARED_DATE < '{$end}'
                  AND UPPER(ed.TITLE) LIKE 'PLAW%'
            )
            SELECT
                CONTACT_ID, PLAN, CLEARED_DATE, DEBT_AMOUNT, EPF_RATE, EPF,
                DEBT_AMOUNT * EPF_RATE AS EPF_FEE_ALLOWED,
                EPF / (DEBT_AMOUNT * EPF_RATE) AS EPF_FEE_PERCENT_COLLECTED,
                ROUND(DEBT_AMOUNT * (EPF / (DEBT_AMOUNT * EPF_RATE)), 2) AS EPF_TIER_DEBT,
                DEBT_ID, CREDITOR_NAME, PROCESS_DATE, DRAFT_DATE
            FROM source_rows
            ORDER BY PROCESS_DATE ASC
        ";
        $data = $connector->query($sql)['data'] ?? null;
        if (!is_array($data)) {
            throw new \RuntimeException('Snowflake returned no EPF result set.');
        }
        return $data;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function buildClientRows(array $rows): array
    {
        $totals = [];
        $order = [];
        foreach ($rows as $row) {
            $id = (string) ($row['CONTACT_ID'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!isset($totals[$id])) {
                $order[] = $id;
                $totals[$id] = 0.0;
            }
            $totals[$id] += (float) ($row['EPF_TIER_DEBT'] ?? 0);
        }
        return array_map(
            fn (string $id): array => ['contact_id' => $id, 'tier_debt' => round($totals[$id], 2)],
            $order
        );
    }

    /** @param array<int,array{tier:int,low:float,high:float,amount:float}> $rows */
    private function findTier(float $debt, array $rows): array
    {
        $tier = 0;
        $payment = 0.0;
        foreach ($rows as $row) {
            if ($debt >= $row['low']) {
                $tier = $row['tier'];
                $payment = $row['amount'];
            } else {
                break;
            }
        }
        return [$tier, $payment];
    }

    /** @return array<int,array{tier:int,low:float,high:float,amount:float}> */
    private function debtTable(): array
    {
        $highs = [10000, 20000, 30000, 45000, 65000, 85000, 110000, 160000, 210000];
        for ($high = 260000; $high <= 2660000; $high += 50000) {
            $highs[] = $high;
        }
        $amounts = [1190, 3570, 5950, 8925, 13090, 17850, 23205, 32130, 44030];
        for ($amount = 55930; count($amounts) < 58; $amount += 11900) {
            $amounts[] = $amount;
        }
        $rows = [];
        $low = 0.01;
        foreach ($highs as $index => $high) {
            $rows[] = [
                'tier' => $index + 1,
                'low' => $low,
                'high' => (float) $high,
                'amount' => (float) $amounts[$index],
            ];
            $low = $high + 0.01;
        }
        return $rows;
    }

    private function copyOutput(string $source): ?string
    {
        $output = $this->option('output');
        if ($output === null || $output === '') {
            return null;
        }
        if (!copy($source, $output)) {
            throw new \RuntimeException("Unable to copy workbook to '{$output}'.");
        }
        return $output;
    }

    /** @param array{filename:string,path:string} $report */
    private function sendReport(
        array $report,
        string $monthLabel,
        float $debt,
        int $tier,
        float $payment,
        bool $jacobOnly = false,
        string $testTo = '',
        ?string $invoicePath = null
    ): void
    {
        $body = '<p>Hi,</p>'
            . '<p>Here is the EPF Summary for ' . htmlspecialchars($monthLabel, ENT_QUOTES, 'UTF-8') . '.</p>'
            . sprintf('<p>The prorated EPF debt is <strong>$%s</strong>, which places us at <strong>T%d</strong>, with a payment due of <strong>$%s</strong>.</p>', number_format($debt, 2), $tier, number_format($payment, 2))
            . '<p>If we are in agreement on the tier, please make the payment at your earliest convenience.</p>'
            . '<p>If there are any discrepancies you would like to review, please let me know.</p>'
            . '<p>Thank you,</p>';
        $attachments = [[
            'name' => $report['filename'],
            'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'contentBytes' => base64_encode(file_get_contents($report['path'])),
        ]];
        if ($invoicePath !== null) {
            $attachments[] = ['name' => basename($invoicePath), 'contentType' => 'application/pdf',
                'contentBytes' => base64_encode(file_get_contents($invoicePath))];
        }
        $review = $testTo !== '' || $jacobOnly;
        $to = $testTo !== '' ? [$testTo] : ($jacobOnly ? ['jacob@libertydebtrelief.com'] : ['emcmurtrey@Higbee.law']);
        $subject = ($review ? '[TEST] ' : '') . ($invoicePath !== null ? 'LDR Invoice to Paramount Law' : 'Paramount Law EPF Summary') . " - {$monthLabel}";
        $mailer = new EmailSenderService();
        if ($review) {
            $sent = $mailer->sendMailHtml($subject, $body, $to, [], [], $attachments, ParamountInvoice::SENDER);
        } else {
            $azure = DBConnector::fromEnvironment('ldr');
            $azure->initializeSqlServer();
            $sent = $mailer->sendMailUsingTblReportsHtml(
                $azure, [self::REPORT_NAME], ['LDR'], $subject, $body, $attachments,
                false, true, ParamountInvoice::SENDER
            );
        }
        if (!$sent) {
            throw new \RuntimeException('Email send failed.');
        }
        $this->info($review
            ? '[INFO] Paramount review email sent only to ' . implode(', ', $to) . '.'
            : '[INFO] Paramount Law EPF Summary email sent.');
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
