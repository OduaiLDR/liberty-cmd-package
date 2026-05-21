<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Sync CCS contacts: Snowflake → TblContacts (CCS DB) → TblContactsCCS (LDR DB).
 *
 * Campaign (Drop_Name) is resolved per-chunk in PHP via temp-table-filtered
 * lookups against TblEnrollmentOverrides and TblMailers, then INSERTed with
 * the row — no post-INSERT UPDATE pass.
 *
 * The 3-step precedence is preserved:
 *   1) TblEnrollmentOverrides matched by CID — overrides both External_ID and Campaign.
 *   2) TblMailers matched by (overridden) External_ID — sets Campaign if empty.
 *   3) TblMailers matched by Address_1 — fallback only when Campaign is still empty
 *      and Data_Source does not contain "non-mailer".
 *
 * --full is non-routine. Daily cron runs incremental (small, fast). Use --full
 * only for: schema changes, data recovery, or initial backfill.
 */
class SyncContactsCCS extends Command
{
    protected $signature = 'Sync:contacts-ccs
        {--full : Force a full refresh even when a previous sync timestamp exists}';

    protected $description = 'Sync CCS contacts from Snowflake into TblContacts (CCS DB) and TblContactsCCS (LDR DB)';

    private const PAGE_SIZE      = 50000;
    private const COPY_PAGE_SIZE = 5000;

    // CONTACTS_USERFIELDS custom field IDs on the CCS Snowflake account
    private const CF_LOAN_AMOUNT_NEEDED      = 671993;
    private const CF_ENROLLMENT_DATE         = 682517;
    private const CF_DROPPED_DATE            = 679265;
    private const CF_PAYMENTS                = 678932;
    private const CF_FPC_DATE                = 676514;
    private const CF_FPR_DATE                = 688859;
    private const CF_FIRST_PAYMENT_AMOUNT    = 732248;
    private const CF_PAYMENT_FREQUENCY       = 658119;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $this->info('[INFO] SyncContactsCCS: starting.');

        // ── Snowflake ─────────────────────────────────────────────────────────
        $this->info('[DEBUG] Initializing CCS Snowflake connector...');
        try {
            $snowflake = DBConnector::fromEnvironment('ccs');
        } catch (\Throwable $e) {
            $this->error('Failed to initialize CCS Snowflake connector: ' . $e->getMessage());
            Log::error('SyncContactsCCS: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }
        $this->info('[DEBUG] CCS Snowflake connector OK.');

        // ── CCS SQL Server (raw PDO — DBConnector::initializeSqlServer always picks
        //    sql_server_connection, so we bypass it for CCS by connecting directly) ──
        $this->info('[DEBUG] Initializing CCS SQL Server connection...');
        try {
            $ccsPdo = $this->initializeCcsPdo();
        } catch (\Throwable $e) {
            $this->error('Failed to connect to CCS SQL Server: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $this->info('[DEBUG] CCS SQL Server OK.');

        // ── LDR SQL Server (for TblContactsCCS copy) ─────────────────────────
        $this->info('[DEBUG] Initializing LDR SQL Server connector...');
        try {
            $ldrConnector = DBConnector::fromEnvironment('ldr');
            $ldrConnector->initializeSqlServer();
            $ldrPdo = $ldrConnector->getSqlServerConnection();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize LDR SQL Server connector: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $this->info('[DEBUG] LDR SQL Server connector OK.');

        // ── Sync mode ─────────────────────────────────────────────────────────
        $lastSyncAt    = $this->option('full') ? null : $this->readLastSyncTime();
        $isIncremental = $lastSyncAt !== null;

        if ($isIncremental) {
            $startDate = date('Y-m-d H:i:s', strtotime($lastSyncAt) - 600);
            $this->info("[INFO] Incremental mode: fetching contacts modified since {$startDate}.");
        } else {
            $startDate = '2021-07-01';
            $this->info('[INFO] Full refresh mode.');
            $this->clearTable($ccsPdo, 'TblContacts');
        }

        $syncStartedAt = date('Y-m-d H:i:s');
        $lastId        = 0;
        $totalFetched  = 0;
        $totalInserted = 0;
        $processedCids = [];
        $seenTpIds     = [];

        // ── Fetch + insert loop ───────────────────────────────────────────────
        do {
            $chunk     = $this->fetchPage($snowflake, $startDate, $lastId);
            $chunkSize = count($chunk);

            if ($chunkSize === 0) {
                break;
            }

            $totalFetched += $chunkSize;
            $lastId        = (int) end($chunk)['CONTACT_ID'];

            $rows = $this->processChunk($ccsPdo, $chunk, $seenTpIds);

            // Collect CIDs only for incremental copy (full refresh uses OFFSET/FETCH)
            if ($isIncremental) {
                foreach ($rows as $r) {
                    $processedCids[] = $r['cid'];
                }
            }

            try {
                $totalInserted += $this->insertChunk($ccsPdo, $rows, $isIncremental);
            } catch (\Throwable $e) {
                $this->error("[ERROR] Insert failed on chunk ending at ID {$lastId}: " . $e->getMessage());
                Log::error('SyncContactsCCS: chunk insert failed', [
                    'last_id' => $lastId,
                    'error'   => $e->getMessage(),
                ]);
                return Command::FAILURE;
            }

            unset($chunk, $rows);
            gc_collect_cycles();

            $this->info("[INFO] Progress: {$totalFetched} fetched, {$totalInserted} upserted...");
        } while ($chunkSize === self::PAGE_SIZE);

        $this->info("[INFO] Completed: {$totalInserted} records upserted into TblContacts (CCS).");

        // ── Copy to LDR TblContactsCCS ────────────────────────────────────────
        $this->info('[INFO] Copying to LDR TblContactsCCS...');
        try {
            $this->copyToLdr($ccsPdo, $ldrPdo, $isIncremental, $processedCids);
        } catch (\Throwable $e) {
            $this->error('[ERROR] Copy to LDR TblContactsCCS failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->writeLastSyncTime($syncStartedAt);
        $this->info("[INFO] Sync watermark saved: {$syncStartedAt}");
        $this->info('[SUCCESS] SyncContactsCCS completed successfully!');
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // CCS SQL Server connection
    // -------------------------------------------------------------------------

    private function initializeCcsPdo(): PDO
    {
        $host     = env('CCS_DB_HOST', '');
        $port     = env('CCS_DB_PORT', '1433');
        $database = env('CCS_DB_DATABASE', '');
        $username = env('CCS_DB_USERNAME', '');
        $password = env('CCS_DB_PASSWORD', '');

        if (empty($host) || empty($database)) {
            throw new \RuntimeException(
                'CCS SQL Server credentials are not configured. Set CCS_DB_HOST and CCS_DB_DATABASE in .env.'
            );
        }

        $dsn = "sqlsrv:Server={$host},{$port};Database={$database};TrustServerCertificate=true";
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    // -------------------------------------------------------------------------
    // Per-chunk lookup fetchers (campaign matching)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{drop_name: string, external_id: string}>
     */
    private function fetchOverridesFiltered(PDO $ccsPdo, array $cids): array
    {
        $cids = array_values(array_unique(array_filter(array_map('strval', $cids), fn($v) => $v !== '')));
        if (empty($cids)) {
            return [];
        }

        $ccsPdo->exec("CREATE TABLE #TmpOverrideFilter (CID VARCHAR(50))");
        foreach (array_chunk($cids, 1000) as $batch) {
            $values = implode(', ', array_map(
                fn($id) => "('" . $this->escSql($id) . "')",
                $batch
            ));
            $ccsPdo->exec("INSERT INTO #TmpOverrideFilter VALUES {$values}");
        }

        $stmt = $ccsPdo->query("
            SELECT o.CID, o.Drop_Name, o.External_ID
            FROM TblEnrollmentOverrides o
            JOIN #TmpOverrideFilter f ON o.CID = f.CID
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ccsPdo->exec("DROP TABLE #TmpOverrideFilter");

        $map = [];
        foreach ($rows as $row) {
            $cid = (string) ($row['CID'] ?? '');
            if ($cid === '') {
                continue;
            }
            $map[$cid] = [
                'drop_name'   => (string) ($row['Drop_Name'] ?? ''),
                'external_id' => (string) ($row['External_ID'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * @return array<string, string>  ExternalID => Drop_Name
     */
    private function fetchMailersByExtIdFiltered(PDO $ccsPdo, array $externalIds): array
    {
        $externalIds = array_values(array_unique(array_filter(
            array_map(fn($v) => trim((string) $v), $externalIds),
            fn($v) => $v !== ''
        )));
        if (empty($externalIds)) {
            return [];
        }

        $ccsPdo->exec("CREATE TABLE #TmpMailerExtFilter (ExtId VARCHAR(50))");
        foreach (array_chunk($externalIds, 1000) as $batch) {
            $values = implode(', ', array_map(
                fn($id) => "('" . $this->escSql(substr($id, 0, 50)) . "')",
                $batch
            ));
            $ccsPdo->exec("INSERT INTO #TmpMailerExtFilter VALUES {$values}");
        }

        $stmt = $ccsPdo->query("
            SELECT m.External_ID, m.Drop_Name
            FROM TblMailers m
            JOIN #TmpMailerExtFilter f ON m.External_ID = f.ExtId
            WHERE m.External_ID IS NOT NULL AND m.Drop_Name IS NOT NULL
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ccsPdo->exec("DROP TABLE #TmpMailerExtFilter");

        $map = [];
        foreach ($rows as $row) {
            $ext = (string) ($row['External_ID'] ?? '');
            $drop = (string) ($row['Drop_Name'] ?? '');
            if ($ext !== '' && $drop !== '' && !isset($map[$ext])) {
                $map[$ext] = $drop;
            }
        }
        return $map;
    }

    /**
     * @return array<string, string>  Address => Drop_Name
     */
    private function fetchMailersByAddressFiltered(PDO $ccsPdo, array $addresses): array
    {
        $addresses = array_values(array_unique(array_filter(
            array_map(fn($v) => trim((string) $v), $addresses),
            fn($v) => $v !== ''
        )));
        if (empty($addresses)) {
            return [];
        }

        $ccsPdo->exec("CREATE TABLE #TmpMailerAddrFilter (Address VARCHAR(255))");
        foreach (array_chunk($addresses, 1000) as $batch) {
            $values = implode(', ', array_map(
                fn($a) => "('" . $this->escSql(substr($a, 0, 255)) . "')",
                $batch
            ));
            $ccsPdo->exec("INSERT INTO #TmpMailerAddrFilter VALUES {$values}");
        }

        $stmt = $ccsPdo->query("
            SELECT m.Address, m.Drop_Name
            FROM TblMailers m
            JOIN #TmpMailerAddrFilter f ON m.Address = f.Address
            WHERE m.Address IS NOT NULL AND m.Drop_Name IS NOT NULL
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ccsPdo->exec("DROP TABLE #TmpMailerAddrFilter");

        $map = [];
        foreach ($rows as $row) {
            $addr = (string) ($row['Address'] ?? '');
            $drop = (string) ($row['Drop_Name'] ?? '');
            if ($addr !== '' && $drop !== '' && !isset($map[$addr])) {
                $map[$addr] = $drop;
            }
        }
        return $map;
    }

    // -------------------------------------------------------------------------
    // Snowflake fetch
    // -------------------------------------------------------------------------

    private function fetchPage(DBConnector $snowflake, string $startDate, int $lastId): array
    {
        $result = $snowflake->query($this->buildQuery($startDate, $lastId, self::PAGE_SIZE));
        return $result['data'] ?? [];
    }

    private function buildQuery(string $startDate, int $lastId, int $limit): string
    {
        $cfLoan     = self::CF_LOAN_AMOUNT_NEEDED;
        $cfEnrolled = self::CF_ENROLLMENT_DATE;
        $cfDropped  = self::CF_DROPPED_DATE;
        $cfPayments = self::CF_PAYMENTS;
        $cfFpc      = self::CF_FPC_DATE;
        $cfFpr      = self::CF_FPR_DATE;
        $cfFpa      = self::CF_FIRST_PAYMENT_AMOUNT;
        $cfFreq     = self::CF_PAYMENT_FREQUENCY;
        $start      = $this->esc($startDate);

        return "
            SELECT
                TO_CHAR(TIMEADD(hour, -7, c.CREATED), 'YYYY-MM-DD HH24:MI:SS')                                  AS CREATED,
                TO_CHAR(a.STAMP, 'YYYY-MM-DD HH24:MI:SS')                                                        AS ASSIGNED_ON,
                TO_CHAR(TIMEADD(hour, -7, COALESCE(c.MODIFIED, a.STAMP, c.CREATED)), 'YYYY-MM-DD HH24:MI:SS')   AS MODIFIED,
                c.ID                                                                                              AS CONTACT_ID,
                c.TP_ID                                                                                           AS EXTERNAL_ID,
                ds.NAME                                                                                           AS DATA_SOURCE,
                CONCAT(u1.FIRSTNAME, ' ', u1.LASTNAME)                                                           AS CREATED_BY,
                CONCAT(u2.FIRSTNAME, ' ', u2.LASTNAME)                                                           AS ASSIGNED_TO,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME)                                                             AS FULLNAME,
                c.PHONE3                                                                                          AS CELL_PHONE,
                c.EMAIL,
                c.ADDRESS                                                                                         AS ADDRESS1,
                c.ADDRESS2,
                c.CITY,
                c.STATE,
                c.ZIP,
                cc.TITLE                                                                                          AS STAGE,
                cls.TITLE                                                                                         AS STATUS,
                cs.TRANSUNION                                                                                     AS CREDIT_SCORE,
                SUBSTRING(cr.METADATA,
                    CHARINDEX('RevolvingCreditUtilization', cr.METADATA) + 29,
                    CHARINDEX('Day30', cr.METADATA) - CHARINDEX('RevolvingCreditUtilization', cr.METADATA) - 32
                )                                                                                                 AS CREDIT_UTILIZATION,
                d.ENROLLED_DEBT,
                uf_loan.F_DECIMAL                                                                                 AS LOAN_AMOUNT_NEEDED,
                TO_CHAR(uf_enrolled.F_DATE, 'YYYY-MM-DD HH24:MI:SS')                                            AS ENROLLMENT_DATE,
                TO_CHAR(uf_dropped.F_DATE,  'YYYY-MM-DD HH24:MI:SS')                                            AS DROPPED_DATE,
                uf_payments.F_NUMERIC                                                                             AS PAYMENTS,
                TO_CHAR(uf_fpc.F_DATE, 'YYYY-MM-DD HH24:MI:SS')                                                 AS FPC_DATE,
                TO_CHAR(uf_fpr.F_DATE, 'YYYY-MM-DD HH24:MI:SS')                                                 AS FPR_DATE,
                uf_fpa.F_DECIMAL                                                                                  AS FIRST_PAYMENT_AMOUNT,
                uf_freq.F_SHORTSTRING                                                                             AS PAYMENT_FREQUENCY,
                ed.TITLE                                                                                          AS PLAN_TITLE
            FROM CONTACTS AS c
            LEFT JOIN CONTACTS_ASSIGNED AS a       ON c.ID = a.CONTACT_ID
            LEFT JOIN DATA_SOURCES AS ds           ON c.C_SOURCE = ds.ID
            LEFT JOIN USERS AS u1                  ON c.CREATED_BY = u1.UID
            LEFT JOIN USERS AS u2                  ON c.ASSIGNED_TO = u2.UID
            LEFT JOIN CONTACTS_STATUS AS s         ON c.ID = s.CONTACT_ID
            LEFT JOIN CONTACTS_CATEGORIES AS cc    ON s.STAGE_ID = cc.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls  ON s.STATUS_ID = cls.ID
            LEFT JOIN CREDIT_SCORES AS cs          ON c.ID = cs.CONTACT_ID
            LEFT JOIN CREDIT_REPORT_REQUEST AS cr  ON c.ID = cr.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                FROM DEBTS
                WHERE ENROLLED = 1
                GROUP BY CONTACT_ID
            ) AS d ON c.ID = d.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep        ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed   ON ep.PLAN_ID = ed.ID
            LEFT JOIN (SELECT CONTACT_ID, F_DECIMAL     FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfLoan})     AS uf_loan     ON c.ID = uf_loan.CONTACT_ID
            LEFT JOIN (SELECT CONTACT_ID, F_DATE        FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfEnrolled}) AS uf_enrolled  ON c.ID = uf_enrolled.CONTACT_ID
            LEFT JOIN (SELECT CONTACT_ID, F_DATE        FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfDropped})  AS uf_dropped   ON c.ID = uf_dropped.CONTACT_ID
            LEFT JOIN (SELECT CONTACT_ID, F_NUMERIC     FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfPayments}) AS uf_payments  ON c.ID = uf_payments.CONTACT_ID
            LEFT JOIN (SELECT CONTACT_ID, F_DATE        FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfFpc})      AS uf_fpc       ON c.ID = uf_fpc.CONTACT_ID
            LEFT JOIN (SELECT CONTACT_ID, F_DATE        FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfFpr})      AS uf_fpr       ON c.ID = uf_fpr.CONTACT_ID
            LEFT JOIN (SELECT CONTACT_ID, F_DECIMAL     FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfFpa})      AS uf_fpa       ON c.ID = uf_fpa.CONTACT_ID
            LEFT JOIN (SELECT CONTACT_ID, F_SHORTSTRING FROM CONTACTS_USERFIELDS WHERE CUSTOM_ID = {$cfFreq})     AS uf_freq      ON c.ID = uf_freq.CONTACT_ID
            WHERE UPPER(ds.NAME) LIKE 'FF-%'
              AND COALESCE(c.FIRSTNAME, '') <> ''
              AND COALESCE(c.MODIFIED, a.STAMP, c.CREATED) >= DATEADD(hour, 7, '{$start}'::TIMESTAMP_NTZ)
              AND c.ID > {$lastId}
            QUALIFY ROW_NUMBER() OVER(PARTITION BY c.ID ORDER BY s.STAMP DESC) = 1
            ORDER BY c.ID
            LIMIT {$limit}
        ";
    }

    // -------------------------------------------------------------------------
    // Processing
    // -------------------------------------------------------------------------

    private function processChunk(PDO $ccsPdo, array $chunk, array &$seenTpIds): array
    {
        // ── First pass: gather CIDs and apply TP_ID dedup + test-email filter ─────
        $candidates = [];
        $cids       = [];

        foreach ($chunk as $row) {
            if (($row['EMAIL'] ?? '') === 'testing@example.com') {
                continue;
            }

            $tpId = trim((string) ($row['EXTERNAL_ID'] ?? ''));
            if ($tpId !== '' && isset($seenTpIds[$tpId])) {
                continue;
            }
            if ($tpId !== '') {
                $seenTpIds[$tpId] = true;
            }

            $cid = (string) ($row['CONTACT_ID'] ?? '');
            if ($cid === '') {
                continue;
            }

            $candidates[] = $row;
            $cids[]       = $cid;
        }

        if (empty($candidates)) {
            return [];
        }

        // ── Step 1: TblEnrollmentOverrides by CID — overrides External_ID + Campaign ─
        $overrides = $this->fetchOverridesFiltered($ccsPdo, $cids);

        // Resolve the effective External_ID for each candidate (override wins).
        // Collect distinct ExtIDs needed for the step-2 mailer lookup.
        $effectiveExtIds = [];
        $extIdsForLookup = [];
        foreach ($candidates as $idx => $row) {
            $cid          = (string) ($row['CONTACT_ID'] ?? '');
            $rawExtId     = substr(trim((string) ($row['EXTERNAL_ID'] ?? '')), 0, 50);
            $effective    = isset($overrides[$cid]) ? substr((string) $overrides[$cid]['external_id'], 0, 50) : $rawExtId;
            $effectiveExtIds[$idx] = $effective;
            if ($effective !== '') {
                $extIdsForLookup[$effective] = true;
            }
        }

        // ── Step 2: TblMailers by External_ID ────────────────────────────────────
        $mailersByExt = $this->fetchMailersByExtIdFiltered($ccsPdo, array_keys($extIdsForLookup));

        // ── Step 3 prep: collect Address_1 values for rows that still need fallback ─
        $addressesForLookup = [];
        foreach ($candidates as $idx => $row) {
            $cid       = (string) ($row['CONTACT_ID'] ?? '');
            $effExt    = $effectiveExtIds[$idx];
            $haveCampaign =
                (isset($overrides[$cid]) && $overrides[$cid]['drop_name'] !== '')
                || ($effExt !== '' && isset($mailersByExt[$effExt]));

            if ($haveCampaign) {
                continue;
            }

            $dataSource = (string) ($row['DATA_SOURCE'] ?? '');
            if (stripos($dataSource, 'non-mailer') !== false) {
                continue;
            }

            $address = substr(trim((string) ($row['ADDRESS1'] ?? '')), 0, 255);
            if ($address !== '') {
                $addressesForLookup[$address] = true;
            }
        }

        $mailersByAddress = $this->fetchMailersByAddressFiltered($ccsPdo, array_keys($addressesForLookup));

        // ── Final assembly with resolved campaign + final external_id ────────────
        $processed = [];
        foreach ($candidates as $idx => $row) {
            $cid       = (string) ($row['CONTACT_ID'] ?? '');
            $rawExtId  = substr(trim((string) ($row['EXTERNAL_ID'] ?? '')), 0, 50);
            $effExtId  = $effectiveExtIds[$idx];

            $campaign  = '';
            $finalExt  = $rawExtId;

            if (isset($overrides[$cid])) {
                $campaign = (string) $overrides[$cid]['drop_name'];
                $finalExt = substr((string) $overrides[$cid]['external_id'], 0, 50);
            }

            if ($campaign === '' && $effExtId !== '' && isset($mailersByExt[$effExtId])) {
                $campaign = $mailersByExt[$effExtId];
            }

            if ($campaign === '') {
                $dataSource = (string) ($row['DATA_SOURCE'] ?? '');
                if (stripos($dataSource, 'non-mailer') === false) {
                    $address = substr(trim((string) ($row['ADDRESS1'] ?? '')), 0, 255);
                    if ($address !== '' && isset($mailersByAddress[$address])) {
                        $campaign = $mailersByAddress[$address];
                    }
                }
            }

            $loanNeeded   = (float) ($row['LOAN_AMOUNT_NEEDED'] ?? 0);
            $enrolledDebt = (float) ($row['ENROLLED_DEBT'] ?? 0);

            // Prefer LOAN_AMOUNT_NEEDED; fall back to ENROLLED_DEBT if zero or implausibly large
            $debtRaw    = ($loanNeeded <= 0 || $loanNeeded > 999999) ? $enrolledDebt : $loanNeeded;
            $debtAmount = (int) (floor($debtRaw / 1000) * 1000);

            $processed[] = [
                'cid'                        => $cid,
                'external_id'                => $finalExt,
                'campaign'                   => substr($campaign, 0, 255),
                'created_date'               => $this->formatDate($row['CREATED'] ?? null),
                'assigned_date'              => $this->formatDate($row['ASSIGNED_ON'] ?? null),
                'data_source'                => substr($row['DATA_SOURCE'] ?? '', 0, 255),
                'created_by'                 => substr($row['CREATED_BY'] ?? '', 0, 255),
                'agent'                      => substr($row['ASSIGNED_TO'] ?? '', 0, 255),
                'client'                     => substr($row['FULLNAME'] ?? '', 0, 255),
                'phone'                      => substr($this->cleanPhone($row['CELL_PHONE'] ?? ''), 0, 50),
                'email'                      => $row['EMAIL'] ?? '',
                'address_1'                  => substr($row['ADDRESS1'] ?? '', 0, 255),
                'address_2'                  => substr($row['ADDRESS2'] ?? '', 0, 255),
                'city'                       => substr($row['CITY'] ?? '', 0, 100),
                'state'                      => substr($row['STATE'] ?? '', 0, 20),
                'zip'                        => substr($row['ZIP'] ?? '', 0, 20),
                'stage'                      => $row['STAGE'] ?? '',
                'status'                     => $row['STATUS'] ?? '',
                'debt_amount'                => $debtAmount,
                'debt_enrolled'              => $enrolledDebt,
                'credit_score'               => (int) ($row['CREDIT_SCORE'] ?? 0),
                'credit_utilization'         => $this->parseCreditUtilization($row['CREDIT_UTILIZATION'] ?? ''),
                'enrolled_date'              => $this->formatDate($row['ENROLLMENT_DATE'] ?? null),
                'dropped_date'               => $this->formatDate($row['DROPPED_DATE'] ?? null),
                'payments'                   => (int) ($row['PAYMENTS'] ?? 0),
                'enrollment_plan'            => substr($row['PLAN_TITLE'] ?? '', 0, 255),
                'first_payment_cleared_date' => $this->formatDate($row['FPC_DATE'] ?? null),
                'first_payment_returned_date' => $this->formatDate($row['FPR_DATE'] ?? null),
                'first_payment_amount'       => (float) ($row['FIRST_PAYMENT_AMOUNT'] ?? 0),
                'payment_frequency'          => substr($row['PAYMENT_FREQUENCY'] ?? '', 0, 50),
            ];
        }

        return $processed;
    }

    // -------------------------------------------------------------------------
    // Insertion into CCS TblContacts
    // -------------------------------------------------------------------------

    private function insertChunk(PDO $pdo, array $data, bool $incremental): int
    {
        if (empty($data)) {
            return 0;
        }

        $fields = 'Created_Date, Assigned_Date, CID, External_ID, Campaign, Data_Source, '
            . 'Created_By, Agent, Client, Phone, Email, Address_1, Address_2, City, State, '
            . 'Zip, Stage, Status, Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, '
            . 'Enrolled_Date, Dropped_Date, Payments, Enrollment_Plan, '
            . 'First_Payment_Cleared_Date, First_Payment_Returned_Date, First_Payment_Amount, Payment_Frequency';

        $pdo->beginTransaction();
        try {
            if ($incremental) {
                foreach (array_chunk($data, 1000) as $deleteBatch) {
                    $ids = implode(', ', array_map(
                        fn($r) => "'" . $this->escSql($r['cid']) . "'",
                        $deleteBatch
                    ));
                    $pdo->exec("DELETE FROM TblContacts WHERE CID IN ({$ids})");
                }
            }

            foreach (array_chunk($data, 1000) as $batch) {
                $valuesParts = [];
                foreach ($batch as $row) {
                    $createdDate  = $row['created_date']               ? "'{$row['created_date']}'"               : 'NULL';
                    $assignedDate = $row['assigned_date']              ? "'{$row['assigned_date']}'"              : 'NULL';
                    $enrolledDate = $row['enrolled_date']              ? "'{$row['enrolled_date']}'"              : 'NULL';
                    $droppedDate  = $row['dropped_date']               ? "'{$row['dropped_date']}'"               : 'NULL';
                    $fpcDate      = $row['first_payment_cleared_date'] ? "'{$row['first_payment_cleared_date']}'" : 'NULL';
                    $fprDate      = $row['first_payment_returned_date'] ? "'{$row['first_payment_returned_date']}'" : 'NULL';
                    $email        = strpos($row['email'], '@') !== false
                        ? "'" . $this->escSql($row['email']) . "'"
                        : 'NULL';

                    $valuesParts[] = "({$createdDate}, {$assignedDate}, "
                        . "'{$this->escSql($row['cid'])}', "
                        . "'{$this->escSql($row['external_id'])}', "
                        . "'{$this->escSql($row['campaign'])}', "
                        . "'{$this->escSql($row['data_source'])}', "
                        . "'{$this->escSql($row['created_by'])}', "
                        . "'{$this->escSql($row['agent'])}', "
                        . "'{$this->escSql($row['client'])}', "
                        . "'{$this->escSql($row['phone'])}', "
                        . "{$email}, "
                        . "'{$this->escSql($row['address_1'])}', "
                        . "'{$this->escSql($row['address_2'])}', "
                        . "'{$this->escSql($row['city'])}', "
                        . "'{$this->escSql($row['state'])}', "
                        . "'{$this->escSql($row['zip'])}', "
                        . "'{$this->escSql($row['stage'])}', "
                        . "'{$this->escSql($row['status'])}', "
                        . ((int) $row['debt_amount']) . ', '
                        . ((float) $row['debt_enrolled']) . ', '
                        . ((int) $row['credit_score']) . ', '
                        . ((int) $row['credit_utilization']) . ', '
                        . "{$enrolledDate}, {$droppedDate}, "
                        . ((int) $row['payments']) . ', '
                        . "'{$this->escSql($row['enrollment_plan'])}', "
                        . "{$fpcDate}, {$fprDate}, "
                        . ((float) $row['first_payment_amount']) . ', '
                        . "'{$this->escSql($row['payment_frequency'])}')";
                }

                $pdo->exec(
                    "INSERT INTO TblContacts ({$fields}) VALUES " . implode(', ', $valuesParts)
                );
            }

            $pdo->commit();
            return count($data);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Copy CCS TblContacts → LDR TblContactsCCS
    // -------------------------------------------------------------------------

    private function copyToLdr(PDO $ccsPdo, PDO $ldrPdo, bool $incremental, array $processedCids): void
    {
        $fields = 'Created_Date, Assigned_Date, CID, External_ID, Campaign, Data_Source, '
            . 'Created_By, Agent, Client, Phone, Email, Address_1, Address_2, City, State, '
            . 'Zip, Stage, Status, Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, '
            . 'Enrolled_Date, Dropped_Date, Payments, Enrollment_Plan, '
            . 'First_Payment_Cleared_Date, First_Payment_Returned_Date, First_Payment_Amount, Payment_Frequency';

        if (!$incremental) {
            // Full refresh: truncate LDR table, then page through CCS using cursor
            // pagination on CID. OFFSET would re-scan all prior rows each batch
            // (quadratic on 360K+ rows); WHERE CID > $lastCid is constant per batch.
            $this->clearTable($ldrPdo, 'TblContactsCCS');

            $lastCid     = 0;
            $totalCopied = 0;
            $pageSize    = self::COPY_PAGE_SIZE;

            do {
                $stmt = $ccsPdo->query("
                    SELECT TOP ({$pageSize}) {$fields}
                    FROM TblContacts
                    WHERE CAST(CID AS BIGINT) > {$lastCid}
                    ORDER BY CAST(CID AS BIGINT)
                ");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($rows)) {
                    break;
                }

                $this->insertBatch($ldrPdo, 'TblContactsCCS', $fields, $rows);
                $totalCopied += count($rows);
                $lastCid      = (int) end($rows)['CID'];
                $this->info("[INFO] LDR copy progress: {$totalCopied} rows...");
            } while (count($rows) === $pageSize);

            $this->info("[INFO] Full copy to TblContactsCCS complete: {$totalCopied} rows.");
            return;
        }

        // Incremental: delete matching CIDs from LDR, then re-copy from CCS
        if (empty($processedCids)) {
            $this->info('[INFO] No CIDs to copy to LDR (incremental, nothing changed).');
            return;
        }

        foreach (array_chunk($processedCids, 1000) as $cidBatch) {
            $ids = implode(', ', array_map(fn($id) => "'" . $this->escSql($id) . "'", $cidBatch));
            $ldrPdo->exec("DELETE FROM TblContactsCCS WHERE CID IN ({$ids})");
        }

        $totalCopied = 0;
        foreach (array_chunk($processedCids, 1000) as $cidBatch) {
            $ids  = implode(', ', array_map(fn($id) => "'" . $this->escSql($id) . "'", $cidBatch));
            $stmt = $ccsPdo->query("SELECT {$fields} FROM TblContacts WHERE CID IN ({$ids})");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                continue;
            }

            $this->insertBatch($ldrPdo, 'TblContactsCCS', $fields, $rows);
            $totalCopied += count($rows);
        }

        $this->info("[INFO] Incremental copy to TblContactsCCS complete: {$totalCopied} rows.");
    }

    // Inserts rows (as associative arrays from SQL Server SELECT) into $table on $pdo.
    // Column names in $rows are the real SQL Server column names (e.g. 'Created_Date').
    private function insertBatch(PDO $pdo, string $table, string $fields, array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $batch) {
            $valuesParts = [];

            foreach ($batch as $row) {
                $createdDate  = !empty($row['Created_Date'])               ? "'" . $this->escSql($row['Created_Date']) . "'"               : 'NULL';
                $assignedDate = !empty($row['Assigned_Date'])              ? "'" . $this->escSql($row['Assigned_Date']) . "'"              : 'NULL';
                $enrolledDate = !empty($row['Enrolled_Date'])              ? "'" . $this->escSql($row['Enrolled_Date']) . "'"              : 'NULL';
                $droppedDate  = !empty($row['Dropped_Date'])               ? "'" . $this->escSql($row['Dropped_Date']) . "'"               : 'NULL';
                $fpcDate      = !empty($row['First_Payment_Cleared_Date']) ? "'" . $this->escSql($row['First_Payment_Cleared_Date']) . "'" : 'NULL';
                $fprDate      = !empty($row['First_Payment_Returned_Date']) ? "'" . $this->escSql($row['First_Payment_Returned_Date']) . "'" : 'NULL';
                $email        = strpos((string) ($row['Email'] ?? ''), '@') !== false
                    ? "'" . $this->escSql($row['Email']) . "'"
                    : 'NULL';

                $valuesParts[] = "({$createdDate}, {$assignedDate}, "
                    . "'{$this->escSql($row['CID'] ?? '')}', "
                    . "'{$this->escSql($row['External_ID'] ?? '')}', "
                    . "'{$this->escSql($row['Campaign'] ?? '')}', "
                    . "'{$this->escSql($row['Data_Source'] ?? '')}', "
                    . "'{$this->escSql($row['Created_By'] ?? '')}', "
                    . "'{$this->escSql($row['Agent'] ?? '')}', "
                    . "'{$this->escSql($row['Client'] ?? '')}', "
                    . "'{$this->escSql($row['Phone'] ?? '')}', "
                    . "{$email}, "
                    . "'{$this->escSql($row['Address_1'] ?? '')}', "
                    . "'{$this->escSql($row['Address_2'] ?? '')}', "
                    . "'{$this->escSql($row['City'] ?? '')}', "
                    . "'{$this->escSql($row['State'] ?? '')}', "
                    . "'{$this->escSql($row['Zip'] ?? '')}', "
                    . "'{$this->escSql($row['Stage'] ?? '')}', "
                    . "'{$this->escSql($row['Status'] ?? '')}', "
                    . ((int) ($row['Debt_Amount'] ?? 0)) . ', '
                    . ((float) ($row['Debt_Enrolled'] ?? 0)) . ', '
                    . ((int) ($row['Credit_Score'] ?? 0)) . ', '
                    . ((int) ($row['Credit_Utilization'] ?? 0)) . ', '
                    . "{$enrolledDate}, {$droppedDate}, "
                    . ((int) ($row['Payments'] ?? 0)) . ', '
                    . "'{$this->escSql($row['Enrollment_Plan'] ?? '')}', "
                    . "{$fpcDate}, {$fprDate}, "
                    . ((float) ($row['First_Payment_Amount'] ?? 0)) . ', '
                    . "'{$this->escSql($row['Payment_Frequency'] ?? '')}')";
            }

            $pdo->exec("INSERT INTO {$table} ({$fields}) VALUES " . implode(', ', $valuesParts));
        }
    }

    // -------------------------------------------------------------------------
    // Table management
    // -------------------------------------------------------------------------

    private function clearTable(PDO $pdo, string $table): void
    {
        try {
            $pdo->exec("TRUNCATE TABLE {$table}");
            $this->info("[INFO] {$table} truncated.");
        } catch (\Throwable $e) {
            $this->info("[INFO] TRUNCATE failed ({$e->getMessage()}), falling back to batch DELETE.");
            do {
                $pdo->exec("DELETE TOP (50000) FROM {$table}");
                $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            } while ($count > 0);
            $this->info("[INFO] {$table} cleared.");
        }
    }

    // -------------------------------------------------------------------------
    // Watermark
    // -------------------------------------------------------------------------

    private function readLastSyncTime(): ?string
    {
        $path = storage_path('app/sync_timestamps.json');
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return $data['CCS'] ?? null;
    }

    private function writeLastSyncTime(string $datetime): void
    {
        $path = storage_path('app/sync_timestamps.json');
        $data = file_exists($path)
            ? (json_decode(file_get_contents($path), true) ?? [])
            : [];
        $data['CCS'] = $datetime;
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function formatDate($value): string
    {
        if (empty($value)) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && strlen($value) >= 10 && $value[4] === '-' && $value[7] === '-') {
            return substr($value, 0, 19);
        }
        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function parseCreditUtilization(string $value): int
    {
        if (empty($value)) {
            return 0;
        }
        if (preg_match('/^\s*([\d.]+)/', $value, $matches)) {
            $util = (float) $matches[1];
            if ($util < 1) {
                $util *= 100;
            }
            return (int) $util;
        }
        return 0;
    }

    private function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    private function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function escSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
