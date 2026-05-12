<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SyncContactsData extends Command
{
    protected $signature = 'Sync:contacts-data {--source= : Run a single source only (LDR, PLAW, or LT)}';

    protected $description = 'Sync contacts data from Snowflake to SQL Server (TblContactsLDR, TblContactsPLAW, and TblContactsLT)';

    private string $source;
    private int $debtAmountCustomId;
    private int $agentCustomId;
    private string $targetTable;

    public function handle(): int
    {
        ini_set('memory_limit', '2G');

        // Single-source mode: used by the parallel sub-processes spawned below
        $source = strtoupper((string) $this->option('source'));
        if ($source !== '') {
            if (!in_array($source, ['LDR', 'PLAW', 'LT'], true)) {
                $this->error("Unknown source '{$source}'. Use LDR, PLAW, or LT.");
                return Command::FAILURE;
            }
            return $this->syncForSource($source);
        }

        // No --source given: spawn all three in parallel and stream their output here
        $this->info("[INFO] Sync Contacts Data: starting LDR, PLAW, and LT in parallel.");

        $php      = PHP_BINARY;
        $artisan  = base_path('artisan');
        $sources  = ['LDR', 'PLAW', 'LT'];
        $results  = [];

        $pool = Process::pool(function ($pool) use ($php, $artisan, $sources) {
            foreach ($sources as $source) {
                $pool->as($source)->timeout(1800)->command([$php, $artisan, 'Sync:contacts-data', "--source={$source}"]);
            }
        })->start(function (string $type, string $output, string $key) {
            // Stream each sub-process line with its source label prefix
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
            $exitCode = $processes[$source]->exitCode();
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

        // Set source-specific configuration
        if ($this->source === 'PLAW') {
            $this->debtAmountCustomId = 743019;
            $this->agentCustomId      = 742153;
            $this->targetTable        = 'TblContactsPLAW';
        } elseif ($this->source === 'LT') {
            $this->debtAmountCustomId = 743020;
            $this->agentCustomId      = 742154;
            $this->targetTable        = 'TblContactsLT';
        } else {
            $this->debtAmountCustomId = 745839;
            $this->agentCustomId      = 742152;
            $this->targetTable        = 'TblContactsLDR';
        }

        try {
            $snowflake = $this->initializeSnowflakeConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize Snowflake connector: ' . $e->getMessage());
            Log::error('SyncContactsData: Snowflake init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        try {
            $sqlConnector = $this->initializeSqlServerConnector();
        } catch (\Throwable $e) {
            $this->error('Failed to initialize SQL Server connector: ' . $e->getMessage());
            Log::error('SyncContactsData: SQL Server init failed', ['exception' => $e]);
            return Command::FAILURE;
        }

        $startDate = '2021-07-01';

        $enrollmentData = $this->loadEnrollmentData($sqlConnector);
        $contactsData   = $this->fetchContactsData($snowflake, $startDate);
        $dropNames      = $this->fetchAllDropNames($sqlConnector);
        $processedData  = $this->processContactData($contactsData, $dropNames);

        $this->info('[INFO] Fetched ' . count($contactsData) . ' → processed ' . count($processedData) . ' records');

        $this->updateEnrollmentPerContact($sqlConnector, $contactsData, $enrollmentData);
        $this->clearTargetTable($sqlConnector);

        $this->info('[INFO] Inserting into ' . $this->targetTable . '...');
        $this->insertContactData($sqlConnector, $processedData);

        $this->updateRelatedTables($sqlConnector);

        $this->info("[SUCCESS] {$this->source} sync completed successfully!");
        return Command::SUCCESS;
    }

    private function loadEnrollmentData(DBConnector $connector): array
    {
        $sql = "
            SELECT LLG_ID, Category, Agent, Affiliate_Agent
            FROM TblEnrollment
            WHERE Category NOT IN ('', 'FDR', 'CSS', 'CNI')
        ";

        $result = $connector->querySqlServer($sql);

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
            'categories'      => $categories,
            'assigned_agents' => $assignedAgents,
            'affiliate_agents' => $affiliateAgents,
        ];
    }

    private function fetchContactsData(DBConnector $snowflake, string $startDate): array
    {
        // Debt amounts, plan titles, and agent assignments are merged here as LEFT JOINs,
        // eliminating 3 separate Snowflake API round trips per source run.
        $sql = "
            SELECT *
            FROM (
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
                    uf_agent.F_SHORTSTRING AS AGENT_CUSTOM,
                    ROW_NUMBER() OVER(PARTITION BY c.ID ORDER BY s.STAMP DESC) AS N
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
                WHERE TIMEADD(hour, -7, COALESCE(c.MODIFIED, c.CREATED)) >= '{$this->esc($startDate)}'
                  AND COALESCE(c.FIRSTNAME, '') <> ''
                  AND ISCOAPP = 0
                ORDER BY c.ID, N ASC
            )
            WHERE N = 1
        ";

        $result = $snowflake->query($sql);
        return $result['data'] ?? [];
    }

    private function fetchAllDropNames(DBConnector $connector): array
    {
        $sql    = "SELECT External_ID, Drop_Name FROM TblMailers WHERE External_ID IS NOT NULL";
        $result = $connector->querySqlServer($sql);

        $lookup = [];
        foreach ($result['data'] ?? [] as $row) {
            $externalId = $row['External_ID'] ?? '';
            $dropName   = $row['Drop_Name'] ?? '';
            if ($externalId && $dropName) {
                $lookup[$externalId] = $dropName;
                // Also store last 9 chars for fallback matching
                if (strlen($externalId) > 9) {
                    $last9 = substr($externalId, -9);
                    if (!isset($lookup[$last9])) {
                        $lookup[$last9] = $dropName;
                    }
                }
            }
        }
        return $lookup;
    }

    private function processContactData(
        array $contactsData,
        array $dropNames
    ): array {
        $processed = [];
        $seenTpIds = [];

        foreach ($contactsData as $row) {
            $contactId = $row['LLG_ID'] ?? '';
            $tpId      = $row['EXTERNAL_ID'] ?? '';

            // Early exit before any lookups — skip rows we know we'll discard
            if (($row['STATUS'] ?? '') === 'Duplicate Lead') {
                continue;
            }
            if ($tpId && isset($seenTpIds[$tpId])) {
                continue;
            }
            if ($tpId) {
                $seenTpIds[$tpId] = true;
            }

            // Debt amount from custom userfield (no enrolled-debt table exists in this schema)
            $debtAmount = $row['DEBT_AMOUNT_CUSTOM'] ?? 0;

            // Plan title and agent come directly from the merged Snowflake query
            $planTitle = $row['PLAN_TITLE'] ?? '';
            $category  = $this->normalizePlanTitle($planTitle);
            $agent     = $row['AGENT_CUSTOM'] ?? '';

            // Parse credit utilization
            $creditUtil = $this->parseCreditUtilization($row['CREDIT_UTILIZATION'] ?? '');

            // Campaign/drop name from pre-loaded SQL Server lookup
            $campaign   = '';
            $externalId = $row['EXTERNAL_ID'] ?? '';
            if ($externalId) {
                $campaign = $dropNames[$externalId] ?? ($dropNames[substr($externalId, -9)] ?? '');
            }

            $processed[] = [
                'created_date'       => $row['CREATED'] ?? null,
                'assigned_date'      => $row['ASSIGNED_ON'] ?? null,
                'llg_id'             => 'LLG-' . $contactId,
                'external_id'        => substr($row['EXTERNAL_ID'] ?? '', 0, 50),
                'campaign'           => substr($campaign, 0, 255),
                'data_source'        => substr($row['DATA_SOURCE'] ?? '', 0, 255),
                'created_by'         => substr($row['CREATED_BY'] ?? '', 0, 255),
                'agent'              => substr($agent, 0, 255),
                'client'             => substr($row['FULLNAME'] ?? '', 0, 255),
                'phone'              => substr($this->cleanPhone($row['CELL_PHONE'] ?? ''), 0, 50),
                'email'              => $row['EMAIL'] ?? '',
                'address_1'          => substr($row['ADDRESS1'] ?? '', 0, 255),
                'address_2'          => substr($row['ADDRESS2'] ?? '', 0, 255),
                'city'               => substr($row['CITY'] ?? '', 0, 100),
                'state'              => substr($row['STATE'] ?? '', 0, 20),
                'zip'                => substr($row['ZIP'] ?? '', 0, 20),
                'stage'              => $row['STAGE'] ?? '',
                'status'             => $row['STATUS'] ?? '',
                'debt_amount'        => floor($debtAmount / 1000) * 1000,
                'debt_enrolled'      => $debtAmount,
                'credit_score'       => $row['CREDIT_SCORE'] ?? 0,
                'credit_utilization' => $creditUtil,
                'category'           => $category,
                'affiliate_agent'    => substr($agent, 0, 255),
                'tp_id'              => substr($tpId, 0, 50),
            ];
        }

        return $processed;
    }

    private function normalizePlanTitle(string $title): string
    {
        if (empty($title)) {
            return '';
        }
        if (stripos($title, 'CCS') !== false) {
            return 'CCS';
        }
        return 'LDR';
    }

    private function parseCreditUtilization(string $value): int
    {
        if (empty($value)) {
            return 0;
        }

        // Match the first numeric value at the start of the string (equivalent to VBA's Val())
        if (preg_match('/^\s*([\d.]+)/', $value, $matches)) {
            $util = floatval($matches[1]);
            if ($util < 1) {
                $util *= 100;
            }
            return intval($util);
        }

        return 0;
    }

    private function cleanPhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

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

    private function insertContactData(DBConnector $connector, array $data): void
    {
        $fields    = 'Created_Date, Assigned_Date, LLG_ID, External_ID, Campaign, Data_Source, Created_By, Agent, Client, Phone, Email, Address_1, Address_2, City, State, Zip, Stage, Status, Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, Category, Affiliate_Agent, TP_ID';
        $batchSize = 1000;
        $total     = count($data);
        $inserted  = 0;
        $pdo       = $connector->getSqlServerConnection();

        $pdo->beginTransaction();
        try {
            for ($i = 0; $i < $total; $i += $batchSize) {
                $batch       = array_slice($data, $i, $batchSize);
                $valuesParts = [];

                foreach ($batch as $row) {
                    $createdDate  = $this->formatDate($row['created_date']);
                    $assignedDate = $row['assigned_date'] ? "'" . $this->formatDate($row['assigned_date']) . "'" : 'NULL';
                    $llgId        = $this->escSql($row['llg_id']);
                    $externalId   = $this->escSql($row['external_id']);
                    $campaign     = $this->escSql($row['campaign']);
                    $dataSource   = $this->escSql($row['data_source']);
                    $createdBy    = $this->escSql($row['created_by']);
                    $agent        = $this->escSql($row['agent']);
                    $client       = $this->escSql($row['client']);
                    $phone        = $this->escSql($row['phone']);
                    $email        = strpos($row['email'], '@') !== false ? "'" . $this->escSql($row['email']) . "'" : 'NULL';
                    $address1     = $this->escSql($row['address_1']);
                    $address2     = $this->escSql($row['address_2']);
                    $city         = $this->escSql($row['city']);
                    $state        = $this->escSql($row['state']);
                    $zip          = $this->escSql($row['zip']);
                    $stage        = $this->escSql($row['stage']);
                    $status       = $this->escSql($row['status']);
                    $debtAmount   = $row['debt_amount'];
                    $debtEnrolled = $row['debt_enrolled'];
                    $creditScore  = $row['credit_score'];
                    $creditUtil   = $row['credit_utilization'];
                    $category     = $this->escSql($row['category']);
                    $affiliate    = $this->escSql($row['affiliate_agent']);
                    $tpId         = $this->escSql($row['tp_id']);

                    $valuesParts[] = "('{$createdDate}', {$assignedDate}, '{$llgId}', '{$externalId}', '{$campaign}', '{$dataSource}', '{$createdBy}', '{$agent}', '{$client}', '{$phone}', {$email}, '{$address1}', '{$address2}', '{$city}', '{$state}', '{$zip}', '{$stage}', '{$status}', '{$debtAmount}', '{$debtEnrolled}', '{$creditScore}', '{$creditUtil}', '{$category}', '{$affiliate}', '{$tpId}')";
                }

                $sql    = "INSERT INTO {$this->targetTable} ({$fields}) VALUES " . implode(', ', $valuesParts);
                $result = $connector->querySqlServer($sql);

                if (!($result['success'] ?? true)) {
                    throw new \RuntimeException($result['error'] ?? 'INSERT batch failed');
                }

                $inserted += count($batch);
                $this->info("[INFO] Inserted {$inserted}/{$total} records");
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->error('Insert transaction failed: ' . $e->getMessage());
            Log::error('SyncContactsData: Insert transaction failed', ['error' => $e->getMessage()]);
        }
    }

    private function updateEnrollmentPerContact(
        DBConnector $sqlConnector,
        array $contactsData,
        array $enrollmentData
    ): void {
        $existingCategories = $enrollmentData['categories'];
        $existingAffiliates = $enrollmentData['affiliate_agents'];
        $categoryChanges    = [];
        $affiliateChanges   = [];

        foreach ($contactsData as $row) {
            $contactId    = $row['LLG_ID'] ?? '';
            $enrolledDate = $row['ENROLLED_DATE'] ?? '';

            // Only process enrolled contacts that exist in TblEnrollment
            if (empty($enrolledDate) || !isset($existingCategories[$contactId])) {
                continue;
            }

            $planTitle   = $row['PLAN_TITLE'] ?? '';
            $newCategory = $this->normalizePlanTitle($planTitle);

            // Skip if no plan title — category stays as-is
            if ($newCategory === '') {
                continue;
            }

            $llgId = "LLG-{$contactId}";

            if ($existingCategories[$contactId] !== $newCategory) {
                $categoryChanges[] = ['llg_id' => $llgId, 'category' => $newCategory];
            }

            // For CCS contacts: update Affiliate_Agent if it changed and is not a system user
            if ($newCategory !== 'LDR') {
                $agent             = $row['AGENT_CUSTOM'] ?? '';
                $existingAffiliate = $existingAffiliates[$contactId] ?? '';
                if ($agent !== '' && $existingAffiliate !== $agent && !str_ends_with(strtolower($agent), ' user')) {
                    $affiliateChanges[] = ['llg_id' => $llgId, 'agent' => $agent];
                }
            }
        }

        $this->applyEnrollmentCategoryUpdates($sqlConnector, $categoryChanges);
        $this->applyEnrollmentAffiliateUpdates($sqlConnector, $affiliateChanges);

        $this->info('[INFO] Enrollment updates: ' . count($categoryChanges) . ' category, ' . count($affiliateChanges) . ' affiliate agent');
    }

    private function applyEnrollmentCategoryUpdates(DBConnector $connector, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $connector->querySqlServer("CREATE TABLE #TmpCatUpd (LLG_ID VARCHAR(50), NewCat VARCHAR(50))");

        foreach (array_chunk($changes, 500) as $chunk) {
            $values = implode(', ', array_map(
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

        foreach (array_chunk($changes, 500) as $chunk) {
            $values = implode(', ', array_map(
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

    private function updateRelatedTables(DBConnector $connector): void
    {
        // Update TblContacts.LLG_ID
        $sql = "
            UPDATE TblContacts
            SET TblContacts.LLG_ID = {$this->targetTable}.LLG_ID
            FROM TblContacts
            INNER JOIN {$this->targetTable}
              ON TblContacts.LLG_ID = 'LLG-' + CAST({$this->targetTable}.External_ID AS VARCHAR(50))
        ";
        try {
            $connector->querySqlServer($sql);
            $this->info('[INFO] Updated TblContacts.LLG_ID');
        } catch (\Throwable $e) {
            $this->warn('[WARN] Failed to update TblContacts.LLG_ID: ' . $e->getMessage());
        }

        // Update TblEnrollment.Agent
        $sql = "
            UPDATE TblEnrollment
            SET TblEnrollment.Agent = TblContacts.Agent
            FROM TblEnrollment, TblContacts
            WHERE TblEnrollment.LLG_ID = TblContacts.LLG_ID
        ";
        try {
            $connector->querySqlServer($sql);
            $this->info('[INFO] Updated TblEnrollment.Agent');
        } catch (\Throwable $e) {
            $this->warn('[WARN] Failed to update TblEnrollment.Agent: ' . $e->getMessage());
        }

        // Source-specific final update
        if ($this->source === 'LDR') {
            $sql = "
                UPDATE TblEnrollment
                SET TblEnrollment.Drop_Name = TblContacts.Campaign
                FROM TblEnrollment, TblContacts
                WHERE TblEnrollment.LLG_ID = TblContacts.LLG_ID
                  AND COALESCE(TblContacts.Campaign, '') <> ''
            ";
            try {
                $connector->querySqlServer($sql);
                $this->info('[INFO] Updated TblEnrollment.Drop_Name');
            } catch (\Throwable $e) {
                $this->warn('[WARN] Failed to update TblEnrollment.Drop_Name: ' . $e->getMessage());
            }
        } else {
            $sql = "
                UPDATE TblEnrollment
                SET TblEnrollment.Agent = TblContacts.Agent
                FROM TblEnrollment, TblContacts
                WHERE TblEnrollment.LLG_ID = TblContacts.LLG_ID
                  AND COALESCE(TblContacts.Agent, '') <> ''
            ";
            try {
                $connector->querySqlServer($sql);
                $this->info('[INFO] Updated TblEnrollment.Agent (PLAW-specific)');
            } catch (\Throwable $e) {
                $this->warn('[WARN] Failed to update TblEnrollment.Agent (PLAW): ' . $e->getMessage());
            }
        }
    }

    private function formatDate($value): string
    {
        if (empty($value)) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
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

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function escSql(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
