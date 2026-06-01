<?php

namespace Cmd\Reports\Console\Commands\GenerateConsumerAffairsFundedReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateConsumerAffairsFundedReport extends Command
{
    protected $signature = 'Generate:consumer-affairs-funded-report';

    protected $description = 'Generate Consumer Affairs funded report (SQL Server) and email it.';

    public function handle(): int
    {
        $this->info('[INFO] Consumer Affairs report: starting.');

        try {
            $connector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('GenerateConsumerAffairsFundedReport: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $reportDate = date('Y-m-d');
        $monthStart = date('Y-m-01', strtotime('first day of last month'));
        $monthEnd = date('Y-m-t', strtotime('last day of last month'));

        $sql = "
            SELECT
                f.PK,
                f.Phone AS [Phone],
                f.Email AS [Email],
                f.Client AS [Client],
                f.City,
                f.State,
                f.LLG_ID AS [Order_Number],
                f.Funding_Date AS [Date_of_Experience],
                f.Notes AS [Product_Info],
                c.Data_Source AS [Source],
                c.Agent AS [Loan_Representative]
            FROM TblFundings AS f
            LEFT JOIN TblContacts AS c ON f.LLG_ID = c.LLG_ID
            WHERE (f.Review_Date IS NULL OR CAST(f.Review_Date AS date) = '{$this->esc($reportDate)}')
              AND f.Email IN (SELECT Email FROM TblContacts)
              AND f.Funding_Date >= '2022-11-01'
              AND f.Notes NOT LIKE '%Loan Term:  Months'
              AND f.Client LIKE '% %'
              AND f.Funding_Date >= '{$this->esc($monthStart)}'
              AND f.Funding_Date <= '{$this->esc($monthEnd)}'
            ORDER BY f.Funding_Date DESC, UPPER(f.Client) ASC
        ";

        $result = $connector->querySqlServer($sql);
        if (!is_array($result) || (isset($result['success']) && $result['success'] === false)) {
            $this->error('Consumer Affairs report: query failed.');
            Log::error('GenerateConsumerAffairsFundedReport: query failed', ['result' => $result]);
            return Command::FAILURE;
        }

        $rows = $result['data'] ?? [];
        if (empty($rows)) {
            $this->warn('Consumer Affairs report: no rows found.');
        }

        $formatter = new Formatter();
        $report = $formatter->buildWorkbook($rows);

        if (!empty($report['pks'])) {
            $this->updateReviewDates($connector, $report['pks'], $reportDate);
        }

        $formatter->sendReport($connector, $report['path'], $report['filename'], $this);

        return Command::SUCCESS;
    }

    protected function updateReviewDates(DBConnector $connector, array $pks, string $reportDate): void
    {
        $pks = array_values(array_unique(array_filter($pks, function ($pk) {
            return is_int($pk) || ctype_digit((string) $pk);
        })));

        if (empty($pks)) {
            return;
        }

        $chunks = array_chunk($pks, 500);
        foreach ($chunks as $chunk) {
            $idList = implode(',', array_map('intval', $chunk));
            $sql = "
                UPDATE TblFundings
                SET Review_Date = '{$this->esc($reportDate)}'
                WHERE PK IN ({$idList})
            ";
            $connector->querySqlServer($sql);
        }
    }

    protected function initializeSqlServerConnector(): DBConnector
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

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
