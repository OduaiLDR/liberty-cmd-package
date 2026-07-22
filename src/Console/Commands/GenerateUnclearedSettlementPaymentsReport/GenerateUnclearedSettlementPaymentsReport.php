<?php

namespace Cmd\Reports\Console\Commands\GenerateUnclearedSettlementPaymentsReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Port of VBA GenerateUnclearedSettlementPaymentsReport for LDR + PLAW.
 * Same SQL/report for both portals; one workbook + email per portal.
 */
class GenerateUnclearedSettlementPaymentsReport extends Command
{
    protected $signature = 'Generate:uncleared-settlement-payments-report
        {--no-email : Build workbooks only, skip email}';

    protected $description = 'Generate LDR + PLAW Uncleared Settlement Payments reports and email each separately.';

    /** @var list<array{env:string, source:string, company:string}> */
    private const PORTALS = [
        ['env' => 'ldr', 'source' => 'LDR', 'company' => 'LDR'],
        ['env' => 'plaw', 'source' => 'PLAW', 'company' => 'PLAW'],
    ];

    public function handle(): int
    {
        $this->info('[INFO] Uncleared Settlement Payments report (LDR + PLAW): starting.');

        $sqlConnector = null;
        if (! $this->option('no-email')) {
            try {
                $sqlConnector = $this->initializeSqlServerConnector();
            } catch (\Throwable $e) {
                $this->error('Failed to initialize SQL Server: '.$e->getMessage());
                Log::error('GenerateUnclearedSettlementPaymentsReport: sql init failed', ['exception' => $e]);

                return Command::FAILURE;
            }
        } else {
            $this->warn('[WARN] --no-email set; workbooks kept under storage/app.');
        }

        $formatter = new Formatter;
        $window = $this->resolveDateWindow();
        $this->info(sprintf(
            '[INFO] Process date window: %s through %s (today-21 .. today-7)',
            $window['start'],
            $window['end']
        ));

        $failed = 0;
        foreach (self::PORTALS as $portal) {
            try {
                $this->generateForPortal($portal, $formatter, $window, $sqlConnector);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$portal['source']} failed: ".$e->getMessage());
                Log::error('GenerateUnclearedSettlementPaymentsReport: portal failed', [
                    'portal' => $portal['source'],
                    'exception' => $e,
                ]);
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{env:string, source:string, company:string}  $portal
     * @param  array{start:string, end:string}  $window
     */
    private function generateForPortal(
        array $portal,
        Formatter $formatter,
        array $window,
        ?DBConnector $sqlConnector
    ): void {
        $source = $portal['source'];
        $this->info("[INFO] === {$source} ===");

        $snowflake = DBConnector::fromEnvironment($portal['env']);
        $rows = $this->fetchUnclearedSettlements($snowflake, $window['start'], $window['end']);
        $this->info("[INFO] {$source} uncleared settlement payments: ".count($rows));

        $result = $formatter->buildWorkbook($rows, $source);
        $path = $result['path'];
        $this->info("[INFO] {$source} workbook: {$path}");

        if ($sqlConnector === null) {
            return;
        }

        $sent = $formatter->sendReport(
            $sqlConnector,
            $result['path'],
            $result['filename'],
            $source,
            $portal['company'],
            $this
        );

        if (! $sent) {
            throw new \RuntimeException("{$source} email failed. Workbook kept at: {$path}");
        }

        if (is_file($path) && ! unlink($path)) {
            Log::warning('GenerateUnclearedSettlementPaymentsReport: sent workbook could not be deleted.', [
                'path' => $path,
                'portal' => $source,
            ]);
            $this->warn("[WARN] {$source} workbook was sent but could not be deleted: {$path}");
        }

        $this->info("[INFO] {$source} email sent.");
    }

    /**
     * VBA: StartDate = Date - 21, EndDate = Date - 7 (inclusive).
     *
     * @return array{start:string, end:string}
     */
    private function resolveDateWindow(): array
    {
        $today = Carbon::today();

        return [
            'start' => $today->copy()->subDays(21)->toDateString(),
            'end' => $today->copy()->subDays(7)->toDateString(),
        ];
    }

    /**
     * @return list<array{
     *   contact_id:string,
     *   process_date:string,
     *   amount:float,
     *   creditor:string
     * }>
     */
    private function fetchUnclearedSettlements(
        DBConnector $snowflake,
        string $startDate,
        string $endDate
    ): array {
        $start = $this->esc($startDate);
        $endExclusive = $this->esc(Carbon::parse($endDate)->addDay()->toDateString());

        // VBA:
        // SELECT CONTACT_ID, PROCESS_DATE, AMOUNT, MEMO
        // FROM TRANSACTIONS
        // WHERE TRANS_TYPE = 'S'
        //   AND PROCESS_DATE >= StartDate AND PROCESS_DATE <= EndDate
        //   AND CLEARED_DATE IS NULL
        //   AND RETURNED_DATE IS NULL
        //   AND CANCELLED = 0
        // ORDER BY PROCESS_DATE ASC
        $sql = "
SELECT
    t.CONTACT_ID,
    TO_VARCHAR(t.PROCESS_DATE::date, 'YYYY-MM-DD') AS PROCESS_DATE,
    t.AMOUNT,
    t.MEMO
FROM TRANSACTIONS t
WHERE t.TRANS_TYPE = 'S'
  AND t.PROCESS_DATE >= '{$start}'
  AND t.PROCESS_DATE < '{$endExclusive}'
  AND t.CLEARED_DATE IS NULL
  AND t.RETURNED_DATE IS NULL
  AND t.CANCELLED = 0
ORDER BY t.PROCESS_DATE ASC
";

        $out = [];
        $result = $snowflake->query($sql);
        $data = $result['data'] ?? null;
        if (! is_array($data)) {
            throw new \UnexpectedValueException('Snowflake returned an invalid report result.');
        }

        foreach ($data as $row) {
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            $processDate = (string) ($row['PROCESS_DATE'] ?? '');
            if ($cid === '' || $processDate === '') {
                continue;
            }

            $out[] = [
                'contact_id' => $cid,
                'process_date' => $processDate,
                'amount' => (float) ($row['AMOUNT'] ?? 0),
                'creditor' => (string) ($row['MEMO'] ?? ''),
            ];
        }

        return $out;
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

        throw new \RuntimeException('Unable to initialize SQL Server connector. Tried: '.implode('; ', $errors));
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
