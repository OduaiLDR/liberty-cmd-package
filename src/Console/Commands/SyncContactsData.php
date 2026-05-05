<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\DBConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncContactsData extends Command
{
    protected $signature = 'Sync:contacts-data';

    protected $description = 'Sync contacts data from Snowflake to SQL Server (TblContactsLDR, TblContactsPLAW, and TblContactsLT)';

    private string $source;
    private int $debtAmountCustomId;
    private int $agentCustomId;
    private string $targetTable;

    public function handle(): int
    {
        $this->info("[INFO] Sync Contacts Data: starting for LDR, PLAW, and LT.");

        // Run for LDR
        $this->info("\n" . str_repeat('=', 80));
        $this->info("SYNCING LDR");
        $this->info(str_repeat('=', 80));
        $ldrResult = $this->syncForSource('LDR');

        // Run for PLAW
        $this->info("\n" . str_repeat('=', 80));
        $this->info("SYNCING PLAW");
        $this->info(str_repeat('=', 80));
        $plawResult = $this->syncForSource('PLAW');

        // Run for LT
        $this->info("\n" . str_repeat('=', 80));
        $this->info("SYNCING LT");
        $this->info(str_repeat('=', 80));
        $ltResult = $this->syncForSource('LT');

        $this->info("\n" . str_repeat('=', 80));
        if ($ldrResult === Command::SUCCESS && $plawResult === Command::SUCCESS && $ltResult === Command::SUCCESS) {
            $this->info('[SUCCESS] All syncs (LDR, PLAW, LT) completed successfully!');
            return Command::SUCCESS;
        } else {
            $this->error('[ERROR] One or more syncs failed. Check logs for details.');
            return Command::FAILURE;
        }
    }

    private function syncForSource(string $source): int
    {
        $this->source = $source;
        $this->info("[INFO] Sync Contacts Data: starting for {$this->source}.");

        // Set source-specific configuration
        if ($this->source === 'PLAW') {
            $this->debtAmountCustomId = 743019;
            $this->agentCustomId = 742153;
            $this->targetTable = 'TblContactsPLAW';
        } elseif ($this->source === 'LT') {
            $this->debtAmountCustomId = 743020;
            $this->agentCustomId = 742154;
            $this->targetTable = 'TblContactsLT';
        } else {
            $this->debtAmountCustomId = 745839;
            $this->agentCustomId = 742152;
            $this->targetTable = 'TblContactsLDR';
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

        // Step 1: Load existing enrollment data
        $this->info('[STEP 1] Loading existing enrollment data...');
        $enrollmentData = $this->loadEnrollmentData($sqlConnector);
        $this->info('[INFO] Loaded ' . count($enrollmentData['categories']) . ' enrollment records');

        // Step 2: Fetch contact data from Snowflake
        $this->info('[STEP 2] Fetching contact data from Snowflake...');
        $contactsData = $this->fetchContactsData($snowflake, $startDate);
        $this->info('[INFO] Fetched ' . count($contactsData) . ' contact records');

        // Step 3: Fetch additional user fields
        $this->info('[STEP 3] Fetching debt amounts...');
        $debtAmounts = $this->fetchDebtAmounts($snowflake);
        $this->info('[INFO] Fetched ' . count($debtAmounts) . ' debt amount records');

        $this->info('[STEP 4] Fetching plan titles...');
        $planTitles = $this->fetchPlanTitles($snowflake);
        $this->info('[INFO] Fetched ' . count($planTitles) . ' plan title records');

        $this->info('[STEP 5] Fetching agent assignments...');
        $agentAssignments = $this->fetchAgentAssignments($snowflake);
        $this->info('[INFO] Fetched ' . count($agentAssignments) . ' agent assignment records');

        $this->info('[STEP 6] Pre-loading mailer drop names...');
        $dropNames = $this->fetchAllDropNames($sqlConnector);
        $this->info('[INFO] Loaded ' . count($dropNames) . ' mailer drop names');

        // Step 4: Process and enrich contact data
        $this->info('[STEP 7] Processing contact data...');
        $processedData = $this->processContactData(
            $contactsData,
            $debtAmounts,
            $planTitles,
            $agentAssignments,
            $enrollmentData,
            $dropNames
        );
        $this->info('[INFO] Processed ' . count($processedData) . ' records for sync');

        // Step 5: Delete existing data from target table
        $this->info('[STEP 8] Clearing target table...');
        $this->clearTargetTable($sqlConnector);

        // Step 6: Insert data in batches
        $this->info('[STEP 9] Inserting data into ' . $this->targetTable . '...');
        $this->insertContactData($sqlConnector, $processedData);

        // Step 7: Update related tables
        $this->info('[STEP 10] Updating related tables...');
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

        $categories = [];
        $assignedAgents = [];
        $affiliateAgents = [];

        foreach ($result as $row) {
            $llgId = $row['LLG_ID'] ?? '';
            if (preg_match('/LLG-(\d+)/', $llgId, $matches)) {
                $contactId = $matches[1];
                $categories[$contactId] = $row['Category'] ?? '';
                $assignedAgents[$contactId] = $row['Agent'] ?? '';
                $affiliateAgents[$contactId] = $row['Affiliate_Agent'] ?? '';
            }
        }

        return [
            'categories' => $categories,
            'assigned_agents' => $assignedAgents,
            'affiliate_agents' => $affiliateAgents,
        ];
    }

    private function fetchContactsData(DBConnector $snowflake, string $startDate): array
    {
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
                    d.ENROLLED_DEBT,
                    cs.TRANSUNION AS CREDIT_SCORE,
                    SUBSTRING(cr.METADATA, CHARINDEX('RevolvingCreditUtilization', cr.METADATA) + 29,
                        CHARINDEX('Day30', cr.METADATA) - CHARINDEX('RevolvingCreditUtilization', cr.METADATA) - 32) AS CREDIT_UTILIZATION,
                    ep.FEE1,
                    c.TP_ID AS TP_ID_COPY,
                    c.ENROLLED_DATE,
                    ROW_NUMBER() OVER(PARTITION BY c.ID ORDER BY s.STAMP DESC) AS N
                FROM CONTACTS AS c
                LEFT JOIN DATA_SOURCES AS ds ON c.C_SOURCE = ds.ID
                LEFT JOIN CONTACTS_STATUS AS s ON c.ID = s.CONTACT_ID
                LEFT JOIN CONTACTS_CATEGORIES AS cc ON s.STAGE_ID = cc.ID
                LEFT JOIN CONTACTS_LEAD_STATUS AS cls ON s.STATUS_ID = cls.ID
                LEFT JOIN CREDIT_SCORES AS cs ON c.ID = cs.CONTACT_ID
                LEFT JOIN CREDIT_REPORT_REQUEST AS cr ON c.ID = cr.CONTACT_ID
                LEFT JOIN (
                    SELECT CONTACT_ID, SUM(ORIGINAL_DEBT_AMOUNT) AS ENROLLED_DEBT
                    FROM DEBTS
                    WHERE ENROLLED = 1
                      AND _FIVETRAN_DELETED = FALSE
                    GROUP BY CONTACT_ID
                ) AS d ON c.ID = d.CONTACT_ID
                LEFT JOIN ENROLLMENT_PLAN AS ep ON c.ID = ep.CONTACT_ID
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

    private function fetchDebtAmounts(DBConnector $snowflake): array
    {
        $sql = "
            SELECT CONTACT_ID, F_DECIMAL
            FROM CONTACTS_USERFIELDS
            WHERE CUSTOM_ID = {$this->debtAmountCustomId}
        ";

        $result = $snowflake->query($sql);
        $lookup = [];
        foreach ($result['data'] ?? [] as $row) {
            $lookup[$row['CONTACT_ID']] = $row['F_DECIMAL'] ?? 0;
        }
        return $lookup;
    }

    private function fetchPlanTitles(DBConnector $snowflake): array
    {
        $sql = "
            SELECT p.CONTACT_ID, ed.TITLE
            FROM ENROLLMENT_PLAN AS p
            LEFT JOIN ENROLLMENT_DEFAULTS2 AS ed ON p.PLAN_ID = ed.ID
            WHERE ed.TITLE IS NOT NULL
        ";

        $result = $snowflake->query($sql);
        $lookup = [];
        foreach ($result['data'] ?? [] as $row) {
            $lookup[$row['CONTACT_ID']] = $row['TITLE'] ?? '';
        }
        return $lookup;
    }

    private function fetchAgentAssignments(DBConnector $snowflake): array
    {
        $sql = "
            SELECT CONTACT_ID, F_SHORTSTRING
            FROM CONTACTS_USERFIELDS
            WHERE CUSTOM_ID = {$this->agentCustomId}
        ";

        $result = $snowflake->query($sql);
        $lookup = [];
        foreach ($result['data'] ?? [] as $row) {
            $lookup[$row['CONTACT_ID']] = $row['F_SHORTSTRING'] ?? '';
        }
        return $lookup;
    }

    private function processContactData(
        array $contactsData,
        array $debtAmounts,
        array $planTitles,
        array $agentAssignments,
        array $enrollmentData,
        array $dropNames
    ): array {
        $processed = [];
        $seenTpIds = [];

        foreach ($contactsData as $row) {
            $contactId = $row['LLG_ID'] ?? '';
            $tpId = $row['EXTERNAL_ID'] ?? '';

            // Get debt amount
            $debtAmount = $debtAmounts[$contactId] ?? ($row['ENROLLED_DEBT'] ?? 0);
            if ($debtAmount == 0 || $debtAmount > 999999) {
                $debtAmount = $row['ENROLLED_DEBT'] ?? 0;
            }

            // Get and normalize plan title
            $planTitle = $planTitles[$contactId] ?? '';
            $category = $this->normalizePlanTitle($planTitle);

            // Get agent assignment
            $agent = $agentAssignments[$contactId] ?? '';

            // Parse credit utilization
            $creditUtil = $this->parseCreditUtilization($row['CREDIT_UTILIZATION'] ?? '');

            // Skip duplicate leads
            if (($row['STATUS'] ?? '') === 'Duplicate Lead') {
                continue;
            }

            // Skip duplicate TP_IDs
            if ($tpId && isset($seenTpIds[$tpId])) {
                continue;
            }
            if ($tpId) {
                $seenTpIds[$tpId] = true;
            }

            // Get campaign/drop name from pre-loaded lookup
            $campaign = '';
            $externalId = $row['EXTERNAL_ID'] ?? '';
            if ($externalId) {
                $campaign = $dropNames[$externalId] ?? ($dropNames[substr($externalId, -9)] ?? '');
            }

            // Skip enrollment updates during processing - will be done in batch later

            $processed[] = [
                'created_date' => $row['CREATED'] ?? null,
                'assigned_date' => $row['ASSIGNED_ON'] ?? null,
                'llg_id' => 'LLG-' . $contactId,
                'external_id' => $row['EXTERNAL_ID'] ?? '',
                'campaign' => $campaign,
                'data_source' => $row['DATA_SOURCE'] ?? '',
                'created_by' => $row['CREATED_BY'] ?? '',
                'agent' => $agent,
                'client' => $row['FULLNAME'] ?? '',
                'phone' => $this->cleanPhone($row['CELL_PHONE'] ?? ''),
                'email' => $row['EMAIL'] ?? '',
                'address_1' => $row['ADDRESS1'] ?? '',
                'address_2' => $row['ADDRESS2'] ?? '',
                'city' => $row['CITY'] ?? '',
                'state' => $row['STATE'] ?? '',
                'zip' => $row['ZIP'] ?? '',
                'stage' => $row['STAGE'] ?? '',
                'status' => $row['STATUS'] ?? '',
                'debt_amount' => floor($debtAmount / 1000) * 1000,
                'debt_enrolled' => $row['ENROLLED_DEBT'] ?? 0,
                'credit_score' => $row['CREDIT_SCORE'] ?? 0,
                'credit_utilization' => $creditUtil,
                'category' => $category,
                'affiliate_agent' => $agent,
                'tp_id' => $tpId,
            ];
        }

        return $processed;
    }

    private function normalizePlanTitle(string $title): string
    {
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

        $parts = explode(',', $value);
        if (isset($parts[3])) {
            $util = floatval($parts[3]) / 100;
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

    private function fetchAllDropNames(DBConnector $connector): array
    {
        $sql = "SELECT External_ID, Drop_Name FROM TblMailers WHERE External_ID IS NOT NULL";
        $result = $connector->querySqlServer($sql);
        
        $lookup = [];
        foreach ($result as $row) {
            $externalId = $row['External_ID'] ?? '';
            $dropName = $row['Drop_Name'] ?? '';
            if ($externalId && $dropName) {
                $lookup[$externalId] = $dropName;
                // Also store last 9 chars for fallback
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


    private function clearTargetTable(DBConnector $connector): void
    {
        try {
            $connector->querySqlServer("TRUNCATE TABLE {$this->targetTable}");
            $this->info("[INFO] Target table truncated instantly.");
            return;
        } catch (\Throwable $e) {
            $this->info("[INFO] TRUNCATE failed, falling back to batch DELETE.");
        }

        $deletedTotal = 0;

        do {
            $sql = "DELETE TOP (50000) FROM {$this->targetTable}";
            try {
                $connector->querySqlServer($sql);
            } catch (\Throwable $e) {
                // Ignore errors
            }

            $sql = "SELECT COUNT(*) AS cnt FROM {$this->targetTable}";
            $result = $connector->querySqlServer($sql);
            $count = $result[0]['cnt'] ?? 0;

            $deletedTotal += 50000;
            if ($count > 0) {
                $this->info("[INFO] Deleted batch, {$count} rows remaining...");
            }
        } while ($count > 0);

        $this->info("[INFO] Target table cleared");
    }

    private function insertContactData(DBConnector $connector, array $data): void
    {
        $fields = 'Created_Date, Assigned_Date, LLG_ID, External_ID, Campaign, Data_Source, Created_By, Agent, Client, Phone, Email, Address_1, Address_2, City, State, Zip, Stage, Status, Debt_Amount, Debt_Enrolled, Credit_Score, Credit_Utilization, Category, Affiliate_Agent, TP_ID';

        $batchSize = 500;
        $total = count($data);
        $inserted = 0;

        for ($i = 0; $i < $total; $i += $batchSize) {
            $batch = array_slice($data, $i, $batchSize);
            $valuesParts = [];

            foreach ($batch as $row) {
                $createdDate = $this->formatDate($row['created_date']);
                $assignedDate = $row['assigned_date'] ? "'" . $this->formatDate($row['assigned_date']) . "'" : 'NULL';
                $llgId = $this->escSql($row['llg_id']);
                $externalId = $this->escSql($row['external_id']);
                $campaign = $this->escSql($row['campaign']);
                $dataSource = $this->escSql($row['data_source']);
                $createdBy = $this->escSql($row['created_by']);
                $agent = $this->escSql($row['agent']);
                $client = $this->escSql($row['client']);
                $phone = $this->escSql($row['phone']);
                $email = strpos($row['email'], '@') !== false ? "'" . $this->escSql($row['email']) . "'" : 'NULL';
                $address1 = $this->escSql($row['address_1']);
                $address2 = $this->escSql($row['address_2']);
                $city = $this->escSql($row['city']);
                $state = $this->escSql($row['state']);
                $zip = $this->escSql($row['zip']);
                $stage = $this->escSql($row['stage']);
                $status = $this->escSql($row['status']);
                $debtAmount = $row['debt_amount'];
                $debtEnrolled = $row['debt_enrolled'];
                $creditScore = $row['credit_score'];
                $creditUtil = $row['credit_utilization'];
                $category = $this->escSql($row['category']);
                $affiliateAgent = $this->escSql($row['affiliate_agent']);
                $tpId = $this->escSql($row['tp_id']);

                $valuesParts[] = "('{$createdDate}', {$assignedDate}, '{$llgId}', '{$externalId}', '{$campaign}', '{$dataSource}', '{$createdBy}', '{$agent}', '{$client}', '{$phone}', {$email}, '{$address1}', '{$address2}', '{$city}', '{$state}', '{$zip}', '{$stage}', '{$status}', '{$debtAmount}', '{$debtEnrolled}', '{$creditScore}', '{$creditUtil}', '{$category}', '{$affiliateAgent}', '{$tpId}')";
            }

            $sql = "INSERT INTO {$this->targetTable} ({$fields}) VALUES " . implode(', ', $valuesParts);

            try {
                $connector->querySqlServer($sql);
                $inserted += count($batch);
                $this->info("[INFO] Inserted {$inserted}/{$total} records");
            } catch (\Throwable $e) {
                $this->error('Failed to insert batch: ' . $e->getMessage());
                Log::error('SyncContactsData: Insert failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function updateRelatedTables(DBConnector $connector): void
    {
        // Update TblContacts.LLG_ID (Optimized JOIN avoiding REPLACE function)
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
            // Update Drop_Name from Campaign
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
            // PLAW: Update Agent again with non-empty condition
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
            $dt = new \DateTime($value);
            return $dt->format('Y-m-d H:i:s');
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
