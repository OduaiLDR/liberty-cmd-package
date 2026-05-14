<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SyncContactsData extends Command
{
    protected $signature = 'Sync:contacts-data
        {--source= : Run a single source only (LDR, PLAW, or LT)}
        {--full    : Force a full refresh even when a previous sync timestamp exists}';

    protected $description = 'Sync contacts data from Snowflake to SQL Server (TblContactsLDR, TblContactsPLAW, and TblContactsLT)';

    private const PAGE_SIZE = 50000;

    private string $source;
    private int $debtAmountCustomId;
    private int $agentCustomId;
    private string $targetTable;

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $source = strtoupper((string) $this->option('source'));
        if ($source !== '') {
            if (!in_array($source, ['LDR', 'PLAW', 'LT'], true)) {
                $this->error("Unknown source '{$source}'. Use LDR, PLAW, or LT.");
                return Command::FAILURE;
            }
            return $this->syncForSource($source);
        }

        $this->info("[INFO] Sync Contacts Data: starting LDR, PLAW, and LT in parallel.");

        $php = PHP_BINARY;
        if (str_contains(basename($php), 'fpm')) {
            $cli = trim((string) shell_exec('which php8.3 2>/dev/null || which php8.2 2>/dev/null || which php 2>/dev/null'));
            $php = $cli ?: 'php';
        }
        $artisan = base_path('artisan');
        $sources = ['LDR', 'PLAW', 'LT'];
        $results = [];

        $pool = Process::pool(function ($pool) use ($php, $artisan, $sources) {
            foreach ($sources as $source) {
                $pool->as($source)->timeout(7200)->command([$php, $artisan, 'Sync:contacts-data', "--source={$source}"]);
            }
        })->start(function (string $type, string $output, string $key) {
            foreach (explode("\n", rtrim($output)) as $line) {
                if ($line !== '') {
                    $this->line("[{$key}] {$line}");
                }
            }
        });

        $processes = $pool->wait();

        $this->info("\n" . str_repeat('=', 80));
        $allOk = true;
        foreach ($sources as $source) {
            $exitCode         = $processes[$source]->exitCode();
            $results[$source] = $exitCode === 0;
            if ($exitCode !== 0) {
                $this->error("[ERROR] {$source} failed (exit code {$exitCode}).");
                $allOk = false;
            }
        }

        if ($allOk) {
            $this->info('[SUCCESS] All syncs (LDR, PLAW, LT) completed successfully!');
            return Command::SUCCESS;
        }

        $this->error('[ERROR] One or more syncs failed. Check logs for details.');
        return Command::FAILURE;
    }

    private function syncForSource(string $source): int
    {
        $this->source = $source;
        $this->info("[INFO] Sync Contacts Data: starting for {$this->source}.");

        if ($this->source === 'PLAW') {
            $this->debtAmountCustomId = 743019;
            $this->agentCustomId      = 742153;
            $this->targetTable        = 'TblContactsPLAW';
        } elseif ($this->source === 'LT') {
            $this->debtAmountCustomId = 387843;  // loan amount needed field
            $this->agentCustomId      = 0;        // agent comes from USERS join, not custom field
            $this->targetTable        = 'TblContacts';
        } else {
            $this->debtAmountCustomId = 745839;
            $this->agentCustomId      = 742152;
            $this->targetTable        = 'TblContactsLDR';
        }

        $this->info("[DEBUG] Initializing Snowflake connector...");
        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('SyncContactsData: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }
        $this->info("[DEBUG] Snowflake connector OK.");

        $this->info("[DEBUG] Initializing SQL Server connector...");
        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncContactsData: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }
        $this->info("[DEBUG] SQL Server connector OK.");

        // Determine sync mode.
        // Incremental: fetch only contacts modified since the last successful run,
        //   then DELETE+INSERT per chunk (no truncate — existing unchanged rows stay).
        // Full refresh: truncate the table and re-sync everything since 2021-07-01.
        $lastSyncAt    = $this->option('full') ? null : $this->readLastSyncTime($this->source);
        $isIncremental = $lastSyncAt !== null;

        if ($isIncremental) {
            // Subtract 10 minutes as a safety buffer against clock skew / in-flight writes
            // that were being saved during the previous run's window.
            $startDate = date('Y-m-d H:i:s', strtotime($lastSyncAt) - 600);
            $this->info("[INFO] Incremental mode: fetching contacts modified since {$startDate}.");
        } else {
            $startDate = '2021-07-01';
            $this->info("[INFO] Full refresh mode.");
            $this->clearTargetTable($sqlConnector);
        }

        // Record the sync start time before any data is fetched.
        // This timestamp is written to the file only after the entire run succeeds,
        // ensuring a failed/partial run never advances the watermark.
        $syncStartedAt = date('Y-m-d H:i:s');

        $lastId           = 0;
        $seenTpIds        = [];
        $categoryChanges  = [];
        $affiliateChanges = [];
        $totalFetched     = 0;
        $totalInserted    = 0;

        do {
            $chunk     = $this->fetchContactsPage($snowflake, $startDate, $lastId, self::PAGE_SIZE);
            $chunkSize = \count($chunk);

            if ($chunkSize === 0) {
                break;
            }

            $totalFetched += $chunkSize;
            $lastId        = (int) end($chunk)['LLG_ID'];

            $enrollmentData = $this->loadEnrollmentDataFiltered($sqlConnector, $chunk);
            $dropNames      = $this->fetchDropNamesFiltered($sqlConnector, $chunk);

            [$processedChunk, $newCatChanges, $newAffChanges] = $this->processChunk(
                $chunk,
                $dropNames,
                $enrollmentData,
                $seenTpIds
            );

            foreach ($newCatChanges as $c) {
                $categoryChanges[] = $c;
            }
            foreach ($newAffChanges as $c) {
                $affiliateChanges[] = $c;
            }

            try {
                $totalInserted += $this->insertChunk($sqlConnector, $processedChunk, $isIncremental);
            } catch (\Throwable $e) {
                $this->error("[ERROR] Insert failed on chunk ending at ID {$lastId}: " . $e->getMessage());
                Log::error('SyncContactsData: chunk insert failed', [
                    'source'  => $this->source,
                    'last_id' => $lastId,
                    'error'   => $e->getMessage(),
                ]);
                return Command::FAILURE;
            }

            unset($chunk, $enrollmentData, $dropNames, $processedChunk, $newCatChanges, $newAffChanges);
            \gc_collect_cycles();

            $this->info("[INFO] Progress: {$totalFetched} fetched, {$totalInserted} upserted...");
        } while ($chunkSize === self::PAGE_SIZE);

        $this->info("[INFO] Completed: {$totalInserted} records upserted into {$this->targetTable}.");
        $this->info("[INFO] Enrollment updates: " . \count($categoryChanges) . " category, " . \count($affiliateChanges) . " affiliate agent");

        $this->applyEnrollmentCategoryUpdates($sqlConnector, $categoryChanges);
        $this->applyEnrollmentAffiliateUpdates($sqlConnector, $affiliateChanges);
        $this->updateRelatedTables($sqlConnector);

        // Persist the watermark only after a fully successful run
        $this->writeLastSyncTime($this->source, $syncStartedAt);
        $this->info("[INFO] Sync watermark saved: {$syncStartedAt}");

        $this->info("[SUCCESS] {$this->source} sync completed successfully!");
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Data fetching
    // -------------------------------------------------------------------------

    /**
     * Fetches one page of contacts from Snowflake using cursor-based pagination on c.ID.
     * Cursor pagination (WHERE c.ID > $lastId) is faster than OFFSET because Snowflake
     * can filter early on the indexed ID column instead of scanning and discarding rows.
     */
    private function fetchContactsPage(
        DBConnector $snowflake,
        string $startDate,
        int $lastId,
        int $limit
    ): array {
        $sql = $this->source === 'LT'
            ? $this->buildLTQuery($startDate, $lastId, $limit)
            : $this->buildStandardQuery($startDate, $lastId, $limit);

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function buildStandardQuery(string $startDate, int $lastId, int $limit): string
    {
        return "
            SELECT
                TIMEADD(hour, -7, c.CREATED) AS CREATED,
                NULL AS ASSIGNED_ON,
                TIMEADD(hour, -7, COALESCE(c.MODIFIED, c.CREATED)) AS MODIFIED,
                c.ID AS LLG_ID,
                c.TP_ID AS EXTERNAL_ID,
                ds.NAME AS DATA_SOURCE,
                NULL AS CREATED_BY,
                NULL AS ASSIGNED_TO,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS FULLNAME,
                c.PHONE3 AS CELL_PHONE,
                c.EMAIL,
                c.ADDRESS AS ADDRESS1,
                c.ADDRESS2,
                c.CITY,
                c.STATE,
                c.ZIP,
                cc.TITLE AS STAGE,
                cls.TITLE AS STATUS,
                NULL AS LOAN_AMOUNT_NEEDED,
                cs.TRANSUNION AS CREDIT_SCORE,
                SUBSTRING(cr.METADATA, CHARINDEX('RevolvingCreditUtilization', cr.METADATA) + 29,
                    CHARINDEX('Day30', cr.METADATA) - CHARINDEX('RevolvingCreditUtilization', cr.METADATA) - 32) AS CREDIT_UTILIZATION,
                ep.FEE1,
                c.TP_ID AS TP_ID_COPY,
                c.ENROLLED_DATE,
                uf_debt.F_DECIMAL AS DEBT_AMOUNT_CUSTOM,
                ed.TITLE AS PLAN_TITLE,
                uf_agent.F_SHORTSTRING AS AGENT_CUSTOM
            FROM CONTACTS AS c
            LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN CONTACTS_STATUS AS s ON c.ID = s.CONTACT_ID
            LEFT JOIN CONTACTS_CATEGORIES AS cc ON s.STAGE_ID = cc.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
            LEFT JOIN CREDIT_SCORES AS cs ON c.ID = cs.CONTACT_ID
            LEFT JOIN CREDIT_REPORT_REQUEST AS cr ON c.ID = cr.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON ep.PLAN_ID = ed.ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_DECIMAL
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = {$this->debtAmountCustomId}
            ) AS uf_debt ON c.ID = uf_debt.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_SHORTSTRING
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = {$this->agentCustomId}
            ) AS uf_agent ON c.ID = uf_agent.CONTACT_ID
            WHERE COALESCE(c.MODIFIED, c.CREATED) >= DATEADD(hour, 7, '{$this->esc($startDate)}'::TIMESTAMP_NTZ)
              AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> ''
              AND ISCOAPP = 0
              AND c.ID > {$lastId}
            QUALIFY ROW_NUMBER() OVER(PARTITION BY c.ID ORDER BY s.STAMP DESC) = 1
            ORDER BY c.ID
            LIMIT {$limit}
        ";
    }

    private function buildLTQuery(string $startDate, int $lastId, int $limit): string
    {
        // LT uses real ASSIGNED_ON/CREATED_BY/ASSIGNED_TO from joined tables.
        // Agent = ASSIGNED_TO (u2), no custom-field agent lookup.
        // Date filter includes a.STAMP. Duplicate leads filtered in Snowflake.
        return "
            SELECT
                TIMEADD(hour, -7, c.CREATED) AS CREATED,
                a.STAMP AS ASSIGNED_ON,
                TIMEADD(hour, -7, COALESCE(c.MODIFIED, a.STAMP)) AS MODIFIED,
                c.ID AS LLG_ID,
                c.TP_ID AS EXTERNAL_ID,
                ds.NAME AS DATA_SOURCE,
                CONCAT(u1.FIRSTNAME, ' ', u1.LASTNAME) AS CREATED_BY,
                CONCAT(u2.FIRSTNAME, ' ', u2.LASTNAME) AS ASSIGNED_TO,
                CONCAT(c.FIRSTNAME, ' ', c.LASTNAME) AS FULLNAME,
                c.PHONE3 AS CELL_PHONE,
                c.EMAIL,
                c.ADDRESS AS ADDRESS1,
                c.ADDRESS2,
                c.CITY,
                c.STATE,
                c.ZIP,
                cc.TITLE AS STAGE,
                cls.TITLE AS STATUS,
                NULL AS LOAN_AMOUNT_NEEDED,
                cs.TRANSUNION AS CREDIT_SCORE,
                SUBSTRING(cr.METADATA, CHARINDEX('RevolvingCreditUtilization', cr.METADATA) + 29,
                    CHARINDEX('Day30', cr.METADATA) - CHARINDEX('RevolvingCreditUtilization', cr.METADATA) - 32) AS CREDIT_UTILIZATION,
                ep.FEE1,
                c.TP_ID AS TP_ID_COPY,
                c.ENROLLED_DATE,
                uf_debt.F_DECIMAL AS DEBT_AMOUNT_CUSTOM,
                NULL AS PLAN_TITLE,
                NULL AS AGENT_CUSTOM
            FROM CONTACTS AS c
            LEFT JOIN CONTACTS_ASSIGNED AS a ON c.ID = a.CONTACT_ID
            LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
            LEFT JOIN USERS AS u1 ON c.CREATED_BY = u1.UID
            LEFT JOIN USERS AS u2 ON c.ASSIGNED_TO = u2.UID
            LEFT JOIN CONTACTS_STATUS AS s ON c.ID = s.CONTACT_ID
            LEFT JOIN CONTACTS_CATEGORIES AS cc ON s.STAGE_ID = cc.ID
            LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
            LEFT JOIN CREDIT_SCORES AS cs ON c.ID = cs.CONTACT_ID
            LEFT JOIN CREDIT_REPORT_REQUEST AS cr ON c.ID = cr.CONTACT_ID
            LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
            LEFT JOIN (
                SELECT CONTACT_ID, F_DECIMAL
                FROM CONTACTS_USERFIELDS
                WHERE CUSTOM_ID = {$this->debtAmountCustomId}
            ) AS uf_debt ON c.ID = uf_debt.CONTACT_ID
            WHERE COALESCE(c.MODIFIED, a.STAMP, c.CREATED) >= DATEADD(hour, 7, '{$this->esc($startDate)}'::TIMESTAMP_NTZ)
              AND c.FIRSTNAME IS NOT NULL AND c.FIRSTNAME <> ''
              AND ISCOAPP = 0
              AND cls.TITLE <> 'Duplicate Lead'
              AND c.ID > {$lastId}
            QUALIFY ROW_NUMBER() OVER(PARTITION BY c.ID ORDER BY s.STAMP DESC) = 1
            ORDER BY c.ID
            LIMIT {$limit}
        ";
    }

    /**
     * Loads enrollment data scoped to enrolled contacts in this chunk.
     * A temp table passes the IDs to SQL Server, avoiding a full TblEnrollment table scan.
     */
    private function loadEnrollmentDataFiltered(DBConnector $connector, array $chunk): array
    {
        $empty = ['categories' => [], 'assigned_agents' => [], 'affiliate_agents' => []];

        $enrolledIds = [];
        foreach ($chunk as $row) {
            if (!empty($row['ENROLLED_DATE'])) {
                $id = (string) ($row['LLG_ID'] ?? '');
                if ($id !== '') {
                    $enrolledIds[] = $id;
                }
            }
        }

        if (empty($enrolledIds)) {
            return $empty;
        }

        $connector->querySqlServer("CREATE TABLE #TmpEnrollFilter (ContactId VARCHAR(20))");
        foreach (\array_chunk($enrolledIds, 1000) as $batch) {
            $values = \implode(', ', \array_map(
                fn($id) => "('" . \str_replace("'", "''", $id) . "')",
                $batch
            ));
            $connector->querySqlServer("INSERT INTO #TmpEnrollFilter VALUES {$values}");
        }

        $result = $connector->querySqlServer("
            SELECT e.LLG_ID, e.Category, e.Agent, e.Affiliate_Agent
            FROM TblEnrollment e
            JOIN #TmpEnrollFilter f ON e.LLG_ID = 'LLG-' + f.ContactId
            WHERE e.Category NOT IN ('', 'FDR', 'CSS', 'CNI')
        ");
        $connector->querySqlServer("DROP TABLE #TmpEnrollFilter");

        $categories      = [];
        $assignedAgents  = [];
        $affiliateAgents = [];

        foreach ($result['data'] ?? [] as $row) {
            $llgId = $row['LLG_ID'] ?? '';
            if (preg_match('/LLG-(\d+)/', $llgId, $matches)) {
                $contactId                   = $matches[1];
                $categories[$contactId]      = $row['Category'] ?? '';
                $assignedAgents[$contactId]  = $row['Agent'] ?? '';
                $affiliateAgents[$contactId] = $row['Affiliate_Agent'] ?? '';
            }
        }

        return [
            'categories'       => $categories,
            'assigned_agents'  => $assignedAgents,
            'affiliate_agents' => $affiliateAgents,
        ];
    }

    /**
     * Fetches drop names only for the External_IDs present in this chunk.
     * Uses a SQL Server temp table to avoid loading all of TblMailers.
     * Preserves fallback matching on the last 9 characters of External_ID.
     */
    private function fetchDropNamesFiltered(DBConnector $connector, array $chunk): array
    {
        $externalIds = [];
        foreach ($chunk as $row) {
            $tpId = \trim((string) ($row['EXTERNAL_ID'] ?? ''));
            if ($tpId !== '') {
                $externalIds[$tpId] = true;
            }
        }

        if (empty($externalIds)) {
            return [];
        }

        $externalIds = \array_keys($externalIds);

        $connector->querySqlServer("CREATE TABLE #TmpMailerFilter (ExtId VARCHAR(50))");
        foreach (\array_chunk($externalIds, 1000) as $batch) {
            $values = \implode(', ', \array_map(
                fn($id) => "('" . \str_replace("'", "''", \substr($id, 0, 50)) . "')",
                $batch
            ));
            $connector->querySqlServer("INSERT INTO #TmpMailerFilter VALUES {$values}");
        }

        $result = $connector->querySqlServer("
            SELECT m.External_ID, m.Drop_Name
            FROM TblMailers m
            WHERE m.External_ID IS NOT NULL
              AND m.Drop_Name IS NOT NULL
              AND (
                  m.External_ID IN (SELECT ExtId FROM #TmpMailerFilter)
                  OR (LEN(m.External_ID) > 9 AND RIGHT(m.External_ID, 9) IN (
                      SELECT RIGHT(ExtId, 9) FROM #TmpMailerFilter WHERE LEN(ExtId) > 9
                  ))
              )
        ");
        $connector->querySqlServer("DROP TABLE #TmpMailerFilter");

        $lookup = [];
        foreach ($result['data'] ?? [] as $row) {
            $externalId = $row['External_ID'] ?? '';
            $dropName   = $row['Drop_Name'] ?? '';
            if ($externalId && $dropName) {
                $lookup[$externalId] = $dropName;
                if (\strlen($externalId) > 9) {
                    $last9 = \substr($externalId, -9);
                    if (!isset($lookup[$last9])) {
                        $lookup[$last9] = $dropName;
                    }
                }
            }
        }

        return $lookup;
    }

    // -------------------------------------------------------------------------
    // Processing
    // -------------------------------------------------------------------------

    /**
     * Processes one chunk of Snowflake rows.
     * $seenTpIds is passed by reference so TP_ID deduplication persists across chunks.
     *
     * @param  array  $seenTpIds  Mutable dedup map — survives across all chunks in a run.
     * @return array{0: array, 1: array, 2: array}  [processedRows, categoryChanges, affiliateChanges]
     */
    private function processChunk(
        array $chunk,
        array $dropNames,
        array $enrollmentData,
        array &$seenTpIds
    ): array {
        $processed        = [];
        $categoryChanges  = [];
        $affiliateChanges = [];

        $existingCategories = $enrollmentData['categories'];
        $existingAffiliates = $enrollmentData['affiliate_agents'];

        foreach ($chunk as $row) {
            $contactId = $row['LLG_ID'] ?? '';
            $tpId      = $row['EXTERNAL_ID'] ?? '';

            if (($row['STATUS'] ?? '') === 'Duplicate Lead') {
                continue;
            }
            if ($tpId && isset($seenTpIds[$tpId])) {
                continue;
            }
            if ($tpId) {
                $seenTpIds[$tpId] = true;
            }

            $debtAmount = $row['DEBT_AMOUNT_CUSTOM'] ?? 0;
            $planTitle  = $row['PLAN_TITLE'] ?? '';
            $category   = $this->normalizePlanTitle($planTitle);
            // LT: agent is the assigned user from USERS join; LDR/PLAW: from custom field
            $agent      = $this->source === 'LT'
                ? ($row['ASSIGNED_TO'] ?? '')
                : ($row['AGENT_CUSTOM'] ?? '');
            $creditUtil = $this->parseCreditUtilization($row['CREDIT_UTILIZATION'] ?? '');

            $campaign = '';
            if ($tpId) {
                $campaign = $dropNames[$tpId] ?? ($dropNames[\substr($tpId, -9)] ?? '');
            }

            $processedRow = [
                'created_date'       => $this->formatDate($row['CREATED'] ?? null),
                'assigned_date'      => $this->formatDate($row['ASSIGNED_ON'] ?? null),
                'llg_id'             => 'LLG-' . $contactId,
                'external_id'        => \substr($tpId, 0, 50),
                'campaign'           => \substr($campaign, 0, 255),
                'data_source'        => \substr($row['DATA_SOURCE'] ?? '', 0, 255),
                'created_by'         => \substr($row['CREATED_BY'] ?? '', 0, 255),
                'agent'              => \substr($agent, 0, 255),
                'client'             => \substr($row['FULLNAME'] ?? '', 0, 255),
                'phone'              => \substr($this->cleanPhone($row['CELL_PHONE'] ?? ''), 0, 50),
                'email'              => $row['EMAIL'] ?? '',
                'address_1'          => \substr($row['ADDRESS1'] ?? '', 0, 255),
                'address_2'          => \substr($row['ADDRESS2'] ?? '', 0, 255),
                'city'               => \substr($row['CITY'] ?? '', 0, 100),
                'state'              => \substr($row['STATE'] ?? '', 0, 20),
                'zip'                => \substr($row['ZIP'] ?? '', 0, 20),
                'stage'              => $row['STAGE'] ?? '',
                'status'             => $row['STATUS'] ?? '',
                'debt_amount'        => \floor($debtAmount / 1000) * 1000,
                'debt_enrolled'      => $debtAmount,
                'credit_score'       => $row['CREDIT_SCORE'] ?? 0,
                'credit_utilization' => $creditUtil,
                'category'           => $category,
                'affiliate_agent'    => \substr($agent, 0, 255),
            ];

            // LT inserts into TblContacts which has no TP_ID column
            if ($this->source !== 'LT') {
                $processedRow['tp_id'] = \substr($tpId, 0, 50);
            }

            $processed[] = $processedRow;

            // Enrollment change detection in the same pass
            $enrolledDate = $row['ENROLLED_DATE'] ?? '';
            if (!empty($enrolledDate) && isset($existingCategories[$contactId]) && $category !== '') {
                $llgId = "LLG-{$contactId}";
                if ($existingCategories[$contactId] !== $category) {
                    $categoryChanges[] = ['llg_id' => $llgId, 'category' => $category];
                }
                if ($category !== 'LDR') {
                    $existingAffiliate = $existingAffiliates[$contactId] ?? '';
                    if ($agent !== '' && $existingAffiliate !== $agent && !\str_ends_with(\strtolower($agent), ' user')) {
                        $affiliateChanges[] = ['llg_id' => $llgId, 'agent' => $agent];
                    }
                }
            }
        }

        return [$processed, $categoryChanges, $affiliateChanges];
    }

    // -------------------------------------------------------------------------
    // Insertion
    // -------------------------------------------------------------------------

    /**
     * Inserts one processed chunk and returns the number of rows upserted.
     * Throws on any failure so the caller can abort the run immediately.
     *
     * Full refresh mode:  plain INSERT (table was already truncated).
     * Incremental mode:   DELETE matching LLG_IDs first, then INSERT — this
     *                     handles both updated existing contacts and brand-new ones
     *                     without needing a full-table MERGE statement.
     */
    private function insertChunk(DBConnector $connector, array $data, bool $incremental = false): int
    {
        if (empty($data)) {
            return 0;
        }

        // TblContacts (LT) has 24 columns — no TP_ID. TblContactsLDR/PLAW have 25.
        $fields = 'Created_Date, Assigned_Date, LLG_ID, External_ID, Campaign, Data_Source, '
            . 'Created_By, Agent, Client, Phone, Email, Address_1, Address_2, City, State, '
            . 'Zip, Stage, Status, Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, '
            . 'Category, Affiliate_Agent'
            . ($this->source !== 'LT' ? ', TP_ID' : '');

        $pdo = $connector->getSqlServerConnection();
        $pdo->beginTransaction();

        try {
            // Incremental: remove stale rows for every LLG_ID we are about to re-insert
            if ($incremental) {
                foreach (\array_chunk($data, 1000) as $deleteBatch) {
                    $ids = \implode(', ', \array_map(
                        fn($row) => "'" . $this->escSql($row['llg_id']) . "'",
                        $deleteBatch
                    ));
                    $sql = "DELETE FROM {$this->targetTable} WHERE LLG_ID IN ({$ids})";
                    if ($pdo->exec($sql) === false) {
                        $err = $pdo->errorInfo();
                        throw new \RuntimeException('DELETE batch failed: ' . ($err[2] ?? 'unknown PDO error'));
                    }
                }
            }

            // SQL Server hard-limits INSERT ... VALUES to 1 000 rows per statement
            foreach (\array_chunk($data, 1000) as $batch) {
                $valuesParts = [];

                foreach ($batch as $row) {
                    $createdDate  = $row['created_date'] ?: '';
                    $assignedDate = $row['assigned_date'] ? "'{$row['assigned_date']}'" : 'NULL';
                    $email        = \strpos($row['email'], '@') !== false
                        ? "'" . $this->escSql($row['email']) . "'"
                        : 'NULL';

                    $valuesParts[] = "('{$createdDate}', {$assignedDate}, "
                        . "'{$this->escSql($row['llg_id'])}', "
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
                        . ((int) $row['debt_amount']) . ", "
                        . ((float) $row['debt_enrolled']) . ", "
                        . ((int) $row['credit_score']) . ", "
                        . ((int) $row['credit_utilization']) . ", "
                        . "'{$this->escSql($row['category'])}', "
                        . "'{$this->escSql($row['affiliate_agent'])}'"
                        . ($this->source !== 'LT' ? ", '{$this->escSql($row['tp_id'])}'" : '')
                        . ')';
                }

                $sql = "INSERT INTO {$this->targetTable} ({$fields}) VALUES " . \implode(', ', $valuesParts);

                if ($pdo->exec($sql) === false) {
                    $err = $pdo->errorInfo();
                    throw new \RuntimeException('INSERT batch failed: ' . ($err[2] ?? 'unknown PDO error'));
                }
            }

            $pdo->commit();
            return \count($data);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Enrollment updates
    // -------------------------------------------------------------------------

    private function applyEnrollmentCategoryUpdates(DBConnector $connector, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $connector->querySqlServer("CREATE TABLE #TmpCatUpd (LLG_ID VARCHAR(50), NewCat VARCHAR(50))");

        foreach (\array_chunk($changes, 500) as $chunk) {
            $values = \implode(', ', \array_map(
                fn($c) => "('{$this->escSql($c['llg_id'])}', '{$this->escSql($c['category'])}')",
                $chunk
            ));
            $connector->querySqlServer("INSERT INTO #TmpCatUpd (LLG_ID, NewCat) VALUES {$values}");
        }

        $connector->querySqlServer("
            UPDATE TblEnrollment
            SET Category = u.NewCat
            FROM TblEnrollment e
            JOIN #TmpCatUpd u ON e.LLG_ID = u.LLG_ID
            WHERE e.Category <> u.NewCat OR e.Category IS NULL
        ");

        $connector->querySqlServer("DROP TABLE #TmpCatUpd");
    }

    private function applyEnrollmentAffiliateUpdates(DBConnector $connector, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $connector->querySqlServer("CREATE TABLE #TmpAffUpd (LLG_ID VARCHAR(50), NewAffiliate NVARCHAR(100))");

        foreach (\array_chunk($changes, 500) as $chunk) {
            $values = \implode(', ', \array_map(
                fn($c) => "('{$this->escSql($c['llg_id'])}', '{$this->escSql($c['agent'])}')",
                $chunk
            ));
            $connector->querySqlServer("INSERT INTO #TmpAffUpd (LLG_ID, NewAffiliate) VALUES {$values}");
        }

        $connector->querySqlServer("
            UPDATE TblEnrollment
            SET Affiliate_Agent = u.NewAffiliate
            FROM TblEnrollment e
            JOIN #TmpAffUpd u ON e.LLG_ID = u.LLG_ID
            WHERE (e.Affiliate_Agent <> u.NewAffiliate OR e.Affiliate_Agent IS NULL)
              AND u.NewAffiliate <> ''
        ");

        $connector->querySqlServer("DROP TABLE #TmpAffUpd");
    }

    // -------------------------------------------------------------------------
    // Table maintenance
    // -------------------------------------------------------------------------

    private function clearTargetTable(DBConnector $connector): void
    {
        $truncateResult = $connector->querySqlServer("TRUNCATE TABLE {$this->targetTable}");
        if ($truncateResult['success'] ?? false) {
            $this->info("[INFO] Target table truncated instantly.");
            return;
        }
        $this->info("[INFO] TRUNCATE failed ({$truncateResult['error']}), falling back to batch DELETE.");

        do {
            $connector->querySqlServer("DELETE TOP (50000) FROM {$this->targetTable}");
            $result = $connector->querySqlServer("SELECT COUNT(*) AS cnt FROM {$this->targetTable}");
            $count  = $result['data'][0]['cnt'] ?? 0;
            if ($count > 0) {
                $this->info("[INFO] Deleted batch, {$count} rows remaining...");
            }
        } while ($count > 0);

        $this->info("[INFO] Target table cleared");
    }

    private function updateRelatedTables(DBConnector $connector): void
    {
        // LT inserts into TblContacts directly — no cross-table updates needed
        if ($this->source === 'LT') {
            return;
        }

        $table = $this->targetTable;

        $steps = [
            "UPDATE TblContacts
             SET TblContacts.LLG_ID = {$table}.LLG_ID
             FROM TblContacts
             INNER JOIN {$table}
               ON TblContacts.LLG_ID = 'LLG-' + CAST({$table}.External_ID AS VARCHAR(50))"
            => '[INFO] Updated TblContacts.LLG_ID',

            "UPDATE TblEnrollment
             SET TblEnrollment.Agent = TblContacts.Agent
             FROM TblEnrollment, TblContacts
             WHERE TblEnrollment.LLG_ID = TblContacts.LLG_ID"
            => '[INFO] Updated TblEnrollment.Agent',

            // Both LDR and PLAW update Drop_Name
            "UPDATE TblEnrollment
             SET TblEnrollment.Drop_Name = TblContacts.Campaign
             FROM TblEnrollment, TblContacts
             WHERE TblEnrollment.LLG_ID = TblContacts.LLG_ID
               AND COALESCE(TblContacts.Campaign, '') <> ''"
            => '[INFO] Updated TblEnrollment.Drop_Name',
        ];

        foreach ($steps as $sql => $label) {
            try {
                $connector->querySqlServer($sql);
                $this->info($label);
            } catch (\Throwable $e) {
                $this->warn('[WARN] ' . $label . ' failed: ' . $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalizePlanTitle(string $title): string
    {
        if (empty($title)) {
            return '';
        }
        return stripos($title, 'CCS') !== false ? 'CCS' : 'LDR';
    }

    private function parseCreditUtilization(string $value): int
    {
        if (empty($value)) {
            return 0;
        }
        if (preg_match('/^\s*([\d.]+)/', $value, $matches)) {
            $util = \floatval($matches[1]);
            if ($util < 1) {
                $util *= 100;
            }
            return \intval($util);
        }
        return 0;
    }

    private function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    private function formatDate($value): string
    {
        if (empty($value)) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        // Fast path: Snowflake returns timestamps as 'YYYY-MM-DD HH:MM:SS[.ffffff]'
        if (\is_string($value) && \strlen($value) >= 19 && $value[4] === '-' && $value[7] === '-') {
            return \substr($value, 0, 19);
        }
        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function initializeSnowflakeConnector(): DBConnector
    {
        try {
            return DBConnector::fromEnvironment(strtolower($this->source));
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unable to initialize Snowflake connector for {$this->source}: {$e->getMessage()}");
        }
    }

    protected function initializeSqlServerConnector(): DBConnector
    {
        try {
            $connector = DBConnector::fromEnvironment(strtolower($this->source));
            $connector->initializeSqlServer();
            return $connector;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Unable to initialize SQL Server connector for {$this->source}: {$e->getMessage()}");
        }
    }

    // -------------------------------------------------------------------------
    // Sync timestamp (incremental watermark)
    // -------------------------------------------------------------------------

    private function timestampFilePath(): string
    {
        return storage_path('app/sync_timestamps.json');
    }

    private function readLastSyncTime(string $source): ?string
    {
        $path = $this->timestampFilePath();
        if (!\file_exists($path)) {
            return null;
        }
        $data = \json_decode(\file_get_contents($path), true);
        return $data[$source] ?? null;
    }

    private function writeLastSyncTime(string $source, string $datetime): void
    {
        $path = $this->timestampFilePath();
        $data = [];
        if (\file_exists($path)) {
            $data = \json_decode(\file_get_contents($path), true) ?? [];
        }
        $data[$source] = $datetime;
        \file_put_contents($path, \json_encode($data, JSON_PRETTY_PRINT));
    }

    // -------------------------------------------------------------------------
    // String escaping
    // -------------------------------------------------------------------------

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function escSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
