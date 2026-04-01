<?php

namespace Cmd\Reports\Console\Commands\ProgramCompletions;

use Cmd\Reports\Services\DBConnector;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessProgramCompletions extends Command
{
    protected $signature = 'process:program-completions
        {--company= : Limit to a single program (LDR or PLAW)}
        {--contact= : Limit to a single contact ID (LLG_ID or numeric CRM ID)}
        {--dry-run : Preview mode - no CRM updates or database writes}
        {--sync : Process inline instead of async}
        {--limit=0 : Limit the number of completions to process}
        {--skip-note : Skip CRM note creation}
        {--force : Force process contact even if not at 100% (for testing)}
        {--verbose-log : Show detailed CRM API logging}';

    protected $description = 'Process program completions: find clients with 100%+ debt settled and mark them as Graduated / Completed.';

    private const CRM_BASE_URL = 'https://api.forthcrm.com/v1';
    private const CRM_GRADUATED_STATUS_TITLE = 'Graduated / Completed';

    private bool $isDryRun = true;
    private bool $skipNote = false;
    private bool $forceMode = false;
    private bool $verboseLog = false;
    private array $apiKeys = [];  // Cached API keys from TblAPIKeys
    private array $graduatedWorkflowStatusIds = [];
    private ?Client $httpClient = null;
    
    // Report tracking
    private int $totalProcessed = 0;
    private int $totalGraduated = 0;
    private int $crmUpdates = 0;
    private int $notesCreated = 0;
    private int $totalErrors = 0;
    private array $completedClients = [];
    private array $errorDetails = [];
    private string $currentConnection = '';

    public function handle(): int
    {
        $this->warn('Program Completions uses --dry-run for preview mode.');
        $this->warn('Without --dry-run it will update CRM status to "' . self::CRM_GRADUATED_STATUS_TITLE . '" and add notes.');
        $this->newLine();

        $companyFilter = strtoupper(trim((string) ($this->option('company') ?? '')));
        if ($companyFilter !== '' && !in_array($companyFilter, ['LDR', 'PLAW'], true)) {
            $this->error('Invalid --company value. Use LDR or PLAW.');
            return Command::FAILURE;
        }

        $contactFilter = $this->normalizeContactId((string) ($this->option('contact') ?? ''));
        $limit = max(0, (int) ($this->option('limit') ?? 0));
        $this->isDryRun = (bool) $this->option('dry-run');
        $this->skipNote = (bool) $this->option('skip-note');
        $this->forceMode = (bool) $this->option('force');
        $this->verboseLog = (bool) $this->option('verbose-log');

        if ($this->verboseLog) {
            $this->info('Verbose logging enabled');
        }

        if (!$this->isDryRun) {
            $this->error(str_repeat('=', 60));
            $this->error('WARNING: LIVE MODE - changes will be made to real data.');
            $this->error(str_repeat('=', 60));

            if (!$this->confirm('Are you SURE you want to run in LIVE mode?', false)) {
                $this->info('Aborted.');
                return Command::SUCCESS;
            }
        }

        $connections = $this->connectionsForCompany($companyFilter);
        $allCompletions = [];
        $summaries = [];

        foreach ($connections as $connectionName) {
            $source = strtoupper($connectionName);
            $this->info("[$source] Initializing databases...");

            try {
                $connector = $this->createConnector($connectionName);
                
                $this->line("  OK Snowflake + SQL Server");

                // Fetch eligible completions from SQL Server
                $completions = $this->fetchEligibleCompletions($connector, $contactFilter, $source);
                $this->line("  Found " . count($completions) . " eligible completions");

                foreach ($completions as $completion) {
                    $completion['connection_name'] = $connectionName;
                    $completion['company'] = $source;
                    $allCompletions[] = $completion;
                }

                $summaries[] = [
                    'source' => $source,
                    'eligible' => count($completions),
                ];

            } catch (\Throwable $e) {
                $this->error("[$source] Failed: {$e->getMessage()}");
                Log::error('Program completions stage-1 failure.', [
                    'connection' => $connectionName,
                    'exception' => $e,
                ]);
                return Command::FAILURE;
            }
        }

        // Apply limit
        $totalBefore = count($allCompletions);
        if ($limit > 0 && count($allCompletions) > $limit) {
            $allCompletions = array_slice($allCompletions, 0, $limit);
        }

        $this->printSummary($summaries, $totalBefore, count($allCompletions));

        if (empty($allCompletions)) {
            $this->warn('No eligible program completions found.');
            return Command::SUCCESS;
        }

        // Process each completion
        foreach ($allCompletions as $completion) {
            try {
                $this->currentConnection = $completion['connection_name'];
                $this->processCompletion($completion);
                $this->totalProcessed++;
            } catch (\Throwable $e) {
                $this->totalErrors++;
                $this->errorDetails[] = [
                    'llg_id' => $completion['LLG_ID'],
                    'message' => $e->getMessage(),
                ];
                $this->error("  ERROR processing {$completion['LLG_ID']}: {$e->getMessage()}");
                Log::error('Program completion processing failed.', [
                    'llg_id' => $completion['LLG_ID'],
                    'company' => $completion['company'],
                    'exception' => $e,
                ]);
            }
        }

        $this->newLine();
        $this->info("Processing complete: {$this->totalProcessed} processed, {$this->totalErrors} errors");
        if (!$this->isDryRun && ($this->totalProcessed > 0 || !empty($this->completedClients))) {
            $this->sendEmailReport($companyFilter ?: 'ALL');
        }

        return $this->totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function connectionsForCompany(string $companyFilter): array
    {
        return match ($companyFilter) {
            'LDR' => ['ldr'],
            'PLAW' => ['plaw'],
            default => ['ldr', 'plaw'],
        };
    }

    private function fetchEligibleCompletions(DBConnector $connector, string $contactFilter, string $company): array
    {
        // Build WHERE clause for optional contact filter
        $whereClause = '';
        if ($contactFilter !== '') {
            $whereClause = "AND s.LLG_ID = '{$this->escapeSqlString($contactFilter)}' ";
        }

        // Program completion candidates come from a shared SQL Server database.
        // Use Enrollment_Plan to keep LDR and PLAW/Progress Law records separated.
        $whereClause .= $this->buildCompanyCandidateFilter($company, 'e');

        // In force mode with specific contact, skip the 100% and "not processed" checks
        // Otherwise require 100% completion AND not already processed
        if ($this->forceMode && $contactFilter !== '') {
            $havingClause = "";
        } else {
            $havingClause = "HAVING CASE WHEN SUM(e.Debt_Amount) > 0 THEN SUM(s.Debt_Amount) / SUM(e.Debt_Amount) ELSE 0 END >= 1.0
AND s.LLG_ID NOT IN (SELECT LLG_ID FROM TblProgramCompletions)";
        }

        // CRM Contact ID is the numeric part of LLG_ID (after 'LLG-')
        $sql = <<<SQL
SELECT 
    s.LLG_ID,
    s.Client,
    e.Welcome_Call_Date,
    SUM(s.Settlement) AS Total_Settlement_Amounts_Accepted,
    SUM(s.Debt_Amount) AS Original_Debt_Amount_Settled,
    SUM(e.Debt_Amount) AS Enrolled_Debt,
    CASE WHEN SUM(s.Debt_Amount) > 0 THEN SUM(s.Settlement) / SUM(s.Debt_Amount) ELSE 0 END AS Settlement_Rate,
    CASE WHEN SUM(e.Debt_Amount) > 0 THEN SUM(s.Debt_Amount) / SUM(e.Debt_Amount) ELSE 0 END AS Program_Completion,
    MAX(s.Settlement_Date) AS Latest_Settlement_Date
FROM TblSettlementDetails AS s
INNER JOIN TblEnrollment AS e ON s.LLG_ID = e.LLG_ID
WHERE 1=1 {$whereClause}
GROUP BY s.LLG_ID, s.Client, e.Welcome_Call_Date
{$havingClause}
ORDER BY Program_Completion DESC
SQL;

        $result = $connector->querySqlServer($sql);

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('Failed to query eligible completions: ' . ($result['error'] ?? 'Unknown error'));
        }

        // In force mode, if no results from settlements, try to get from TblEnrollment directly
        if (empty($result['data']) && $this->forceMode && $contactFilter !== '') {
            return $this->fetchContactFromEnrollment($connector, $contactFilter, $company);
        }
        
        return $result['data'] ?? [];
    }

    private function buildCompanyCandidateFilter(string $company, string $alias = 'e'): string
    {
        $planColumn = "{$alias}.Enrollment_Plan";
        $categoryColumn = "{$alias}.Category";

        return match (strtoupper($company)) {
            'LDR' => "AND ((UPPER(ISNULL({$planColumn}, '')) LIKE 'LDR%' OR UPPER(ISNULL({$planColumn}, '')) LIKE 'LT L%') OR (({$planColumn} IS NULL OR LTRIM(RTRIM({$planColumn})) = '') AND UPPER(ISNULL({$categoryColumn}, '')) = 'LDR')) ",
            'PLAW' => "AND (UPPER(ISNULL({$planColumn}, '')) LIKE 'PLAW%' OR UPPER(ISNULL({$planColumn}, '')) LIKE '%PROGRESS%' OR UPPER(ISNULL({$categoryColumn}, '')) = 'PLAW') ",
            default => '',
        };
    }

    private function fetchContactFromEnrollment(DBConnector $connector, string $llgId, string $company): array
    {
        $companyFilter = $this->buildCompanyCandidateFilter($company, 'e');
        $sql = "SELECT e.LLG_ID, e.Client, e.Debt_Amount AS Enrolled_Debt, e.Welcome_Call_Date 
                FROM TblEnrollment e WHERE e.LLG_ID = '{$this->escapeSqlString($llgId)}' {$companyFilter}";
        
        $result = $connector->querySqlServer($sql);
        
        if (($result['success'] ?? false) !== true || empty($result['data'])) {
            return [];
        }
        
        $row = $result['data'][0];
        $existingCompletionDate = $this->getRecordedCompletionDate($connector, (string) $row['LLG_ID']);
        return [[
            'LLG_ID' => $row['LLG_ID'],
            'Client' => $row['Client'],
            'Welcome_Call_Date' => $row['Welcome_Call_Date'],
            'Enrolled_Debt' => $row['Enrolled_Debt'],
            'Original_Debt_Amount_Settled' => 0,
            'Settlement_Rate' => 0,
            'Program_Completion' => 0,
            'Latest_Settlement_Date' => $existingCompletionDate ?? date('Y-m-d'),
        ]];
    }

    private function processCompletion(array $completion): void
    {
        $llgId = $completion['LLG_ID'];
        $client = $completion['Client'];
        $company = $completion['company'];
        $connectionName = $completion['connection_name'];
        // CRM Contact ID is the numeric part of LLG_ID (e.g., LLG-322271237 -> 322271237)
        $crmContactId = $this->extractContactId($llgId);
        
        $enrolledDebt = (float) ($completion['Enrolled_Debt'] ?? 0);
        $settledDebt = (float) ($completion['Original_Debt_Amount_Settled'] ?? 0);
        $settlementRate = (float) ($completion['Settlement_Rate'] ?? 0);
        $programCompletion = (float) ($completion['Program_Completion'] ?? 0);
        $latestSettlementDate = $completion['Latest_Settlement_Date'] ?? date('Y-m-d');

        // Build note text matching VBA format
        $note = "Original Debt Enrolled: " . $this->formatCurrency($enrolledDebt) . "\n";
        $note .= "Debt Settled: " . $this->formatCurrency($settledDebt) . "\n";
        $note .= "Overall Settlement Percentage: " . $this->formatPercent($settlementRate);

        if ($this->isDryRun) {
            $connector = $this->createConnector($connectionName);
            $alreadyRecorded = $this->completionAlreadyRecorded($connector, $llgId);

            $this->line("  [DRY RUN] [{$company}] {$llgId} - {$client}");
            $this->line("    CRM Contact ID: " . ($crmContactId ?: 'NOT FOUND'));
            $this->line("    Program Completion: " . $this->formatPercent($programCompletion));
            $this->line("    Enrolled: " . $this->formatCurrency($enrolledDebt));
            $this->line("    Settled: " . $this->formatCurrency($settledDebt));
            $this->line("    Would set: graduated=1, grad_date={$latestSettlementDate}");
            $this->line("    Would set workflow status: " . self::CRM_GRADUATED_STATUS_TITLE);
            if (!$this->skipNote) {
                $this->line("    Would add note:");
                foreach (explode("\n", $note) as $noteLine) {
                    $this->line("      " . $noteLine);
                }
            }
            $this->line($alreadyRecorded
                ? "    Would skip TblProgramCompletions insert (already recorded)"
                : "    Would insert into TblProgramCompletions");
            $this->totalGraduated++;
            $this->completedClients[] = [
                'llg_id' => $llgId,
                'client_name' => $client,
                'original_debt' => $this->formatCurrency($enrolledDebt),
                'settled_debt' => $this->formatCurrency($settledDebt),
                'settlement_pct' => $this->formatPercent($settlementRate),
                'crm_status' => $crmContactId ? 'Would Update' : 'Skipped',
                'note_status' => (!$this->skipNote && $crmContactId) ? 'Would Create' : 'Skipped',
            ];
            return;
        }

        // LIVE MODE
        $this->line("  [{$company}] Processing {$llgId} - {$client}...");

        // Validate CRM Contact ID
        if (!$crmContactId) {
            $this->warn("    WARNING: No CRM Contact ID found - skipping CRM updates");
        }

        // 1. Insert into TblProgramCompletions
        $connector = $this->createConnector($connectionName);

        if ($this->completionAlreadyRecorded($connector, $llgId)) {
            $this->line("    OK TblProgramCompletions already contains {$llgId} - skipping insert");
        } else {
            $insertSql = sprintf(
                "INSERT INTO TblProgramCompletions (LLG_ID, Client, Completetion_Date) VALUES ('%s', '%s', '%s')",
                $this->escapeSqlString($llgId),
                $this->escapeSqlString($client),
                $this->escapeSqlString($latestSettlementDate)
            );

            $insertResult = $connector->querySqlServer($insertSql);
            if (($insertResult['success'] ?? false) !== true) {
                throw new \RuntimeException('Failed to insert into TblProgramCompletions: ' . ($insertResult['error'] ?? 'Unknown'));
            }
            $this->line("    OK Inserted into TblProgramCompletions");
        }

        // 2. Update CRM status to "Graduated / Completed" (only if we have CRM Contact ID)
        if ($crmContactId) {
            $this->updateCRMStatus($crmContactId, $company, $connector, $latestSettlementDate);
            $this->line("    OK CRM status updated to " . self::CRM_GRADUATED_STATUS_TITLE);
            $this->crmUpdates++;

            // 3. Add CRM note (unless skipped)
            if (!$this->skipNote) {
                $this->addCRMNote($crmContactId, $note, $company, $connector);
                $this->line("    OK CRM note added");
                $this->notesCreated++;
            }
        }

        $this->line("    OK Complete");
        
        // Track for report
        $this->totalGraduated++;
        $this->completedClients[] = [
            'llg_id' => $llgId,
            'client_name' => $client,
            'original_debt' => $this->formatCurrency($enrolledDebt),
            'settled_debt' => $this->formatCurrency($settledDebt),
            'settlement_pct' => $this->formatPercent($settlementRate),
            'crm_status' => $crmContactId ? 'Success' : 'Skipped',
            'note_status' => ($crmContactId && !$this->skipNote) ? 'Success' : 'Skipped',
        ];
    }

    private function updateCRMStatus(string $contactId, string $company, DBConnector $connector, string $gradDate): void
    {
        $apiKey = $this->getApiKey($company, $connector);
        $workflowStatusId = $this->getGraduatedWorkflowStatusId($company, $apiKey);
        $this->updateCRMWorkflowStatus($contactId, $apiKey, $workflowStatusId);
        $this->updateCRMGraduationFields($contactId, $apiKey, $gradDate);
        $this->updateCRMStatusLabel($contactId, $company, self::CRM_GRADUATED_STATUS_TITLE);
    }

    private function updateCRMWorkflowStatus(string $contactId, string $apiKey, int $statusId): void
    {
        $url = self::CRM_BASE_URL . "/contacts/{$contactId}/workflow";
        $payload = ['statusID' => $statusId];

        if ($this->verboseLog) {
            $this->line("     CRM PUT {$url}");
            $this->line("     Api-Key: " . substr($apiKey, 0, 12) . "...");
            $this->line("     Body: " . json_encode($payload));
        }

        $client = $this->getHttpClient();
        $response = $client->request('PUT', $url, [
            'headers' => [
                'Api-Key' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($this->verboseLog) {
            $this->line("     Response: HTTP {$statusCode}");
            $this->line("     Body: " . substr($body, 0, 200));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('CRM workflow status update failed: HTTP ' . $statusCode . ' - ' . $body);
        }
    }

    private function updateCRMGraduationFields(string $contactId, string $apiKey, string $gradDate): void
    {
        $url = self::CRM_BASE_URL . "/contacts/{$contactId}";
        $payload = [
            'graduated' => 1,
            'grad_date' => $gradDate,
        ];

        if ($this->verboseLog) {
            $this->line("     CRM PUT {$url}");
            $this->line("     Api-Key: " . substr($apiKey, 0, 12) . "...");
            $this->line("     Body: " . json_encode($payload));
        }

        $client = $this->getHttpClient();
        $response = $client->request('PUT', $url, [
            'headers' => [
                'Api-Key' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        if ($this->verboseLog) {
            $this->line("     Response: HTTP {$statusCode}");
            $this->line("     Body: " . substr($body, 0, 200));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('CRM graduation field update failed: HTTP ' . $statusCode . ' - ' . $body);
        }
    }

    private function updateCRMStatusLabel(string $contactId, string $company, string $status): void
    {
        $crmLoginKey = $this->getCrmLoginApiKey($company);
        $url = "https://login.debtpaypro.com/post/{$crmLoginKey}/?" . http_build_query([
            'updaterecord'  => $contactId,
            'client_status' => $status,
        ]);

        if ($this->verboseLog) {
            $this->line("     CRM status label update: client_status={$status}");
        }

        $client = $this->getHttpClient();
        $response = $client->request('GET', $url);
        $statusCode = $response->getStatusCode();
        $responseBody = trim((string) $response->getBody());

        if ($this->verboseLog) {
            $this->line("     Response: HTTP {$statusCode} - {$responseBody}");
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException("CRM status label update failed: HTTP {$statusCode} - {$responseBody}");
        }

        $parts = explode(':', $responseBody);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            throw new \RuntimeException("CRM status label update failed: {$responseBody}");
        }
    }

    private function addCRMNote(string $contactId, string $note, string $company, DBConnector $connector): void
    {
        // Use login.debtpaypro.com endpoint with notebody (same as VBA)
        $crmLoginKey = $this->getCrmLoginApiKey($company);
        $url = "https://login.debtpaypro.com/post/{$crmLoginKey}/?updaterecord={$contactId}&notebody=" . urlencode($note);
        
        if ($this->verboseLog) {
            $this->line("     CRM POST login.debtpaypro.com notebody");
            $this->line("     Note content: " . substr(str_replace("\n", " | ", $note), 0, 80) . "...");
        }
        
        $client = $this->getHttpClient();
        $response = $client->request('POST', $url);

        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        
        if ($this->verboseLog) {
            $this->line("     Response: HTTP {$statusCode} - {$body}");
        }
        
        // Check for success response (e.g., "Success:1153799588")
        if ($statusCode < 200 || $statusCode >= 300 || stripos($body, 'Success') === false) {
            throw new \RuntimeException('CRM note creation failed: HTTP ' . $statusCode . ' - ' . $body);
        }
    }

    private function getCrmLoginApiKey(string $company): string
    {
        $company = strtoupper($company);
        $key = env("CRM_LOGIN_API_KEY_{$company}");
        
        if (empty($key)) {
            throw new \RuntimeException("CRM Login API key not found for company: {$company}. Set CRM_LOGIN_API_KEY_{$company} in .env");
        }
        
        return $key;
    }

    private function getApiKey(string $company, DBConnector $connector): string
    {
        // Check cache first
        if (isset($this->apiKeys[$company])) {
            return $this->apiKeys[$company];
        }

        // Fetch from TblAPIKeys
        $sql = sprintf(
            "SELECT API_Key FROM TblAPIKeys WHERE Category = '%s'",
            $this->escapeSqlString($company)
        );
        
        $result = $connector->querySqlServer($sql);
        
        if (!($result['success'] ?? false) || empty($result['data'])) {
            throw new \RuntimeException("API key not found for company: {$company}");
        }

        $apiKey = $result['data'][0]['API_Key'];
        $this->apiKeys[$company] = $apiKey;  // Cache it
        
        return $apiKey;
    }

    private function getHttpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'timeout' => 30,
                'http_errors' => false,  // Don't throw on 4xx/5xx
                'verify' => false,
            ]);
        }
        return $this->httpClient;
    }

    private function extractContactId(string $llgId): string
    {
        // LLG_ID format: "LLG-348935810" or "EDLS-180933206" -> extract numeric part
        return preg_replace('/^[A-Z]+-/', '', $llgId) ?? $llgId;
    }

    private function getGraduatedWorkflowStatusId(string $company, string $apiKey): int
    {
        if (isset($this->graduatedWorkflowStatusIds[$company])) {
            return $this->graduatedWorkflowStatusIds[$company];
        }

        $response = $this->getHttpClient()->request('GET', self::CRM_BASE_URL . '/contact-statuses', [
            'headers' => [
                'Api-Key' => $apiKey,
                'Accept' => 'application/json',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('Unable to load CRM workflow statuses: HTTP ' . $statusCode . ' - ' . $body);
        }

        $decoded = json_decode($body, true);
        $results = $decoded['response']['results'] ?? [];

        foreach ($results as $row) {
            if (strcasecmp((string) ($row['title'] ?? ''), self::CRM_GRADUATED_STATUS_TITLE) === 0) {
                $statusId = (int) ($row['id'] ?? 0);
                if ($statusId > 0) {
                    $this->graduatedWorkflowStatusIds[$company] = $statusId;
                    return $statusId;
                }
            }
        }

        throw new \RuntimeException('Unable to resolve CRM workflow status ID for ' . self::CRM_GRADUATED_STATUS_TITLE . '.');
    }

    private function completionAlreadyRecorded(DBConnector $connector, string $llgId): bool
    {
        $sql = sprintf(
            "SELECT TOP 1 1 AS found FROM TblProgramCompletions WHERE LLG_ID = '%s'",
            $this->escapeSqlString($llgId)
        );

        $result = $connector->querySqlServer($sql);

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('Failed to check TblProgramCompletions: ' . ($result['error'] ?? 'Unknown'));
        }

        return !empty($result['data']);
    }

    private function getRecordedCompletionDate(DBConnector $connector, string $llgId): ?string
    {
        $sql = sprintf(
            "SELECT TOP 1 Completetion_Date FROM TblProgramCompletions WHERE LLG_ID = '%s' ORDER BY Completetion_Date DESC",
            $this->escapeSqlString($llgId)
        );

        $result = $connector->querySqlServer($sql);

        if (($result['success'] ?? false) !== true) {
            throw new \RuntimeException('Failed to read TblProgramCompletions date: ' . ($result['error'] ?? 'Unknown'));
        }

        $value = $result['data'][0]['Completetion_Date'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function printSummary(array $summaries, int $totalBefore, int $selected): void
    {
        $this->newLine();
        $this->info('Program Completions Summary');
        $this->line(str_repeat('=', 39));

        foreach ($summaries as $summary) {
            $this->line(sprintf('  [%s] Eligible: %d', $summary['source'], $summary['eligible']));
        }

        $this->line("  Total eligible: {$totalBefore}");
        $this->line("  Selected this run: {$selected}");
        $this->line('  Mode: ' . ($this->isDryRun ? 'DRY RUN (preview)' : 'LIVE'));
        $this->line('  Skip note: ' . ($this->skipNote ? 'yes' : 'no'));
        $this->newLine();
    }

    private function sendEmailReport(string $company): void
    {
        $this->info('Sending email report...');

        try {
            $reportConnectorName = $this->currentConnection !== ''
                ? $this->currentConnection
                : $this->resolveReportConnectorName($company);
            $connector = $this->createConnector($reportConnectorName);

            $reportData = new ReportData();
            $reportData
                ->setConnection($company)
                ->setDryRun($this->isDryRun)
                ->setStats(
                    $this->totalProcessed,
                    $this->totalGraduated,
                    $this->crmUpdates,
                    $this->notesCreated,
                    $this->totalErrors
                )
                ->setCompletedClients($this->completedClients)
                ->setErrors($this->errorDetails);

            $formatter = new ReportFormatter();
            $sent = $formatter->sendReport($connector, $reportData, $this);

            if ($sent) {
                Log::info('ProcessProgramCompletions: email report sent.', ['company' => $company]);
            } else {
                Log::warning('ProcessProgramCompletions: email report failed or no recipients.', ['company' => $company]);
            }
        } catch (\Throwable $e) {
            $this->warn("  Failed to send email report: {$e->getMessage()}");
            Log::error('ProcessProgramCompletions: email report error.', ['exception' => $e]);
        }
    }

    private function normalizeContactId(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(LLG|LDR|PLAW|LT)-(\d+)$/i', $value, $matches)) {
            return 'LLG-' . $matches[2];
        }

        // If numeric only, add LLG- prefix
        if (preg_match('/^\d+$/', $value)) {
            return 'LLG-' . $value;
        }

        return $value;
    }

    private function createConnector(string $connectionName): DBConnector
    {
        $connector = DBConnector::fromEnvironment($connectionName);
        $connector->initializeSqlServer();

        return $connector;
    }

    private function resolveReportConnectorName(string $company): string
    {
        $company = strtoupper(trim($company));
        if (in_array($company, ['LDR', 'PLAW'], true)) {
            return strtolower($company);
        }

        return 'ldr';
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    private function formatCurrency(float $value): string
    {
        return '$' . number_format($value, 0);
    }

    private function formatPercent(float $value): string
    {
        return number_format($value * 100, 0) . '%';
    }
}



