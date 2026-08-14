<?php

namespace Cmd\Reports\Console\Commands\GenerateReconsiderationReport;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Port of VBA GenerateReconsiderationReport for LDR + PLAW.
 * One command -> separate workbook + email per portal.
 *
 * Portal-specific IDs:
 * - LDR status 377650, custom fields 745758/745763/745762/742152/745759
 * - PLAW status 377687, custom fields 745893/745898/745762/745898/745894
 */
class GenerateReconsiderationReport extends Command
{
    private const REPORT_TIMEZONE = 'America/Los_Angeles';

    private const SYSTEM_USER_IDS = '3121141, 7803971';

    protected $signature = 'Generate:reconsideration-report
        {--no-email : Build workbooks only, skip email}';

    protected $description = 'Generate LDR + PLAW Reconsideration reports and email each separately.';

    /** @var list<array{env:string, source:string, company:string, status_id:int, custom:array{retention_agent:int, reason_for_request:int, retention_immediate_results:int, assigned_to:int, cancel_request_date:int}}> */
    private const PORTALS = [
        [
            'env' => 'ldr',
            'source' => 'LDR',
            'company' => 'LDR',
            'status_id' => 377650,
            'custom' => [
                'retention_agent' => 745758,
                'reason_for_request' => 745763,
                'retention_immediate_results' => 745762,
                'assigned_to' => 742152,
                'cancel_request_date' => 745759,
            ],
        ],
        [
            'env' => 'plaw',
            'source' => 'PLAW',
            'company' => 'PLAW',
            'status_id' => 377687,
            'custom' => [
                'retention_agent' => 745893,
                'reason_for_request' => 745898,
                'retention_immediate_results' => 745762,
                'assigned_to' => 745898,
                'cancel_request_date' => 745894,
            ],
        ],
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        $this->info('[INFO] Reconsideration report (LDR + PLAW): starting.');

        $sqlConnector = null;
        if (! $this->option('no-email')) {
            try {
                $sqlConnector = $this->initializeSqlServerConnector();
            } catch (\Throwable $e) {
                $this->error('Failed to initialize SQL Server: '.$e->getMessage());
                Log::error('GenerateReconsiderationReport: sql init failed', ['exception' => $e]);

                return Command::FAILURE;
            }
        } else {
            $this->warn('[WARN] --no-email set; workbooks kept under storage/app.');
        }

        $formatter = new Formatter;
        $failed = 0;

        foreach (self::PORTALS as $portal) {
            try {
                $this->generateForPortal($portal, $formatter, $sqlConnector);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$portal['source']} failed: ".$e->getMessage());
                Log::error('GenerateReconsiderationReport: portal failed', [
                    'portal' => $portal['source'],
                    'exception' => $e,
                ]);
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array{env:string, source:string, company:string, status_id:int, custom:array{retention_agent:int, reason_for_request:int, retention_immediate_results:int, assigned_to:int, cancel_request_date:int}}  $portal
     */
    private function generateForPortal(
        array $portal,
        Formatter $formatter,
        ?DBConnector $sqlConnector
    ): void {
        $source = $portal['source'];
        $this->info("[INFO] === {$source} ===");

        $snowflake = DBConnector::fromEnvironment($portal['env']);
        $data = $this->buildReportData($snowflake, $portal, $source);

        $this->info(sprintf(
            '[INFO] %s rows — Dropped: %d | Reconsideration: %d | Pending: %d',
            $source,
            count($data['dropped_clients']),
            count($data['reconsideration_clients']),
            count($data['reconsideration_pending'])
        ));

        $result = $this->timed("{$source} workbook", fn () => $formatter->buildWorkbook($data, $source));
        $path = $result['path'];
        $this->info("[INFO] {$source} workbook: {$path}");

        if ($sqlConnector === null) {
            return;
        }

        $sent = $this->timed("{$source} email", fn () => $formatter->sendReport(
            $sqlConnector,
            $result['path'],
            $result['filename'],
            $source,
            $portal['company'],
            $this
        ));

        if (! $sent) {
            throw new \RuntimeException("{$source} email failed. Workbook kept at: {$path}");
        }

        if (is_file($path) && ! unlink($path)) {
            Log::warning('GenerateReconsiderationReport: sent workbook could not be deleted.', [
                'path' => $path,
                'portal' => $source,
            ]);
            $this->warn("[WARN] {$source} workbook was sent but could not be deleted: {$path}");
        }

        $this->info("[INFO] {$source} email sent.");
    }

    /**
     * @param  array{env:string, source:string, company:string, status_id:int, custom:array{retention_agent:int, reason_for_request:int, retention_immediate_results:int, assigned_to:int, cancel_request_date:int}}  $portal
     * @return array{
     *   dropped_clients:list<array<string,mixed>>,
     *   reconsideration_clients:list<array<string,mixed>>,
     *   reconsideration_pending:list<array<string,mixed>>,
     *   current_status_1:list<array<string,mixed>>,
     *   current_status_2:list<array<string,mixed>>,
     *   months:list<string>
     * }
     */
    private function buildReportData(DBConnector $snowflake, array $portal, string $source = ''): array
    {
        $prefix = $source !== '' ? "{$source} " : '';

        $dropped = $this->timed($prefix.'query Dropped', fn () => $this->fetchDroppedClients($snowflake));
        $reconsideration = $this->timed($prefix.'query Reconsideration', fn () => $this->fetchReconsiderationClients($snowflake, $portal));
        $bundle = $this->timed($prefix.'query Status bundle', fn () => $this->fetchStatusBundle($snowflake, (int) $portal['status_id']));

        return $this->assembleReportData($dropped, $reconsideration, $bundle);
    }

    /**
     * @param  list<array<string,mixed>>  $dropped
     * @param  list<array<string,mixed>>  $reconsideration
     * @param  array{
     *   pending:list<array<string,mixed>>,
     *   status1:array<string, array{CONTACT_ID:string, ENROLLED_BY:string, TITLE:string, STATUS_DATE:string}>,
     *   status2:array<string, array{CONTACT_ID:string, ENROLLED_BY:string, TITLE:string, STATUS_DATE:string}>
     * }  $bundle
     * @return array{
     *   dropped_clients:list<array<string,mixed>>,
     *   reconsideration_clients:list<array<string,mixed>>,
     *   reconsideration_pending:list<array<string,mixed>>,
     *   current_status_1:list<array<string,mixed>>,
     *   current_status_2:list<array<string,mixed>>,
     *   months:list<string>
     * }
     */
    private function assembleReportData(array $dropped, array $reconsideration, array $bundle): array
    {
        $status1All = $bundle['status1'];
        $status2All = $bundle['status2'];

        $droppedBy = [];
        foreach ($dropped as $row) {
            $cid = (string) ($row['ID'] ?? '');
            if ($cid === '') {
                continue;
            }
            if (! isset($droppedBy[$cid])) {
                $droppedBy[$cid] = $row;
            }
        }

        $clients = [];
        $status1 = [];
        $status2 = [];
        $seenStatusIds = [];
        foreach ($reconsideration as $row) {
            $cid = (string) ($row['ID'] ?? '');
            $statusKey = $this->statusLookupKey($cid);
            $active = (string) ($row['ACTIVE_STATUS'] ?? '');
            $drop = $droppedBy[$cid] ?? null;

            $currentStatus = '';
            $statusDate = '';
            $lastStatusBy = '';
            if (strcasecmp($active, 'Active') === 0) {
                $s1 = $status1All[$cid] ?? ($statusKey !== '' ? ($status1All[$statusKey] ?? null) : null);
                $s2 = $status2All[$cid] ?? ($statusKey !== '' ? ($status2All[$statusKey] ?? null) : null);
                $currentStatus = (string) ($s1['TITLE'] ?? '');
                $statusDate = (string) ($s1['STATUS_DATE'] ?? '');
                $lastStatusBy = (string) ($s2['ENROLLED_BY'] ?? '');
            }

            if ($statusKey !== '' && ! isset($seenStatusIds[$statusKey])) {
                $seenStatusIds[$statusKey] = true;
                if (isset($status1All[$statusKey])) {
                    $status1[$statusKey] = $status1All[$statusKey];
                }
                if (isset($status2All[$statusKey])) {
                    $status2[$statusKey] = $status2All[$statusKey];
                }
            }

            $clients[] = [
                'id' => $cid,
                'client' => (string) ($row['CLIENT'] ?? ''),
                'enrolled_date' => (string) ($row['ENROLLED_DATE'] ?? ''),
                'dropped_date' => (string) ($row['DROPPED_DATE'] ?? ''),
                'dropped_by' => (string) ($drop['DROPPED_BY'] ?? ''),
                'dropped_reason' => (string) ($drop['DROPPED_REASON'] ?? ''),
                'enrolled_debt' => (float) ($row['ENROLLED_DEBT'] ?? 0),
                'active_status' => $active,
                'current_status' => $currentStatus,
                'status_date' => $statusDate,
                'last_status_by' => $lastStatusBy,
                'retention_agent' => (string) ($row['RETENTION_AGENT'] ?? ''),
                'reason_for_request' => (string) ($row['REASON_FOR_REQUEST'] ?? ''),
                'retention_immediate_results' => (string) ($row['RETENTION_IMMEDIATE_RESULTS'] ?? ''),
                'assigned_to' => (string) ($row['ASSIGNED_TO'] ?? ''),
                'cancel_request_date' => (string) ($row['CANCEL_REQUEST_DATE'] ?? ''),
            ];
        }

        $droppedClients = [];
        foreach ($dropped as $row) {
            $droppedClients[] = [
                'id' => (string) ($row['ID'] ?? ''),
                'client' => (string) ($row['CLIENT'] ?? ''),
                'enrolled_date' => (string) ($row['ENROLLED_DATE'] ?? ''),
                'dropped_date' => (string) ($row['DROPPED_DATE'] ?? ''),
                'dropped_by' => (string) ($row['DROPPED_BY'] ?? ''),
                'dropped_reason' => (string) ($row['DROPPED_REASON'] ?? ''),
                'enrolled_debt' => (float) ($row['ENROLLED_DEBT'] ?? 0),
            ];
        }

        $pendingRows = [];
        foreach ($bundle['pending'] as $row) {
            $pendingRows[] = [
                'contact_id' => (string) ($row['CONTACT_ID'] ?? ''),
                'status' => (string) ($row['STATUS'] ?? ''),
                'status_date' => (string) ($row['STATUS_DATE'] ?? ''),
            ];
        }

        return [
            'dropped_clients' => $droppedClients,
            'reconsideration_clients' => $clients,
            'reconsideration_pending' => $pendingRows,
            'current_status_1' => array_values($status1),
            'current_status_2' => array_values($status2),
            'months' => $this->monthStarts(),
        ];
    }

    /** Same keying as the old chunked status lookup: trim + digits only. */
    private function statusLookupKey(string $contactId): string
    {
        $contactId = trim($contactId);

        return ctype_digit($contactId) ? $contactId : '';
    }

    /** @return list<string> YYYY-MM-01 for current and previous 3 months */
    private function monthStarts(): array
    {
        $today = Carbon::today(self::REPORT_TIMEZONE);
        $months = [];
        for ($i = 3; $i >= 0; $i--) {
            $months[] = $today->copy()->startOfMonth()->subMonthsNoOverflow($i)->toDateString();
        }

        return $months;
    }

    /** @return list<array<string,mixed>> */
    private function fetchDroppedClients(DBConnector $snowflake): array
    {
        $sql = <<<'SQL'
SELECT
    c.ID,
    CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
    TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE) AS DATE), 'YYYY-MM-DD') AS ENROLLED_DATE,
    TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.DROPPED_DATE) AS DATE), 'YYYY-MM-DD') AS DROPPED_DATE,
    CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) AS DROPPED_BY,
    cr.TITLE AS DROPPED_REASON,
    d.DEBT AS ENROLLED_DEBT
FROM CONTACTS AS c
LEFT JOIN CANCELLATION_REASONS AS cr ON c.DROPPED_REASON = cr.ID
LEFT JOIN (
    SELECT CONTACT_ID, MAX(CREATED_BY) AS CREATED_BY
    FROM CONTACTS_LOG
    WHERE MESSAGE LIKE '%Drop%'
      AND CONTACT_ID IN (SELECT ID FROM CONTACTS WHERE DROPPED = 1 AND ENROLLED_DATE IS NOT NULL)
    GROUP BY CONTACT_ID
) AS l ON c.ID = l.CONTACT_ID
LEFT JOIN USERS AS u ON l.CREATED_BY = u.UID
LEFT JOIN (
    SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS DEBT
    FROM DEBTS
    WHERE ENROLLED = 1
      AND _FIVETRAN_DELETED = FALSE
    GROUP BY CONTACT_ID
) AS d ON c.ID = d.CONTACT_ID
WHERE c.ENROLLED_DATE IS NOT NULL
  AND c.DROPPED = 1
  AND UPPER(c.FIRSTNAME) <> 'TEST'
  AND UPPER(c.LASTNAME) <> 'TEST'
  AND COALESCE(c.FIRSTNAME, '') <> ''
  AND CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) <> '% User'
ORDER BY CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) ASC, c.DROPPED_DATE ASC
SQL;

        return $this->queryRows($snowflake, $sql);
    }

    /**
     * @param  array{status_id:int, custom:array{retention_agent:int, reason_for_request:int, retention_immediate_results:int, assigned_to:int, cancel_request_date:int}}  $portal
     * @return list<array<string,mixed>>
     */
    private function fetchReconsiderationClients(DBConnector $snowflake, array $portal): array
    {
        $statusId = (int) $portal['status_id'];
        $c = $portal['custom'];

        $sql = "
SELECT
    c.ID,
    CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS CLIENT,
    TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.ENROLLED_DATE) AS DATE), 'YYYY-MM-DD') AS ENROLLED_DATE,
    TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', c.DROPPED_DATE) AS DATE), 'YYYY-MM-DD') AS DROPPED_DATE,
    d.DEBT AS ENROLLED_DEBT,
    CASE WHEN c.DROPPED = 0 THEN 'Active' ELSE 'Dropped' END AS ACTIVE_STATUS,
    cu.RETENTION_AGENT,
    cu.REASON_FOR_REQUEST,
    cu.RETENTION_IMMEDIATE_RESULTS,
    cu.ASSIGNED_TO,
    TO_VARCHAR(CONVERT_TIMEZONE('America/Los_Angeles', cu.CANCEL_REQUEST_DATE), 'YYYY-MM-DD HH24:MI:SS') AS CANCEL_REQUEST_DATE
FROM CONTACTS AS c
LEFT JOIN (
    SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS DEBT
    FROM DEBTS
    WHERE ENROLLED = 1
      AND _FIVETRAN_DELETED = FALSE
    GROUP BY CONTACT_ID
) AS d ON c.ID = d.CONTACT_ID
LEFT JOIN (
    SELECT
        CONTACT_ID,
        MAX(CASE WHEN CUSTOM_ID = {$c['retention_agent']} THEN F_STRING END) AS RETENTION_AGENT,
        MAX(CASE WHEN CUSTOM_ID = {$c['reason_for_request']} THEN F_STRING END) AS REASON_FOR_REQUEST,
        MAX(CASE WHEN CUSTOM_ID = {$c['retention_immediate_results']} THEN F_STRING END) AS RETENTION_IMMEDIATE_RESULTS,
        MAX(CASE WHEN CUSTOM_ID = {$c['assigned_to']} THEN F_SHORTSTRING END) AS ASSIGNED_TO,
        MAX(CASE WHEN CUSTOM_ID = {$c['cancel_request_date']} THEN F_DATETIME END) AS CANCEL_REQUEST_DATE
    FROM CONTACTS_USERFIELDS
    WHERE CUSTOM_ID IN ({$c['retention_agent']}, {$c['reason_for_request']}, {$c['retention_immediate_results']}, {$c['assigned_to']}, {$c['cancel_request_date']})
    GROUP BY CONTACT_ID
) AS cu ON c.ID = cu.CONTACT_ID
WHERE c.ENROLLED_DATE IS NOT NULL
  AND UPPER(c.FIRSTNAME) <> 'TEST'
  AND UPPER(c.LASTNAME) <> 'TEST'
  AND COALESCE(c.FIRSTNAME, '') <> ''
  AND c.ID IN (
      SELECT CONTACT_ID
      FROM CONTACTS_STATUS
      WHERE STATUS_ID = {$statusId}
  )
";

        return $this->queryRows($snowflake, $sql);
    }

    /**
     * Latest status (pending + current-status variants) in one Snowflake round trip.
     *
     * pending: absolute latest row (no USER_ID filter) for every contact that ever
     * had the reconsideration status — same population as the old pending query.
     * status1 / status2: latest among USER_ID > 0 (status2 also drops system users).
     * Hidden Current Status sheets are still limited to reconsideration clients in PHP.
     *
     * @return array{
     *   pending:list<array<string,mixed>>,
     *   status1:array<string, array{CONTACT_ID:string, ENROLLED_BY:string, TITLE:string, STATUS_DATE:string}>,
     *   status2:array<string, array{CONTACT_ID:string, ENROLLED_BY:string, TITLE:string, STATUS_DATE:string}>
     * }
     */
    private function fetchStatusBundle(DBConnector $snowflake, int $statusId): array
    {
        $sql = "
WITH base AS (
    SELECT
        cs.CONTACT_ID,
        cs.USER_ID,
        CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) AS ENROLLED_BY,
        cls.TITLE,
        TO_VARCHAR(CAST(CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) AS DATE), 'YYYY-MM-DD') AS STATUS_DATE,
        CONVERT_TIMEZONE('America/Los_Angeles', cs.STAMP) AS STAMP_LA
    FROM CONTACTS_STATUS AS cs
    INNER JOIN CONTACTS AS c ON cs.CONTACT_ID = c.ID
    LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cs.STATUS_ID = cls.ID
    LEFT JOIN USERS AS u ON cs.USER_ID = u.UID
    WHERE c.ID IN (
        SELECT CONTACT_ID
        FROM CONTACTS_STATUS
        WHERE STATUS_ID = {$statusId}
    )
),
ranked AS (
    SELECT
        CONTACT_ID,
        USER_ID,
        ENROLLED_BY,
        TITLE,
        STATUS_DATE,
        ROW_NUMBER() OVER (PARTITION BY CONTACT_ID ORDER BY STAMP_LA DESC) AS N_PENDING,
        ROW_NUMBER() OVER (
            PARTITION BY CONTACT_ID, IFF(USER_ID > 0 AND CONTACT_ID > 0, 1, 0)
            ORDER BY STAMP_LA DESC
        ) AS N_STATUS1,
        ROW_NUMBER() OVER (
            PARTITION BY CONTACT_ID, IFF(USER_ID > 0 AND CONTACT_ID > 0 AND USER_ID NOT IN (".self::SYSTEM_USER_IDS."), 1, 0)
            ORDER BY STAMP_LA DESC
        ) AS N_STATUS2
    FROM base
)
SELECT KIND, CONTACT_ID, ENROLLED_BY, TITLE, STATUS_DATE
FROM (
    SELECT 'pending' AS KIND, CONTACT_ID, ENROLLED_BY, TITLE, STATUS_DATE
    FROM ranked
    WHERE N_PENDING = 1
    UNION ALL
    SELECT 'status1' AS KIND, CONTACT_ID, ENROLLED_BY, TITLE, STATUS_DATE
    FROM ranked
    WHERE N_STATUS1 = 1
      AND USER_ID > 0
      AND CONTACT_ID > 0
    UNION ALL
    SELECT 'status2' AS KIND, CONTACT_ID, ENROLLED_BY, TITLE, STATUS_DATE
    FROM ranked
    WHERE N_STATUS2 = 1
      AND USER_ID > 0
      AND CONTACT_ID > 0
      AND USER_ID NOT IN (".self::SYSTEM_USER_IDS.")
) AS status_bundle
";

        $pending = [];
        $status1 = [];
        $status2 = [];
        foreach ($this->queryRows($snowflake, $sql) as $row) {
            $kind = strtolower((string) ($row['KIND'] ?? ''));
            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid === '') {
                continue;
            }
            if ($kind === 'pending') {
                $pending[] = [
                    'CONTACT_ID' => $cid,
                    'STATUS' => (string) ($row['TITLE'] ?? ''),
                    'STATUS_DATE' => (string) ($row['STATUS_DATE'] ?? ''),
                ];
                continue;
            }

            $mapped = [
                'CONTACT_ID' => $cid,
                'ENROLLED_BY' => (string) ($row['ENROLLED_BY'] ?? ''),
                'TITLE' => (string) ($row['TITLE'] ?? ''),
                'STATUS_DATE' => (string) ($row['STATUS_DATE'] ?? ''),
            ];
            if ($kind === 'status1') {
                $status1[$cid] = $mapped;
            } elseif ($kind === 'status2') {
                $status2[$cid] = $mapped;
            }
        }

        return [
            'pending' => $pending,
            'status1' => $status1,
            'status2' => $status2,
        ];
    }

    /**
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    private function timed(string $label, callable $fn): mixed
    {
        $started = microtime(true);
        try {
            return $fn();
        } finally {
            $seconds = round(microtime(true) - $started, 1);
            $line = "[INFO] {$label}: {$seconds}s";
            try {
                Log::info('GenerateReconsiderationReport timing', [
                    'label' => $label,
                    'seconds' => $seconds,
                ]);
            } catch (\Throwable) {
                // Log facade is not bound in unit tests.
            }
            try {
                $this->info($line);
            } catch (\Throwable) {
                // Command output is not bound in unit tests.
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function queryRows(DBConnector $snowflake, string $sql): array
    {
        $result = $snowflake->query($sql);
        $data = $result['data'] ?? null;
        if (! is_array($data)) {
            throw new \UnexpectedValueException('Snowflake returned an invalid report result.');
        }

        return $data;
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
