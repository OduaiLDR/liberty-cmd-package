<?php

namespace Cmd\Reports\Console\Commands\GenerateOfferAuthorizationReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Port of VBA GenerateOfferAuthorizationReport for LDR + PLAW.
 *
 * VBA differences preserved:
 * - LDR offer status 2088; PLAW offer status 2110
 * - LDR normalizes TITLE + return address from plan prefix
 * - PLAW hardcodes title "Progress Law" + Kenosha return address
 * - Prior TblOfferAuthorization rows (Process_Date < today) are excluded and
 *   newly authorized rows are recorded with Process_Date = today
 */
class GenerateOfferAuthorizationReport extends Command
{
    protected $signature = 'Generate:offer-authorization-report
        {--no-email : Build workbooks only, skip email}
        {--no-track : Skip TblOfferAuthorization lookup/insert (testing only)}';

    protected $description = 'Generate LDR + PLAW Offer Authorization reports and email each separately.';

    /** @var list<array{env:string, source:string, company:string, offer_status:int}> */
    private const PORTALS = [
        ['env' => 'ldr', 'source' => 'LDR', 'company' => 'LDR', 'offer_status' => 2088],
        ['env' => 'plaw', 'source' => 'PLAW', 'company' => 'PLAW', 'offer_status' => 2110],
    ];

    public function handle(): int
    {
        $this->info('[INFO] Offer Authorization report (LDR + PLAW): starting.');

        $sqlConnector = null;
        $needsSql = ! $this->option('no-email') || ! $this->option('no-track');

        if ($needsSql) {
            try {
                $sqlConnector = $this->initializeSqlServerConnector();
            } catch (\Throwable $e) {
                $this->error('Failed to initialize SQL Server: '.$e->getMessage());
                Log::error('GenerateOfferAuthorizationReport: sql init failed', ['exception' => $e]);

                return Command::FAILURE;
            }
        }

        if ($this->option('no-email')) {
            $this->warn('[WARN] --no-email set; workbooks kept under storage/app.');
        }
        if ($this->option('no-track')) {
            $this->warn('[WARN] --no-track set; TblOfferAuthorization will not be read or written.');
        }

        $formatter = new Formatter;
        $dates = $this->resolveBusinessDates();
        $processDate = $dates['process'];
        $reportDate = $dates['report'];
        $this->info(sprintf(
            '[INFO] Authorization process date: %s | report title date: %s',
            $processDate,
            $reportDate
        ));

        $failed = 0;
        foreach (self::PORTALS as $portal) {
            try {
                $this->generateForPortal($portal, $formatter, $processDate, $reportDate, $sqlConnector);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$portal['source']} failed: ".$e->getMessage());
                Log::error('GenerateOfferAuthorizationReport: portal failed', [
                    'portal' => $portal['source'],
                    'exception' => $e,
                ]);
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{env:string, source:string, company:string, offer_status:int}  $portal
     */
    private function generateForPortal(
        array $portal,
        Formatter $formatter,
        string $processDate,
        string $reportDate,
        ?DBConnector $sqlConnector
    ): void {
        $source = $portal['source'];
        $this->info("[INFO] === {$source} ===");

        $snowflake = DBConnector::fromEnvironment($portal['env']);
        $candidates = $this->fetchOfferCandidates($snowflake, $portal['offer_status']);
        $this->info("[INFO] {$source} Snowflake candidates: ".count($candidates));

        $track = ! $this->option('no-track');
        $rows = $this->prepareAuthorizationRows(
            $candidates,
            $source,
            $processDate,
            $sqlConnector,
            $track
        );
        $this->info("[INFO] {$source} authorized offers for report: ".count($rows));

        if ($rows === []) {
            $this->info("[INFO] {$source}: no new offer authorizations; workbook and email skipped.");

            return;
        }

        $result = $formatter->buildWorkbook($rows, $source, $reportDate);
        $path = $result['path'];
        $this->info("[INFO] {$source} workbook: {$path}");

        if ($this->option('no-email')) {
            if ($track && $sqlConnector !== null) {
                $this->recordAuthorizations($sqlConnector, $rows, $processDate);
            }

            return;
        }

        if ($sqlConnector === null) {
            throw new \RuntimeException("{$source} email requested but SQL Server connector is unavailable.");
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

        // Only mark offers after the report was successfully built and delivered.
        // A workbook/email failure must not suppress undelivered offers tomorrow.
        if ($track) {
            $this->recordAuthorizations($sqlConnector, $rows, $processDate);
        }

        if (is_file($path) && ! unlink($path)) {
            Log::warning('GenerateOfferAuthorizationReport: sent workbook could not be deleted.', [
                'path' => $path,
                'portal' => $source,
            ]);
            $this->warn("[WARN] {$source} workbook was sent but could not be deleted: {$path}");
        }

        $this->info("[INFO] {$source} email sent.");
    }

    /**
     * @return list<array{
     *   offer_id:string,
     *   llg_id:string,
     *   title:string,
     *   firstname:string,
     *   lastname:string,
     *   address:string,
     *   address2:string,
     *   address3:string,
     *   city:string,
     *   state:string,
     *   zip:string,
     *   return_address:string
     * }>
     */
    private function fetchOfferCandidates(DBConnector $snowflake, int $offerStatus): array
    {
        // VBA:
        // SELECT s.ID AS OFFER_ID, CONCAT('LLG-',c.ID) AS LLG_ID, e2.TITLE,
        //        c.FIRSTNAME, c.LASTNAME, c.ADDRESS, c.ADDRESS2, c.ADDRESS3,
        //        c.CITY, c.STATE, c.ZIP, NULL AS RETURN_ADDRESS
        // FROM SETTLEMENT_OFFERS AS s
        // LEFT JOIN CONTACTS AS c ON s.CONTACT_ID = c.ID
        // LEFT JOIN ENROLLMENT_PLAN AS e1 ON s.CONTACT_ID = e1.CONTACT_ID
        // LEFT JOIN ENROLLMENT_DEFAULTS2 AS e2 ON e1.PLAN_ID = e2.ID
        // WHERE s.OFFER_STATUS = {2088 LDR | 2110 PLAW}
        $sql = "
SELECT
    s.ID AS OFFER_ID,
    CONCAT('LLG-', c.ID) AS LLG_ID,
    e2.TITLE,
    c.FIRSTNAME,
    c.LASTNAME,
    c.ADDRESS,
    c.ADDRESS2,
    c.ADDRESS3,
    c.CITY,
    c.STATE,
    c.ZIP,
    NULL AS RETURN_ADDRESS
FROM SETTLEMENT_OFFERS AS s
LEFT JOIN CONTACTS AS c ON s.CONTACT_ID = c.ID
LEFT JOIN ENROLLMENT_PLAN AS e1 ON s.CONTACT_ID = e1.CONTACT_ID
LEFT JOIN ENROLLMENT_DEFAULTS2 AS e2 ON e1.PLAN_ID = e2.ID
WHERE s.OFFER_STATUS = {$offerStatus}
";

        $result = $snowflake->query($sql);
        $data = $result['data'] ?? null;
        if (! is_array($data)) {
            throw new \UnexpectedValueException('Snowflake returned an invalid report result.');
        }

        $out = [];
        foreach ($data as $row) {
            $offerId = trim((string) ($row['OFFER_ID'] ?? ''));
            $llgId = trim((string) ($row['LLG_ID'] ?? ''));
            if ($offerId === '' || $llgId === '' || strcasecmp($llgId, 'LLG-') === 0) {
                continue;
            }

            $out[] = [
                'offer_id' => $offerId,
                'llg_id' => $llgId,
                'title' => (string) ($row['TITLE'] ?? ''),
                'firstname' => (string) ($row['FIRSTNAME'] ?? ''),
                'lastname' => (string) ($row['LASTNAME'] ?? ''),
                'address' => (string) ($row['ADDRESS'] ?? ''),
                'address2' => (string) ($row['ADDRESS2'] ?? ''),
                'address3' => (string) ($row['ADDRESS3'] ?? ''),
                'city' => (string) ($row['CITY'] ?? ''),
                'state' => $this->normalizeState((string) ($row['STATE'] ?? '')),
                'zip' => (string) ($row['ZIP'] ?? ''),
                'return_address' => (string) ($row['RETURN_ADDRESS'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{
     *   offer_id:string,
     *   llg_id:string,
     *   title:string,
     *   firstname:string,
     *   lastname:string,
     *   address:string,
     *   address2:string,
     *   address3:string,
     *   city:string,
     *   state:string,
     *   zip:string,
     *   return_address:string
     * }>  $candidates
     * @return list<array{
     *   offer_id:string,
     *   llg_id:string,
     *   title:string,
     *   firstname:string,
     *   lastname:string,
     *   address:string,
     *   address2:string,
     *   address3:string,
     *   city:string,
     *   state:string,
     *   zip:string,
     *   return_address:string
     * }>
     */
    private function prepareAuthorizationRows(
        array $candidates,
        string $source,
        string $processDate,
        ?DBConnector $sqlConnector,
        bool $track
    ): array {
        $rows = array_map(
            fn (array $candidate): array => $this->normalizePortalFields($candidate, $source),
            $candidates
        );

        if ($track) {
            if ($sqlConnector === null) {
                throw new \RuntimeException('SQL Server connector required for TblOfferAuthorization tracking.');
            }

            $previous = $this->fetchPreviouslyAuthorizedKeys($sqlConnector, $rows, $processDate);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ! isset($previous[$this->authorizationKey($row['offer_id'], $row['llg_id'])])
            ));
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = strcasecmp($a['lastname'], $b['lastname']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = strcasecmp($a['firstname'], $b['firstname']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcasecmp($a['offer_id'], $b['offer_id']);
        });

        return $rows;
    }

    /**
     * @param  array{
     *   offer_id:string,
     *   llg_id:string,
     *   title:string,
     *   firstname:string,
     *   lastname:string,
     *   address:string,
     *   address2:string,
     *   address3:string,
     *   city:string,
     *   state:string,
     *   zip:string,
     *   return_address:string
     * }  $row
     * @return array{
     *   offer_id:string,
     *   llg_id:string,
     *   title:string,
     *   firstname:string,
     *   lastname:string,
     *   address:string,
     *   address2:string,
     *   address3:string,
     *   city:string,
     *   state:string,
     *   zip:string,
     *   return_address:string
     * }
     */
    private function normalizePortalFields(array $row, string $source): array
    {
        if ($source === 'PLAW') {
            // VBA PLAW hardcodes both fields for every kept row.
            $row['title'] = 'Progress Law';
            $row['return_address'] = '7520 39th Ave, Suite 102, Kenosha, WI 53142';

            return $row;
        }

        // VBA LDR:
        // If TITLE Like "*LDR *" => LDR / Orange return address
        // ElseIf TITLE Like "PLAW *" => PLAW / San Diego return address
        $title = (string) $row['title'];
        if (preg_match('/LDR\s/i', $title) === 1) {
            $row['title'] = 'LDR';
            $row['return_address'] = '333 City Blvd W 17 FL, Orange CA, 92868';
        } elseif (preg_match('/^PLAW\s/i', $title) === 1) {
            $row['title'] = 'PLAW';
            $row['return_address'] = '350 10th Avenue, Suite 1000, San Diego CA, 92101-7496';
        }

        return $row;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, true>
     */
    private function fetchPreviouslyAuthorizedKeys(
        DBConnector $sqlConnector,
        array $rows,
        string $processDate
    ): array {
        $existing = [];

        // SQL Server supports at most 2,100 parameters. Each row uses two,
        // plus one process-date parameter.
        foreach (array_chunk($rows, 900) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
            $params = [];
            foreach ($chunk as $row) {
                $params[] = $row['offer_id'];
                $params[] = $row['llg_id'];
            }
            $params[] = $processDate;

            $result = $sqlConnector->querySqlServer(
                "SELECT DISTINCT t.Offer_ID, t.LLG_ID
                 FROM TblOfferAuthorization AS t
                 INNER JOIN (VALUES {$values}) AS requested(Offer_ID, LLG_ID)
                   ON requested.Offer_ID = t.Offer_ID
                  AND requested.LLG_ID = t.LLG_ID
                 WHERE t.Process_Date < ?",
                $params
            );

            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException(
                    'TblOfferAuthorization lookup failed: '.($result['error'] ?? 'unknown error')
                );
            }

            foreach ($result['data'] ?? [] as $row) {
                $offerId = (string) ($row['Offer_ID'] ?? $row['OFFER_ID'] ?? '');
                $llgId = (string) ($row['LLG_ID'] ?? '');
                $existing[$this->authorizationKey($offerId, $llgId)] = true;
            }
        }

        return $existing;
    }

    /** @param list<array<string, string>> $rows */
    private function recordAuthorizations(
        DBConnector $sqlConnector,
        array $rows,
        string $processDate
    ): void {
        foreach (array_chunk($rows, 700) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $params = [];
            foreach ($chunk as $row) {
                $params[] = $row['offer_id'];
                $params[] = $row['llg_id'];
                $params[] = $processDate;
            }

            $result = $sqlConnector->querySqlServer(
                "INSERT INTO TblOfferAuthorization (Offer_ID, LLG_ID, Process_Date) VALUES {$values}",
                $params
            );

            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException(
                    'TblOfferAuthorization insert failed: '.($result['error'] ?? 'unknown error')
                );
            }
        }
    }

    private function authorizationKey(string $offerId, string $llgId): string
    {
        return $offerId."\0".$llgId;
    }

    /** @return array{process:string, report:string} */
    private function resolveBusinessDates(): array
    {
        $today = Carbon::now('America/Los_Angeles')->startOfDay();

        return [
            'process' => $today->toDateString(),
            'report' => $today->copy()->subDay()->toDateString(),
        ];
    }

    private function normalizeState(string $state): string
    {
        // VBA: Range("J:J").Replace What:="_", Replacement:=" "
        return str_replace('_', ' ', $state);
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
}
