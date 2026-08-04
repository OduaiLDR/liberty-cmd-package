<?php

namespace Cmd\Reports\Console\Commands\GenerateNSFReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Port of VBA GenerateNSFReport for LDR + PLAW.
 * Same SQL/report for both portals; one workbook + email per portal.
 *
 * Sources:
 * - docs/NSFLDR.md
 * - docs/NSFPLAW.md
 */
class GenerateNSFReport extends Command
{
    private const REPORT_TIMEZONE = 'America/Los_Angeles';

    protected $signature = 'Generate:nsf-report
        {--no-email : Build workbooks only, skip email}';

    protected $description = 'Generate LDR + PLAW NSF reports and email each separately.';

    /** @var list<array{env:string, source:string, company:string}> */
    private const PORTALS = [
        ['env' => 'ldr', 'source' => 'LDR', 'company' => 'LDR'],
        ['env' => 'plaw', 'source' => 'PLAW', 'company' => 'PLAW'],
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $this->info('[INFO] NSF report (LDR + PLAW): starting.');

        $reportDate = Carbon::today(self::REPORT_TIMEZONE)->toDateString();
        $this->info("[INFO] Report date: {$reportDate}");

        $sqlConnector = null;
        if (! $this->option('no-email')) {
            try {
                $sqlConnector = $this->initializeSqlServerConnector();
            } catch (\Throwable $e) {
                $this->error('Failed to initialize SQL Server: '.$e->getMessage());
                Log::error('GenerateNSFReport: sql init failed', ['exception' => $e]);

                return Command::FAILURE;
            }
        } else {
            $this->warn('[WARN] --no-email set; workbooks kept under storage/app.');
        }

        $formatter = new Formatter;
        $failed = 0;

        foreach (self::PORTALS as $portal) {
            try {
                $this->generateForPortal($portal, $formatter, $sqlConnector, $reportDate);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$portal['source']} failed: ".$e->getMessage());
                Log::error('GenerateNSFReport: portal failed', [
                    'portal' => $portal['source'],
                    'exception' => $e,
                ]);
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{env:string, source:string, company:string}  $portal
     */
    private function generateForPortal(
        array $portal,
        Formatter $formatter,
        ?DBConnector $sqlConnector,
        string $reportDate
    ): void {
        $source = $portal['source'];
        $this->info("[INFO] === {$source} ===");

        $snowflake = DBConnector::fromEnvironment($portal['env']);
        $rows = $this->fetchNsfRows($snowflake, $reportDate);
        $this->info("[INFO] {$source} NSF rows: ".count($rows));

        if ($rows === []) {
            $this->info("[INFO] {$source}: no NSF data; workbook and email skipped.");

            return;
        }

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
            Log::warning('GenerateNSFReport: sent workbook could not be deleted.', [
                'path' => $path,
                'portal' => $source,
            ]);
            $this->warn("[WARN] {$source} workbook was sent but could not be deleted: {$path}");
        }

        $this->info("[INFO] {$source} email sent.");
    }

    /**
     * @return list<array{
     *   ID:string|int|null,
     *   CONTACT:string|null,
     *   ENROLLED_DATE:string|null,
     *   ENROLLED_DEBT:float|int|string|null,
     *   STATUS:string|null,
     *   STATUS_DATE:string|null,
     *   DAYS:int|string|null,
     *   PHONE_1:string|null,
     *   PHONE_2:string|null,
     *   PHONE_3:string|null,
     *   PHONE_4:string|null
     * }>
     */
    private function fetchNsfRows(DBConnector $snowflake, string $reportDate): array
    {
        // VBA:
        // latest status per contact (N=1), only contacts that ever had a status title LIKE '%NSF%'
        $sql = <<<SQL
WITH NSF_CONTACTS AS (
    SELECT DISTINCT cs.CONTACT_ID
    FROM CONTACTS_STATUS AS cs
    INNER JOIN CONTACTS_LEAD_STATUS AS cls ON cls.ID = cs.STATUS_ID
    WHERE cls.TITLE LIKE '%NSF%'
),
ELIGIBLE_CONTACTS AS (
    SELECT
        c.ID,
        c.FIRSTNAME,
        c.LASTNAME,
        c.ENROLLED_DATE,
        c.PHONE,
        c.PHONE2,
        c.PHONE3,
        c.PHONE4
    FROM CONTACTS AS c
    INNER JOIN NSF_CONTACTS AS nc ON nc.CONTACT_ID = c.ID
    WHERE c.DEL = 0
      AND c.ENROLLED = 1
      AND COALESCE(c.FIRSTNAME, '') <> ''
),
ENROLLED_DEBT AS (
    SELECT d.CONTACT_ID, SUM(d.ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
    FROM DEBTS AS d
    INNER JOIN ELIGIBLE_CONTACTS AS ec ON ec.ID = d.CONTACT_ID
    WHERE d.ENROLLED = 1
      AND d._FIVETRAN_DELETED = FALSE
    GROUP BY d.CONTACT_ID
),
LATEST_STATUS AS (
    SELECT
        ec.ID,
        CONCAT(ec.FIRSTNAME, ' ', ec.LASTNAME) AS CONTACT,
        TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', ec.ENROLLED_DATE) AS DATE), 'YYYY-MM-DD') AS ENROLLED_DATE,
        ed.ENROLLED_DEBT,
        cls.TITLE AS STATUS,
        TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', s.STAMP) AS DATE), 'YYYY-MM-DD') AS STATUS_DATE,
        DATEDIFF(DAY, CAST(CONVERT_TIMEZONE('America/Los_Angeles', s.STAMP) AS DATE), TO_DATE('{$reportDate}')) AS DAYS,
        ec.PHONE AS PHONE_1,
        ec.PHONE2 AS PHONE_2,
        ec.PHONE3 AS PHONE_3,
        ec.PHONE4 AS PHONE_4,
        CONVERT_TIMEZONE('America/Los_Angeles', s.STAMP) AS STATUS_STAMP,
        ROW_NUMBER() OVER (PARTITION BY ec.ID ORDER BY CONVERT_TIMEZONE('America/Los_Angeles', s.STAMP) DESC, s.STATUS_ID DESC) AS N
    FROM ELIGIBLE_CONTACTS AS ec
    INNER JOIN CONTACTS_STATUS AS s ON ec.ID = s.CONTACT_ID
    LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
    LEFT JOIN ENROLLED_DEBT AS ed ON ec.ID = ed.CONTACT_ID
)
SELECT
    ID,
    CONTACT,
    ENROLLED_DATE,
    ENROLLED_DEBT,
    STATUS,
    STATUS_DATE,
    DAYS,
    PHONE_1,
    PHONE_2,
    PHONE_3,
    PHONE_4
FROM LATEST_STATUS
WHERE N = 1
ORDER BY STATUS_STAMP DESC, ID
SQL;

        $result = $snowflake->query($sql);
        $data = $result['data'] ?? null;
        if (! is_array($data)) {
            throw new \UnexpectedValueException('Snowflake returned an invalid report result.');
        }

        return $data;
    }

    private function initializeSqlServerConnector(): DBConnector
    {
        foreach (['ldr', 'plaw', 'production', 'sandbox'] as $env) {
            try {
                $connector = DBConnector::fromEnvironment($env);
                $connector->initializeSqlServer();

                return $connector;
            } catch (\Throwable) {
            }
        }

        throw new \RuntimeException('Unable to initialize SQL Server connector.');
    }
}
