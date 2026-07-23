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

    /** Keep generated IN clauses small enough for reliable Snowflake execution. */
    private const QUERY_CHUNK_SIZE = 1000;

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
        $data = $this->buildReportData($snowflake, $portal);

        $this->info(sprintf(
            '[INFO] %s rows — Dropped: %d | Reconsideration: %d | Pending: %d',
            $source,
            count($data['dropped_clients']),
            count($data['reconsideration_clients']),
            count($data['reconsideration_pending'])
        ));

        $result = $formatter->buildWorkbook($data, $source);
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
    private function buildReportData(DBConnector $snowflake, array $portal): array
    {
        $dropped = $this->fetchDroppedClients($snowflake);
        $reconsideration = $this->fetchReconsiderationClients($snowflake, $portal);
        $pending = $this->fetchReconsiderationPending($snowflake, (int) $portal['status_id']);
        $reconsiderationIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => (string) ($row['ID'] ?? ''),
            $reconsideration
        ))));
        $status1 = $this->fetchCurrentStatus($snowflake, false, $reconsiderationIds);
        $status2 = $this->fetchCurrentStatus($snowflake, true, $reconsiderationIds);

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
        foreach ($reconsideration as $row) {
            $cid = (string) ($row['ID'] ?? '');
            $active = (string) ($row['ACTIVE_STATUS'] ?? '');
            $drop = $droppedBy[$cid] ?? null;

            $currentStatus = '';
            $statusDate = '';
            $lastStatusBy = '';
            if (strcasecmp($active, 'Active') === 0) {
                $s1 = $status1[$cid] ?? null;
                $s2 = $status2[$cid] ?? null;
                $currentStatus = (string) ($s1['TITLE'] ?? '');
                $statusDate = (string) ($s1['STATUS_DATE'] ?? '');
                $lastStatusBy = (string) ($s2['ENROLLED_BY'] ?? '');
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
        foreach ($pending as $row) {
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
    TO_VARCHAR(c.ENROLLED_DATE::date, 'YYYY-MM-DD') AS ENROLLED_DATE,
    TO_VARCHAR(c.DROPPED_DATE::date, 'YYYY-MM-DD') AS DROPPED_DATE,
    CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) AS DROPPED_BY,
    cr.TITLE AS DROPPED_REASON,
    d.DEBT AS ENROLLED_DEBT
FROM CONTACTS AS c
LEFT JOIN CANCELLATION_REASONS AS cr ON c.DROPPED_REASON = cr.ID
LEFT JOIN CONTACTS_LOG AS l ON c.ID = l.CONTACT_ID
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
  AND l.MESSAGE LIKE '%Drop%'
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
    TO_VARCHAR(c.ENROLLED_DATE::date, 'YYYY-MM-DD') AS ENROLLED_DATE,
    TO_VARCHAR(c.DROPPED_DATE::date, 'YYYY-MM-DD') AS DROPPED_DATE,
    d.DEBT AS ENROLLED_DEBT,
    CASE WHEN c.DROPPED = 0 THEN 'Active' ELSE 'Dropped' END AS ACTIVE_STATUS,
    cu1.F_STRING AS RETENTION_AGENT,
    cu2.F_STRING AS REASON_FOR_REQUEST,
    cu3.F_STRING AS RETENTION_IMMEDIATE_RESULTS,
    cu4.F_SHORTSTRING AS ASSIGNED_TO,
    TO_VARCHAR(cu5.F_DATETIME, 'YYYY-MM-DD HH24:MI:SS') AS CANCEL_REQUEST_DATE
FROM CONTACTS AS c
LEFT JOIN (
    SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS DEBT
    FROM DEBTS
    WHERE ENROLLED = 1
      AND _FIVETRAN_DELETED = FALSE
    GROUP BY CONTACT_ID
) AS d ON c.ID = d.CONTACT_ID
LEFT JOIN (
    SELECT CONTACT_ID, F_STRING
    FROM CONTACTS_USERFIELDS
    WHERE CUSTOM_ID = {$c['retention_agent']}
) AS cu1 ON c.ID = cu1.CONTACT_ID
LEFT JOIN (
    SELECT CONTACT_ID, F_STRING
    FROM CONTACTS_USERFIELDS
    WHERE CUSTOM_ID = {$c['reason_for_request']}
) AS cu2 ON c.ID = cu2.CONTACT_ID
LEFT JOIN (
    SELECT CONTACT_ID, F_STRING
    FROM CONTACTS_USERFIELDS
    WHERE CUSTOM_ID = {$c['retention_immediate_results']}
) AS cu3 ON c.ID = cu3.CONTACT_ID
LEFT JOIN (
    SELECT CONTACT_ID, F_SHORTSTRING
    FROM CONTACTS_USERFIELDS
    WHERE CUSTOM_ID = {$c['assigned_to']}
) AS cu4 ON c.ID = cu4.CONTACT_ID
LEFT JOIN (
    SELECT CONTACT_ID, F_DATETIME
    FROM CONTACTS_USERFIELDS
    WHERE CUSTOM_ID = {$c['cancel_request_date']}
) AS cu5 ON c.ID = cu5.CONTACT_ID
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

    /** @return list<array<string,mixed>> */
    private function fetchReconsiderationPending(DBConnector $snowflake, int $statusId): array
    {
        $sql = "
SELECT CONTACT_ID, STATUS, STATUS_DATE
FROM (
    SELECT
        cs.CONTACT_ID,
        cls.TITLE AS STATUS,
        TO_VARCHAR(cs.STAMP::date, 'YYYY-MM-DD') AS STATUS_DATE,
        ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS N
    FROM CONTACTS_STATUS AS cs
    LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cs.STATUS_ID = cls.ID
    LEFT JOIN CONTACTS AS c ON cs.CONTACT_ID = c.ID
    WHERE c.ID IN (
        SELECT CONTACT_ID
        FROM CONTACTS_STATUS
        WHERE STATUS_ID = {$statusId}
    )
)
WHERE N = 1
";

        return $this->queryRows($snowflake, $sql);
    }

    /**
     * @return array<string, array{CONTACT_ID:string, ENROLLED_BY:string, TITLE:string, STATUS_DATE:string}>
     */
    private function fetchCurrentStatus(
        DBConnector $snowflake,
        bool $excludeSystemUsers,
        array $contactIds
    ): array
    {
        $contactIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): string => trim((string) $id), $contactIds),
            static fn (string $id): bool => ctype_digit($id)
        )));
        if ($contactIds === []) {
            return [];
        }

        $exclude = $excludeSystemUsers
            ? 'AND cs.USER_ID NOT IN (3121141, 7803971)'
            : '';

        $out = [];
        foreach (array_chunk($contactIds, self::QUERY_CHUNK_SIZE) as $chunk) {
            $contactIn = implode(', ', $chunk);
            $sql = "
SELECT CONTACT_ID, ENROLLED_BY, TITLE, STATUS_DATE
FROM (
    SELECT
        cs.CONTACT_ID,
        CONCAT(u.FIRSTNAME, ' ', u.LASTNAME) AS ENROLLED_BY,
        cls.TITLE,
        TO_VARCHAR(cs.STAMP::date, 'YYYY-MM-DD') AS STATUS_DATE,
        ROW_NUMBER() OVER (PARTITION BY cs.CONTACT_ID ORDER BY cs.STAMP DESC) AS N
    FROM CONTACTS_STATUS AS cs
    LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON cs.STATUS_ID = cls.ID
    LEFT JOIN USERS AS u ON cs.USER_ID = u.UID
    WHERE cs.USER_ID > 0
      AND cs.CONTACT_ID > 0
      AND cs.CONTACT_ID IN ({$contactIn})
      {$exclude}
)
WHERE N = 1
ORDER BY CONTACT_ID
";

            foreach ($this->queryRows($snowflake, $sql) as $row) {
                $cid = (string) ($row['CONTACT_ID'] ?? '');
                if ($cid === '') {
                    continue;
                }
                $out[$cid] = [
                    'CONTACT_ID' => $cid,
                    'ENROLLED_BY' => (string) ($row['ENROLLED_BY'] ?? ''),
                    'TITLE' => (string) ($row['TITLE'] ?? ''),
                    'STATUS_DATE' => (string) ($row['STATUS_DATE'] ?? ''),
                ];
            }
        }

        return $out;
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
