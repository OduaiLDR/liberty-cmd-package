<?php

namespace Cmd\Reports\Console\Commands\GenerateConsumerAffairsFundedReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Consumer Affairs Funded Report (Lending Tower).
 *
 * Fixed 2026-08-04 per Jacob: the report was only ever reading
 * TblFundings.Phone, a SQL Server field that's frequently blank. The real
 * phone data lives in Snowflake's 'lt' CONTACTS table across 4 fields
 * (PHONE, PHONE2, PHONE3, PHONE4) -- confirmed against production that
 * 10/10 sampled blank-Phone rows from last month had a real number sitting
 * in Snowflake PHONE. Now looks up all 4 fields there and falls back
 * through them in order, using TblFundings.Phone first if it happens to be
 * populated.
 */
class GenerateConsumerAffairsFundedReport extends Command
{
    protected $signature = 'Generate:consumer-affairs-funded-report
                            {--test : Send only to jacob@libertydebtrelief.com, and skip the Review_Date gate/write-back so a test run never touches production review tracking}';

    protected $description = 'Generate Consumer Affairs funded report (SQL Server + Snowflake) and email it.';

    private const TEST_RECIPIENT = 'jacob@libertydebtrelief.com';

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

        $isTest = (bool) $this->option('test');
        $reportDate = date('Y-m-d');
        $monthStart = date('Y-m-01', strtotime('first day of last month'));
        $monthEnd = date('Y-m-t', strtotime('last day of last month'));

        // Test runs skip the Review_Date gate entirely so testing never
        // depends on (or disturbs) which rows production has already
        // marked reviewed.
        $reviewFilter = $isTest
            ? ''
            : "AND (f.Review_Date IS NULL OR CAST(f.Review_Date AS date) = '{$this->esc($reportDate)}')";

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
            WHERE 1=1
              {$reviewFilter}
              AND f.Email IN (SELECT Email FROM TblContacts)
              AND f.Funding_Date >= '2022-11-01'
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

        try {
            $rows = $this->attachSnowflakePhones($rows);
        } catch (\Throwable $e) {
            // Non-fatal: worst case the report reverts to TblFundings.Phone
            // only, same as before this fix -- better than failing the
            // whole report over a phone lookup.
            $this->warn('[WARN] Snowflake phone lookup failed, falling back to TblFundings.Phone only: ' . $e->getMessage());
            Log::warning('GenerateConsumerAffairsFundedReport: Snowflake phone lookup failed.', ['exception' => $e]);
        }

        $formatter = new Formatter();
        $report = $formatter->buildWorkbook($rows);

        if (!$isTest && !empty($report['pks'])) {
            $this->updateReviewDates($connector, $report['pks'], $reportDate);
        }

        $recipients = $isTest ? [self::TEST_RECIPIENT] : null;
        $formatter->sendReport($connector, $report['path'], $report['filename'], $recipients, $this);

        return Command::SUCCESS;
    }

    /**
     * Look up PHONE/PHONE2/PHONE3/PHONE4 in Snowflake 'lt' CONTACTS for
     * every row, keyed by the numeric ID inside LLG_ID (Order_Number), and
     * attach them to each row so the Formatter can fall back through all 4
     * fields the same way GenerateConsumerAffairsSettlementReport already
     * does for LDR/PLAW.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function attachSnowflakePhones(array $rows): array
    {
        $idToRows = [];
        foreach ($rows as $i => $row) {
            $llgId = (string) ($row['Order_Number'] ?? '');
            $id = preg_replace('/\D+/', '', $llgId);
            if ($id !== '') {
                $idToRows[$id][] = $i;
            }
        }

        if (empty($idToRows)) {
            return $rows;
        }

        $snowflake = DBConnector::fromEnvironment('lt');

        foreach (array_chunk(array_keys($idToRows), 1000) as $chunk) {
            $idList = implode(',', array_map('intval', $chunk));
            $sql = "SELECT ID, PHONE, PHONE2, PHONE3, PHONE4 FROM CONTACTS WHERE ID IN ({$idList})";

            $result = $snowflake->query($sql);
            $sfRows = $result['data'] ?? (is_array($result) && array_is_list($result) ? $result : []);

            foreach ($sfRows as $sfRow) {
                $sfId = (string) ($sfRow['ID'] ?? '');
                if ($sfId === '' || !isset($idToRows[$sfId])) {
                    continue;
                }
                foreach ($idToRows[$sfId] as $rowIndex) {
                    $rows[$rowIndex]['Phone2'] = $sfRow['PHONE2'] ?? null;
                    $rows[$rowIndex]['Phone3'] = $sfRow['PHONE3'] ?? null;
                    $rows[$rowIndex]['Phone4'] = $sfRow['PHONE4'] ?? null;
                    // Only use Snowflake's PHONE as a fallback -- TblFundings.Phone,
                    // when present, is left untouched as the first choice.
                    if (trim((string) ($rows[$rowIndex]['Phone'] ?? '')) === '') {
                        $rows[$rowIndex]['Phone'] = $sfRow['PHONE'] ?? '';
                    }
                }
            }
        }

        return $rows;
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
