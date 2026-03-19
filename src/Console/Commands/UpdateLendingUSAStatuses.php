<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Console\Commands\LendingUSAStatusReport\ReportData;
use Cmd\Reports\Console\Commands\LendingUSAStatusReport\ReportFormatter;
use Cmd\Reports\Services\CognitoSrpAuth;
use Cmd\Reports\Services\DBConnector;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateLendingUSAStatuses extends Command
{
    protected $signature = 'lending-usa:update-statuses
        {--connection= : Snowflake environment (plaw, ldr, lt). Leave empty to run both LDR and PLAW}
        {--crm-key= : CRM login API key override}
        {--dry-run : Simulate without writing anything}
        {--limit=0 : Limit number of API records to fetch (0=all)}
        {--contact-id= : Process only this specific contact ID}
        {--verbose-dry : Show detailed per-client actions in dry run}';

    protected $description = 'Sync LendingUSA application statuses via API, replacing VBA UpdateLendingUSAStatuses macro.';

    protected const BLOCKED_STATES = ['CT', 'IA', 'KS', 'MA', 'NH', 'ND', 'SC', 'VT', 'WV'];

    protected const LENDING_API_BASE = 'https://api.lendingusa.com';
    protected const LENDING_CHANNEL_CODE = 'fsl';
    protected const PAGE_SIZE = 100;

    protected const ACCOUNT_CONFIG = [
        'ldr' => [
            'env_user' => 'LENDING_USA_USERNAME_LDR',
            'env_pass' => 'LENDING_USA_PASSWORD_LDR',
            'env_merchant' => 'LENDING_USA_MERCHANT_ID_LDR',
        ],
        'plaw' => [
            'env_user' => 'LENDING_USA_USERNAME_PLAW',
            'env_pass' => 'LENDING_USA_PASSWORD_PLAW',
            'env_merchant' => 'LENDING_USA_MERCHANT_ID_PLAW',
        ],
    ];

    protected ?DBConnector $connector = null;
    protected ?Client $httpClient = null;
    protected bool $dryRun = false;
    protected bool $verboseDry = false;
    protected string $crmKey = '';
    protected string $currentConnection = 'plaw';
    protected ?string $forthApiKeyCache = null;

    // Counters
    protected int $totalFetched = 0;
    protected int $totalFiltered = 0;
    protected int $totalProcessed = 0;
    protected int $totalSkipped = 0;
    protected int $totalFunded = 0;
    protected int $totalCrmUpdated = 0;
    protected int $totalErrors = 0;

    // Detailed tracking for manager report
    protected array $statusBreakdown = [];
    protected array $changedRecords = [];
    protected array $fundedClients = [];
    protected array $declinedClients = [];
    protected array $newClients = [];

    // Batch-loaded caches for performance optimization
    protected array $enrollmentStatesCache = [];
    protected array $enrollmentPlansCache = [];
    protected array $clientNamesCache = [];
    protected array $fundedClientsCache = [];

    public function handle(): int
    {
        $connection = (string) ($this->option('connection') ?? '');
        $this->dryRun = (bool) $this->option('dry-run');
        $this->verboseDry = (bool) $this->option('verbose-dry');

        // If no connection specified, run both LDR and PLAW
        if ($connection === '') {
            $this->info("=== LendingUSA Status Sync [BOTH LDR & PLAW]" . ($this->dryRun ? ' (DRY RUN)' : '') . " ===");
            $this->newLine();
            
            $ldrResult = $this->runForConnection('ldr');
            $this->newLine(2);
            $plawResult = $this->runForConnection('plaw');
            
            return ($ldrResult === Command::SUCCESS && $plawResult === Command::SUCCESS) 
                ? Command::SUCCESS 
                : Command::FAILURE;
        }

        // Single connection mode
        return $this->runForConnection($connection);
    }

    protected function runForConnection(string $connection): int
    {
        // Reset counters and tracking arrays for this connection
        $this->resetCounters();
        $this->currentConnection = $connection;
        
        $source = strtoupper($connection);
        $this->info("=== LendingUSA Status Sync [{$source}]" . ($this->dryRun ? ' (DRY RUN)' : '') . " ===");
        Log::info('UpdateLendingUSAStatuses: started.', ['connection' => $connection, 'dry_run' => $this->dryRun]);

        try {
            // 1. Initialize DB connections
            $this->info('[1/6] Initializing database connections...');
            $this->connector = DBConnector::fromEnvironment($connection);
            $this->connector->initializeSqlServer();
            $this->info('  Snowflake + SQL Server: OK');

            // 2. Resolve CRM key
            $this->crmKey = $this->resolveCrmLoginKey($connection, (string) ($this->option('crm-key') ?? ''));
            if ($this->crmKey === '' && !$this->dryRun) {
                $this->error('CRM login API key not set. Use --crm-key or set CRM_LOGIN_API_KEY / CRM_LOGIN_API_KEY_PLAW.');
                return Command::FAILURE;
            }
            $this->info('  CRM key: ' . ($this->crmKey !== '' ? 'resolved' : 'not set (dry-run OK)'));

            // 3. Load current status snapshot from TblLendingUSAStatuses
            $this->info('[2/6] Loading current status snapshot...');
            $statusSnapshot = $this->loadStatusSnapshot();
            $this->info('  Active statuses in DB: ' . count($statusSnapshot));

            // 4. Fetch LendingUSA applications via API
            $this->info('[3/6] Fetching LendingUSA applications via API...');
            $applications = $this->fetchApplications();
            $this->info("  Raw records fetched: {$this->totalFetched}");

            // 5. Normalize and dedupe
            $this->info('[4/6] Normalizing and deduplicating...');
            $normalized = $this->normalizeAndDedupe($applications);
            $this->info("  After filter/dedupe: " . count($normalized) . " (filtered out: {$this->totalFiltered})");

            // 6. Process each client
            $this->info('[5/6] Processing clients...');
            $this->httpClient = new Client(['timeout' => 30, 'verify' => false]);
            $processTime = now()->format('Y-m-d H:i:s');

            $filterContactId = (string) ($this->option('contact-id') ?? '');
            if ($filterContactId !== '') {
                $this->info("  Filtering for contact ID: {$filterContactId}");
            }

            // Batch load all client data upfront for performance
            $clientIds = array_column($normalized, 'llgid');
            if (!empty($clientIds)) {
                $this->batchLoadClientData($clientIds);
            }

            foreach ($normalized as $record) {
                // Skip if filtering for specific contact ID
                if ($filterContactId !== '' && $record['llgid'] !== $filterContactId) {
                    continue;
                }
                $this->processClient($record, $statusSnapshot, $processTime);
            }

            // 7. Summary
            $this->printManagerReport($source, count($normalized));

            // 8. Send email report (skip in dry-run mode unless explicitly requested)
            if (!$this->dryRun) {
                $this->sendEmailReport($connection);
            }

            Log::info('UpdateLendingUSAStatuses: finished.', [
                'connection' => $connection,
                'dry_run' => $this->dryRun,
                'fetched' => $this->totalFetched,
                'processed' => $this->totalProcessed,
                'funded' => $this->totalFunded,
                'crm_updated' => $this->totalCrmUpdated,
                'errors' => $this->totalErrors,
            ]);

            return $this->totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Fatal error: {$e->getMessage()}");
            Log::error('UpdateLendingUSAStatuses: fatal error.', ['exception' => $e]);
            return Command::FAILURE;
        }
    }

    // ─── Step 2: Load Status Snapshot ─────────────────────────────────

    protected function loadStatusSnapshot(): array
    {
        $sql = "SELECT CID, Status FROM TblLendingUSAStatuses WHERE Expired = 'FALSE'";
        $result = $this->connector->querySqlServer($sql);

        $map = [];
        foreach ($result['data'] ?? [] as $row) {
            $cid = (string) $row['CID'];
            $map[$cid] = (string) $row['Status'];
        }

        return $map;
    }

    // ─── Step 3: Fetch Applications from LendingUSA API ───────────────

    protected function fetchApplications(): array
    {
        $connection = $this->currentConnection;
        $idToken = $this->authenticateLendingUSA($connection);
        if ($idToken === null) {
            throw new \RuntimeException('Failed to authenticate with LendingUSA (Cognito).');
        }
        $this->info('  LendingUSA auth: OK');

        $merchantId = $this->getMerchantId($connection);
        $this->info("  Merchant ID: {$merchantId}");

        $all = [];
        $offset = 0;
        $limit = self::PAGE_SIZE;
        $fetchLimit = (int) $this->option('limit');

        while (true) {
            $url = self::LENDING_API_BASE . '/v1/applications-view/search?' . http_build_query([
                'merchantId' => $merchantId,
                'channelCode' => self::LENDING_CHANNEL_CODE,
                'sortBy' => 'InitiatedDate.DateTime',
                'sortOrder' => 'desc',
                'visible' => 'true',
                'offset' => $offset,
                'limit' => $limit,
            ]);

            $response = $this->httpClient()->get($url, [
                'headers' => [
                    'Authorization' => $idToken,
                    'Accept' => 'application/json',
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $records = $body['data'] ?? [];

            if (!is_array($records) || empty($records)) {
                break;
            }

            $all = array_merge($all, $records);
            $this->totalFetched += count($records);

            $this->line("  Fetched page: offset={$offset}, got=" . count($records)
                . ', total so far=' . $this->totalFetched
                . ' / ' . ($body['pagination']['total'] ?? '?'));

            if ($fetchLimit > 0 && $this->totalFetched >= $fetchLimit) {
                $all = array_slice($all, 0, $fetchLimit);
                $this->totalFetched = count($all);
                break;
            }

            if (count($records) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return $all;
    }

    // ─── Step 4: Normalize and Dedupe ─────────────────────────────────

    protected function normalizeAndDedupe(array $applications): array
    {
        $normalized = [];
        $seenIds = [];

        foreach ($applications as $app) {
            // Extract LLGID from channelAttributes.clientId
            // LDR format: "LDR-1071672867", PLAW format: "492409830"
            $rawClientId = $app['channelAttributes']['clientId'] ?? '';
            $llgid = $this->numericOnly($rawClientId);

            if ($llgid === '') {
                $this->totalFiltered++;
                continue;
            }

            // Extract status from statusLabel (human-readable)
            // e.g. "App Initiated", "Funded", "Declined"
            $status = (string) ($app['statusLabel'] ?? $app['statusName'] ?? '');

            // Track status breakdown for report
            $this->statusBreakdown[$status] = ($this->statusBreakdown[$status] ?? 0) + 1;

            // Filter Duplicate/Expired
            if (in_array($status, ['Duplicate', 'Expired'], true)) {
                $this->totalFiltered++;
                continue;
            }

            // Dedupe by LLGID (keep first occurrence)
            if (isset($seenIds[$llgid])) {
                $this->totalFiltered++;
                continue;
            }
            $seenIds[$llgid] = true;

            // Extract status date (ISO format, take date part)
            $statusDateRaw = (string) ($app['statusDate'] ?? '');
            $statusDate = substr($statusDateRaw, 0, 10); // "2026-03-02"

            $normalized[] = [
                'llgid' => $llgid,
                'status' => $status,
                'status_date' => $statusDate,
                'raw' => $app,
            ];
        }

        return $normalized;
    }

    // ─── Step 5: Process Each Client ──────────────────────────────────

    protected function processClient(array $record, array $statusSnapshot, string $processTime): void
    {
        $llgid = $record['llgid'];
        $status = $record['status'];
        $statusDate = $record['status_date'];

        // --- Proceed decision ---
        $proceed = false;
        if (!isset($statusSnapshot[$llgid])) {
            $proceed = true; // new client
        } elseif ($statusSnapshot[$llgid] !== $status) {
            $proceed = true; // status changed
        }

        if (!$proceed) {
            $this->totalSkipped++;
            return;
        }

        // Fetch State from TblEnrollment
        $state = $this->getEnrollmentState($llgid);

        // Fetch Enrollment Plan from Snowflake
        $enrollmentPlan = $this->getEnrollmentPlanTitle($llgid);
        $plan = $this->categorizePlan($enrollmentPlan);
        
        // If plan is empty (client not found in this connection's Snowflake),
        // getLLGStatus() will return '' → CRM update skipped. This is correct
        // per VBA: no plan = no CRM mapping.

        // Fetch Client Name from Snowflake
        $clientName = $this->getClientName($llgid);

        // Blocked state check
        if (in_array(strtoupper(trim($state)), self::BLOCKED_STATES, true)) {
            if ($this->verboseDry) {
                $this->warn("  [BLOCKED] CID {$llgid} state={$state}");
            }
            $this->totalSkipped++;
            return;
        }

        $this->totalProcessed++;
        $isNew = !isset($statusSnapshot[$llgid]);
        $oldStatus = $statusSnapshot[$llgid] ?? 'N/A';

        if ($this->verboseDry || $this->dryRun) {
            $label = $isNew ? 'NEW' : 'CHANGED';
            $this->line("  [{$label}] CID={$llgid} | {$oldStatus} → {$status} | Plan={$plan} | State={$state}");
        }

        // --- A) CRM status update (also resolves client name from CRM if needed) ---
        $crmContactData = null;
        $crmStatus = $this->handleCrmStatusUpdate($llgid, $status, $plan, $crmContactData);

        // Fallback: get client name from CRM if Snowflake didn't have it
        if ($clientName === '' && $crmContactData !== null) {
            $fn = trim((string) ($crmContactData['first_name'] ?? ''));
            $ln = trim((string) ($crmContactData['last_name'] ?? ''));
            $clientName = trim("{$fn} {$ln}");
        }

        // --- B) Funded workflow (after name is resolved) ---
        if ($status === 'Funded') {
            $this->handleFundedWorkflow($llgid, $clientName, $plan, $oldStatus, $state);
        }

        // Track for manager report (after name is resolved)
        if ($isNew) {
            $this->newClients[] = ['cid' => $llgid, 'name' => $clientName, 'status' => $status, 'state' => $state, 'plan' => $plan];
        }
        if (in_array($status, ['Declined', 'Disqualified', 'Withdrawn'], true)) {
            $this->declinedClients[] = ['cid' => $llgid, 'name' => $clientName, 'prev_status' => $oldStatus, 'new_status' => $status, 'state' => $state];
        }

        // Track changed records for report
        $this->changedRecords[] = [
            'cid' => $llgid,
            'name' => $clientName,
            'old_status' => $oldStatus,
            'new_status' => $status,
            'crm_status' => $crmStatus,
            'state' => $state,
        ];

        // --- C) Record status history ---
        $this->recordStatusHistory($llgid, $status, $processTime, $statusDate);
    }

    // ─── Funded Workflow ──────────────────────────────────────────────

    protected function handleFundedWorkflow(string $llgid, string $clientName, string $plan, string $prevStatus = 'N/A', string $state = ''): void
    {
        // Check if already funded (uses cache if available)
        if ($this->isAlreadyFunded($llgid)) {
            if ($this->verboseDry) {
                $this->line("    [FUNDED] CID {$llgid} already in TblLendingUSAFunded - skipping");
            }
            return;
        }

        $this->totalFunded++;
        $today = date('Y-m-d');
        $negotiator = $this->findLeastAssignedNegotiator();

        // Track for manager report
        $this->fundedClients[] = [
            'cid' => $llgid,
            'name' => $clientName,
            'prev_status' => $prevStatus,
            'state' => $state,
            'negotiator' => $negotiator ?: 'Pending',
        ];

        if ($this->dryRun) {
            $this->line("    [DRY-RUN] Would INSERT into TblLendingUSAFunded (LLG_ID={$llgid}, Funded_Date={$today})");
            $this->line("    [DRY-RUN] Would PAUSE CRM contact {$llgid}");
            $this->line("    [DRY-RUN] Would assign negotiator: {$negotiator}");
            $this->line("    [DRY-RUN] Would UPDATE TblNegotiatorAssignments (CID=LLG-{$llgid})");
            $this->line("    [DRY-RUN] Would INSERT into TblNegotiatorFundingAssignments");
            return;
        }

        // Insert into TblLendingUSAFunded
        $sql = "INSERT INTO TblLendingUSAFunded (LLG_ID, Funded_Date) VALUES ('{$this->esc($llgid)}', '{$today}')";
        $this->connector->querySqlServer($sql);

        // Pause CRM contact via API
        $this->pauseCrmContact($llgid);

        // Assign negotiator (reuse already-resolved negotiator)
        if ($negotiator !== '') {
            // Update TblNegotiatorAssignments
            $sql = "UPDATE TblNegotiatorAssignments SET "
                . "Negotiator = '{$this->esc($negotiator)}'"
                . ", Ready_To_Settle_Date = '{$today}'"
                . " WHERE CID = 'LLG-{$this->esc($llgid)}'"
                . " AND Settlement_ID IS NULL";
            $this->connector->querySqlServer($sql);

            // Insert TblNegotiatorFundingAssignments
            $sql = "INSERT INTO TblNegotiatorFundingAssignments (CID, Negotiator, Assigned_Date) "
                . "VALUES ('LLG-{$this->esc($llgid)}', '{$this->esc($negotiator)}', '{$today}')";
            $this->connector->querySqlServer($sql);

            $this->info("    [FUNDED] CID {$llgid} → negotiator: {$negotiator}");
        } else {
            $this->warn("    [FUNDED] CID {$llgid} → no available negotiator found!");
            $this->totalErrors++;
        }
    }

    // ─── CRM Status Update ────────────────────────────────────────────

    protected function handleCrmStatusUpdate(string $llgid, string $lendingStatus, string $plan, ?array &$outContactData = null): string
    {
        // Check if contact exists in CRM
        $contactData = $this->getCrmContactData($llgid);
        $outContactData = $contactData;

        // If contact not found in CRM, skip CRM update (not an error - just doesn't exist)
        if ($contactData === null) {
            if ($this->verboseDry) {
                $this->line("    [SKIP-CRM] CID {$llgid} not found in CRM");
            }
            return '(Not in CRM)';
        }

        $currentCrmStatus = $contactData['status_label'] ?? $contactData['client_status'] ?? $contactData['leadstatus'] ?? '';

        // Check if contact is Graduated/Completed or Cancelled
        if (stripos($currentCrmStatus, 'Graduated') !== false
            || stripos($currentCrmStatus, 'Completed') !== false) {
            if ($this->verboseDry) {
                $this->line("    [SKIP-CRM] CID {$llgid} is Graduated/Completed ('{$currentCrmStatus}')");
            }
            return '(Graduated)';
        }
        if (stripos($currentCrmStatus, 'Cancel') !== false) {
            if ($this->verboseDry) {
                $this->line("    [SKIP-CRM] CID {$llgid} is Cancelled ('{$currentCrmStatus}')");
            }
            return '(Cancelled)';
        }

        // Translate LendingUSA status to CRM status
        $crmStatus = $this->getLLGStatus($lendingStatus, $plan);

        if ($crmStatus === '') {
            // Per VBA: no CRM update for unmapped statuses (e.g. "Not Interested", "Hold")
            // but status history is still recorded. Not an error, just skipped.
            if ($this->verboseDry) {
                $this->line("    [SKIP-CRM] CID {$llgid}: No CRM mapping for '{$lendingStatus}' (plan={$plan})");
            }
            return '(No mapping)';
        }

        // Check if CRM already has this status (reuse $currentCrmStatus from line above)
        if (strcasecmp($currentCrmStatus, $crmStatus) === 0) {
            if ($this->verboseDry) {
                $this->line("    [SKIP-CRM] CID {$llgid} already has status '{$crmStatus}'");
            }
            return '(No change)';
        }

        if ($this->dryRun) {
            $this->line("    [DRY-RUN] Would update CRM status for CID {$llgid}: '{$lendingStatus}' → '{$crmStatus}'");
            $this->totalCrmUpdated++;
            return $crmStatus;
        }

        // Update CRM status via POST endpoint
        if ($this->updateCrmStatus($llgid, $crmStatus)) {
            $this->totalCrmUpdated++;
            if ($this->verboseDry) {
                $this->info("    [CRM] CID {$llgid} → {$crmStatus}");
            }
            return $crmStatus;
        } else {
            $this->totalErrors++;
            return '(Error)';
        }
    }

    // ─── Record Status History ────────────────────────────────────────

    protected function recordStatusHistory(string $llgid, string $status, string $processTime, string $statusDate): void
    {
        if ($this->dryRun) {
            $this->line("    [DRY-RUN] Would expire old statuses and insert new for CID {$llgid}: '{$status}'");
            return;
        }

        // Expire old rows
        $sql = "UPDATE TblLendingUSAStatuses SET Expired = 'TRUE' WHERE CID = '{$this->esc($llgid)}'";
        $this->connector->querySqlServer($sql);

        // Insert new row
        $sql = "INSERT INTO TblLendingUSAStatuses (CID, Status, Processed_Time, Status_Date, Expired) VALUES ("
            . "'{$this->esc($llgid)}'"
            . ", '{$this->esc($status)}'"
            . ", '{$this->esc($processTime)}'"
            . ", '{$this->esc($statusDate)}'"
            . ", 'FALSE')";
        $this->connector->querySqlServer($sql);
    }

    // ─── LendingUSA Auth (Cognito) ────────────────────────────────────

    protected function authenticateLendingUSA(string $connection): ?string
    {
        $config = self::ACCOUNT_CONFIG[$connection] ?? self::ACCOUNT_CONFIG['plaw'];

        $username = env($config['env_user']);
        $password = env($config['env_pass']);

        if (empty($username) || empty($password)) {
            $this->error("LendingUSA credentials not set. Set {$config['env_user']} and {$config['env_pass']} in .env");
            return null;
        }

        try {
            $auth = new CognitoSrpAuth();
            $tokens = $auth->authenticate($username, $password);

            if (empty($tokens['IdToken'])) {
                $this->error('Cognito auth returned no IdToken');
                return null;
            }

            return $tokens['IdToken'];
        } catch (\Throwable $e) {
            $this->error('Cognito SRP auth failed: ' . $e->getMessage());
            return null;
        }
    }

    protected function getMerchantId(string $connection): string
    {
        $config = self::ACCOUNT_CONFIG[$connection] ?? self::ACCOUNT_CONFIG['plaw'];
        return (string) env($config['env_merchant'], '');
    }

    // ─── CRM Interactions ─────────────────────────────────────────────

    protected function getCrmContactData(string $contactId): ?array
    {
        if ($this->dryRun && $this->crmKey === '') {
            return null; // Can't check CRM in dry-run without key
        }

        $apiKey = $this->resolveForthApiKey();
        if ($apiKey === '') {
            return null;
        }

        try {
            $response = $this->httpClient()->get("https://api.forthcrm.com/v1/contacts/{$contactId}", [
                'headers' => [
                    'Api-Key' => $apiKey,
                    'Accept' => 'application/json',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            return $data['response'] ?? $data ?? null;
        } catch (\Throwable $e) {
            // 404 = contact not found, not an error
            return null;
        }
    }

    // LUSA Status ID mapping from Forth CRM
    protected const LUSA_STATUS_IDS = [
        // LDR statuses
        'LDR Enrolled (LUSA-App)' => 378200,
        'LDR Enrolled (LUSA-PQ)' => 378201,
        'LDR Enrolled (LUSA-Approved)' => 378203,
        'LDR Enrolled (LUSA-APPROVED)' => 378203,
        'LDR Enrolled (LUSA-Funded)' => 378204,
        'LDR Enrolled (LUSA-FUNDED)' => 378204,
        'LDR Enrolled (LUSA-Declined)' => 378205,
        'LDR Enrolled (LUSA-DECLINED)' => 378205,
        'LDR Enrolled (LUSA-Withdrawn)' => 378206,
        'LDR Enrolled (LUSA-WITHDRAWN)' => 378206,
        // PLAW statuses
        'PLAW Enrolled (LUSA-App)' => 378215,
        'PLAW Enrolled (LUSA-PQ)' => 378216,
        'PLAW Enrolled (LUSA-Approved)' => 378217,
        'PLAW Enrolled (LUSA-APPROVED)' => 378217,
        'PLAW Enrolled (LUSA-Funded)' => 378218,
        'PLAW Enrolled (LUSA-FUNDED)' => 378218,
        'PLAW Enrolled (LUSA-Declined)' => 378219,
        'PLAW Enrolled (LUSA-DECLINED)' => 378219,
        'PLAW Enrolled (LUSA-Withdrawn)' => 378220,
        'PLAW Enrolled (LUSA-WITHDRAWN)' => 378220,
        // ProLaw statuses (verified from PLAW Snowflake CONTACTS_LEAD_STATUS)
        'ProLaw Enrolled (LUSA-App)' => 378139,
        'ProLaw Enrolled (LUSA-PQ)' => 378140,
        'ProLaw Enrolled (LUSA-Approved)' => 378141,
        'ProLaw Enrolled (LUSA-APPROVED)' => 378141,
        'ProLaw Enrolled (LUSA-Funded)' => 378142,
        'ProLaw Enrolled (LUSA-FUNDED)' => 378142,
        'ProLaw Enrolled (LUSA-Declined)' => 378143,
        'ProLaw Enrolled (LUSA-DECLINED)' => 378143,
        'ProLaw Enrolled (LUSA-Withdrawn)' => 378144,
        'ProLaw Enrolled (LUSA-WITHDRAWN)' => 378144,
    ];

    protected function updateCrmStatus(string $contactId, string $status): bool
    {
        if ($contactId === '' || !is_numeric($contactId)) {
            return false;
        }

        // Use Forth API /workflow endpoint with statusID
        $apiKey = $this->resolveForthApiKey();
        if ($apiKey === '') {
            $this->warn("  CRM update skipped for CID {$contactId}: no Forth API key");
            return false;
        }

        // Get statusID from mapping
        $statusId = self::LUSA_STATUS_IDS[$status] ?? null;
        if ($statusId === null) {
            $this->warn("  CRM update skipped for CID {$contactId}: no statusID mapping for '{$status}'");
            return false;
        }

        try {
            $response = $this->httpClient()->put("https://api.forthcrm.com/v1/contacts/{$contactId}/workflow", [
                'headers' => [
                    'Api-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'statusID' => $statusId,
                ],
            ]);

            $httpCode = $response->getStatusCode();
            if ($httpCode >= 200 && $httpCode < 300) {
                return true;
            }

            $this->warn("  CRM update failed for CID {$contactId}: HTTP {$httpCode}");
            return false;
        } catch (\Throwable $e) {
            $this->warn("  CRM update failed for CID {$contactId}: {$e->getMessage()}");
            return false;
        }
    }

    protected function pauseCrmContact(string $contactId): void
    {
        $apiKey = $this->resolveForthApiKey();
        if ($apiKey === '') {
            $this->warn("  Cannot pause CID {$contactId}: no Forth API key");
            return;
        }

        try {
            $this->httpClient()->put("https://api.forthcrm.com/v1/contacts/{$contactId}", [
                'headers' => [
                    'Api-Key' => $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'pause' => true,
                ],
            ]);
            $this->info("    [PAUSE] CRM contact {$contactId} paused.");
        } catch (\Throwable $e) {
            $this->warn("    [PAUSE] Failed to pause CID {$contactId}: {$e->getMessage()}");
            $this->totalErrors++;
        }
    }

    // ─── DB Helpers ───────────────────────────────────────────────────

    /**
     * Batch load all client data in bulk queries for performance optimization.
     * This eliminates N+1 query problems by fetching all data upfront.
     */
    protected function batchLoadClientData(array $clientIds): void
    {
        if (empty($clientIds)) {
            return;
        }

        $this->info('  Loading client data in batch...');
        $startTime = microtime(true);

        // Batch load enrollment states from SQL Server
        $llgIds = array_map(fn($id) => "'LLG-{$this->esc($id)}'", $clientIds);
        $llgIdList = implode(',', $llgIds);
        
        $sql = "SELECT LLG_ID, State FROM TblEnrollment WHERE LLG_ID IN ({$llgIdList})";
        $result = $this->connector->querySqlServer($sql);
        foreach ($result['data'] ?? [] as $row) {
            $cid = str_replace('LLG-', '', $row['LLG_ID'] ?? '');
            $this->enrollmentStatesCache[$cid] = (string) ($row['State'] ?? '');
        }

        // Batch load funded clients from SQL Server
        $plainIds = array_map(fn($id) => "'{$this->esc($id)}'", $clientIds);
        $plainIdList = implode(',', $plainIds);
        
        $sql = "SELECT LLG_ID FROM TblLendingUSAFunded WHERE LLG_ID IN ({$plainIdList})";
        $result = $this->connector->querySqlServer($sql);
        foreach ($result['data'] ?? [] as $row) {
            $this->fundedClientsCache[$row['LLG_ID']] = true;
        }

        // Batch load enrollment plans from Snowflake (single query)
        $idList = implode(',', $clientIds);
        $sql = "SELECT p.CONTACT_ID, e.TITLE 
                FROM ENROLLMENT_PLAN AS p 
                LEFT JOIN ENROLLMENT_DEFAULTS2 AS e ON p.PLAN_ID = e.ID 
                WHERE p.CONTACT_ID IN ({$idList})";
        try {
            $result = $this->connector->query($sql);
            foreach ($result['data'] ?? [] as $row) {
                $this->enrollmentPlansCache[$row['CONTACT_ID']] = (string) ($row['TITLE'] ?? '');
            }
        } catch (\Throwable $e) {
            // Silently continue - plans will be empty
        }

        // Batch load client names from Snowflake (single query)
        $sql = "SELECT ID, FIRSTNAME, LASTNAME FROM CONTACTS WHERE ID IN ({$idList})";
        try {
            $result = $this->connector->query($sql);
            foreach ($result['data'] ?? [] as $row) {
                $firstName = trim((string) ($row['FIRSTNAME'] ?? ''));
                $lastName = trim((string) ($row['LASTNAME'] ?? ''));
                $this->clientNamesCache[$row['ID']] = trim("{$firstName} {$lastName}");
            }
        } catch (\Throwable $e) {
            // Silently continue - names will be empty
        }

        $elapsed = round((microtime(true) - $startTime) * 1000);
        $this->info("  Batch loaded " . count($clientIds) . " clients in {$elapsed}ms");
    }

    protected function getEnrollmentState(string $llgid): string
    {
        // Use cache if available
        if (isset($this->enrollmentStatesCache[$llgid])) {
            return $this->enrollmentStatesCache[$llgid];
        }
        
        // Fallback to individual query
        $sql = "SELECT State FROM TblEnrollment WHERE LLG_ID = 'LLG-{$this->esc($llgid)}'";
        $result = $this->connector->querySqlServer($sql);
        return (string) ($result['data'][0]['State'] ?? '');
    }

    protected function getEnrollmentPlanTitle(string $llgid): string
    {
        // Use cache if available
        if (isset($this->enrollmentPlansCache[$llgid])) {
            return $this->enrollmentPlansCache[$llgid];
        }
        
        // Fallback to individual query
        $sql = "SELECT e.TITLE FROM ENROLLMENT_PLAN AS p LEFT JOIN ENROLLMENT_DEFAULTS2 AS e ON p.PLAN_ID = e.ID WHERE p.CONTACT_ID = '{$this->esc($llgid)}'";
        try {
            $result = $this->connector->query($sql);
            return (string) ($result['data'][0]['TITLE'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    protected function getClientName(string $llgid): string
    {
        // Use cache if available
        if (isset($this->clientNamesCache[$llgid])) {
            return $this->clientNamesCache[$llgid];
        }
        
        // Fallback to individual query
        $sql = "SELECT FIRSTNAME, LASTNAME FROM CONTACTS WHERE ID = '{$this->esc($llgid)}'";
        try {
            $result = $this->connector->query($sql);
            $firstName = trim((string) ($result['data'][0]['FIRSTNAME'] ?? ''));
            $lastName = trim((string) ($result['data'][0]['LASTNAME'] ?? ''));
            return trim("{$firstName} {$lastName}");
        } catch (\Throwable $e) {
            return '';
        }
    }
    
    protected function isAlreadyFunded(string $llgid): bool
    {
        // Use cache if available
        if (isset($this->fundedClientsCache[$llgid])) {
            return true;
        }
        
        // Fallback to individual query
        $sql = "SELECT COUNT(*) AS CNT FROM TblLendingUSAFunded WHERE LLG_ID = '{$this->esc($llgid)}'";
        $result = $this->connector->querySqlServer($sql);
        return ($result['data'][0]['CNT'] ?? 0) > 0;
    }

    protected function categorizePlan(string $enrollmentPlan): string
    {
        // Matches VBA logic exactly:
        // If UCase(Left(EnrollmentPlan, 3)) = "LDR" → "LDR"
        // ElseIf UCase(Left(EnrollmentPlan, 4)) = "LT L" → "LDR"
        // ElseIf UCase(Left(EnrollmentPlan, 4)) = "PLAW" → "PLAW"
        // ElseIf InStr(1, EnrollmentPlan, "Progress", vbTextCompare) > 0 → "ProLaw"
        // Else → ""
        $upper = strtoupper($enrollmentPlan);

        if (str_starts_with($upper, 'LDR')) {
            return 'LDR';
        }
        if (str_starts_with($upper, 'LT L')) {
            return 'LDR';
        }
        if (str_starts_with($upper, 'PLAW')) {
            return 'PLAW';
        }
        if (stripos($enrollmentPlan, 'Progress') !== false) {
            return 'ProLaw';
        }

        return '';
    }

    protected function findLeastAssignedNegotiator(): string
    {
        $sql = "SELECT TOP 1 e.Employee_Name "
            . "FROM TblEmployees AS e "
            . "LEFT JOIN ("
            . "SELECT Negotiator, COUNT(*) AS Assignments "
            . "FROM TblNegotiatorFundingAssignments "
            . "GROUP BY Negotiator"
            . ") AS f ON e.Employee_Name = f.Negotiator "
            . "WHERE e.Access_Level IN ('Settlement Manager') "
            . "AND e.Term_Date IS NULL "
            . "AND e.Employee_Name <> 'CSR Agent' "
            . "AND e.Employee_Name <> 'Dina Hakeem' "
            . "ORDER BY f.Assignments ASC, NEWID()";

        $result = $this->connector->querySqlServer($sql);
        return (string) ($result['data'][0]['Employee_Name'] ?? '');
    }

    // ─── GetLLGStatus Mapping ─────────────────────────────────────────

    protected function getLLGStatus(string $lendingStatus, string $plan): string
    {
        // Exact mapping from VBA GetLLGStatus function:
        // GetLLGStatus = Category & " Enrolled (LUSA-xxx)"
        // where Category is LDR, PLAW, or ProLaw
        if ($plan === '') {
            return '';
        }

        switch ($lendingStatus) {
            case 'App Initiated':
            case 'Prospect':
                return "{$plan} Enrolled (LUSA-App)";

            case 'Pre-Qualified':
            case 'Need More Info':
            case 'Info Required':  // API variant of "Need More Info"
            case 'Pre-Approved':
            case 'Underwriting Review':
            case 'Contract Signed':
            case 'Contract Sent':  // API variant
            case 'Offer Generated':
            case 'Offer Selected':
            case 'Pending':
                return "{$plan} Enrolled (LUSA-PQ)";

            case 'Approved':
            case 'Qualified':
            case 'Sent for Funding':
                return "{$plan} Enrolled (LUSA-Approved)";

            case 'Funded':
                return "{$plan} Enrolled (LUSA-Funded)";

            case 'Declined':
            case 'Disqualified':
                return "{$plan} Enrolled (LUSA-Declined)";

            case 'Withdrawn':
                return "{$plan} Enrolled (LUSA-Withdrawn)";

            default:
                // No CRM mapping for: "Not Interested", "Hold", "Expired Hold",
                // "Funding Review", "Duplicate", "Expired", etc.
                return '';
        }
    }

    // ─── Utility Helpers ──────────────────────────────────────────────

    protected function numericOnly(string $value): string
    {
        // Strip known prefixes: LLG-, LDR-, PLAW-, LT-
        $cleaned = preg_replace('/^(LLG|LDR|PLAW|LT)-/i', '', trim($value));
        return preg_replace('/[^0-9]/', '', $cleaned) ?? '';
    }

    protected function esc(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    protected function httpClient(): Client
    {
        if ($this->httpClient === null) {
            $this->httpClient = new Client(['timeout' => 30, 'verify' => false]);
        }
        return $this->httpClient;
    }

    protected function resolveCrmLoginKey(string $connection, string $override): string
    {
        if ($override !== '') {
            return $override;
        }

        if (function_exists('tenant')) {
            try {
                $tenantKey = tenant('crm_login_api_key');
                if (is_string($tenantKey) && $tenantKey !== '') {
                    return $tenantKey;
                }
            } catch (\Throwable $e) {
            }
        }

        $connKey = env('CRM_LOGIN_API_KEY_' . strtoupper($connection));
        if (is_string($connKey) && $connKey !== '') {
            return $connKey;
        }

        $defaultKey = env('CRM_LOGIN_API_KEY');
        return is_string($defaultKey) ? $defaultKey : '';
    }

    protected function resolveForthApiKey(): string
    {
        // Return cached key if already resolved for this connection
        if ($this->forthApiKeyCache !== null) {
            return $this->forthApiKeyCache;
        }

        // Priority 1: TblAPIKeys database table (authoritative source)
        $categoryMap = ['plaw' => 'PLAW', 'ldr' => 'LDR', 'lt' => 'LT'];
        $cat = $categoryMap[$this->currentConnection] ?? strtoupper($this->currentConnection);

        $sql = "SELECT API_Key FROM TblAPIKeys WHERE Category = '{$this->esc($cat)}'";
        $result = $this->connector->querySqlServer($sql);
        $dbKey = (string) ($result['data'][0]['API_Key'] ?? '');
        
        if ($dbKey !== '') {
            $this->forthApiKeyCache = $dbKey;
            return $dbKey;
        }
        
        // Priority 2: Environment variables (fallback)
        $envKeyMap = [
            'ldr' => 'FORTH_LDR_CLIENT_SECRET',
            'plaw' => 'FORTH_PLAW_CLIENT_SECRET',
            'lt' => 'FORTH_LT_CLIENT_SECRET',
        ];
        
        $envKeyName = $envKeyMap[$this->currentConnection] ?? '';
        if ($envKeyName !== '' && function_exists('env')) {
            $envKey = (string) env($envKeyName, '');
            $this->forthApiKeyCache = $envKey;
            return $envKey;
        }
        
        $this->forthApiKeyCache = '';
        return '';
    }

    protected function resetCounters(): void
    {
        $this->totalFetched = 0;
        $this->totalFiltered = 0;
        $this->totalProcessed = 0;
        $this->totalSkipped = 0;
        $this->totalFunded = 0;
        $this->totalCrmUpdated = 0;
        $this->totalErrors = 0;
        $this->statusBreakdown = [];
        $this->changedRecords = [];
        $this->fundedClients = [];
        $this->declinedClients = [];
        $this->newClients = [];
        // Clear batch caches
        $this->enrollmentStatesCache = [];
        $this->enrollmentPlansCache = [];
        $this->clientNamesCache = [];
        $this->fundedClientsCache = [];
        $this->forthApiKeyCache = null;
    }

    // ─── Manager Report ──────────────────────────────────────────────────

    protected function printManagerReport(string $source, int $afterDedupe): void
    {
        $runTime = now()->format('Y-m-d H:i:s');
        $mode = $this->dryRun ? 'DRY RUN' : 'LIVE';

        $this->newLine();
        $this->line('--------------------------------------------------------------------------------');
        $this->line('  LENDINGUSA STATUS SYNC REPORT');
        $this->line('--------------------------------------------------------------------------------');
        $this->line("  Account:    {$source}");
        $this->line("  Run Time:   {$runTime}");
        $this->line("  Mode:       {$mode}");
        $this->line('--------------------------------------------------------------------------------');

        // Overview Stats
        $this->newLine();
        $this->info('SUMMARY');
        $this->table(
            ['Metric', 'Count', 'Description'],
            [
                ['API Records Fetched', $this->totalFetched, 'Total applications from LendingUSA'],
                ['Filtered Out', $this->totalFiltered, 'Duplicate/Expired statuses removed'],
                ['Unique Clients', $afterDedupe, 'After deduplication'],
                ['Status Changes', $this->totalProcessed, 'New or changed statuses'],
                ['No Change', $this->totalSkipped, 'Already up to date or blocked state'],
                ['Errors', $this->totalErrors, $this->totalErrors > 0 ? 'Review needed' : 'None'],
            ]
        );

        // Status Breakdown
        if (!empty($this->statusBreakdown)) {
            $this->newLine();
            $this->info('STATUS BREAKDOWN');
            arsort($this->statusBreakdown);
            $statusRows = [];
            foreach ($this->statusBreakdown as $status => $count) {
                $pct = $this->totalFetched > 0 ? round(($count / $this->totalFetched) * 100, 1) : 0;
                $statusRows[] = [$status, $count, "{$pct}%"];
            }
            $this->table(['Status', 'Count', 'Percent'], $statusRows);
        }

        // Funded Clients Detail
        if (!empty($this->fundedClients)) {
            $this->newLine();
            $this->info('FUNDED CLIENTS (' . count($this->fundedClients) . ')');
            $fundedRows = [];
            foreach (array_slice($this->fundedClients, 0, 20) as $client) {
                $fundedRows[] = [
                    $client['cid'],
                    $client['prev_status'] ?? 'N/A',
                    $client['state'] ?? '-',
                    $client['negotiator'] ?? 'Pending',
                ];
            }
            $this->table(['Client ID', 'Previous Status', 'State', 'Negotiator'], $fundedRows);
            if (count($this->fundedClients) > 20) {
                $this->line('  + ' . (count($this->fundedClients) - 20) . ' more');
            }
        }

        // Declined Clients
        if (!empty($this->declinedClients)) {
            $this->newLine();
            $this->info('DECLINED/WITHDRAWN (' . count($this->declinedClients) . ')');
            $declinedRows = [];
            foreach (array_slice($this->declinedClients, 0, 15) as $client) {
                $declinedRows[] = [
                    $client['cid'],
                    $client['prev_status'] ?? 'N/A',
                    $client['new_status'],
                    $client['state'] ?? '-',
                ];
            }
            $this->table(['Client ID', 'Previous Status', 'New Status', 'State'], $declinedRows);
            if (count($this->declinedClients) > 15) {
                $this->line('  + ' . (count($this->declinedClients) - 15) . ' more');
            }
        }

        // New Clients
        if (!empty($this->newClients)) {
            $this->newLine();
            $this->info('NEW APPLICATIONS (' . count($this->newClients) . ')');
            $newRows = [];
            foreach (array_slice($this->newClients, 0, 15) as $client) {
                $newRows[] = [
                    $client['cid'],
                    $client['status'],
                    $client['state'] ?? '-',
                    $client['plan'] ?? '-',
                ];
            }
            $this->table(['Client ID', 'Status', 'State', 'Plan'], $newRows);
            if (count($this->newClients) > 15) {
                $this->line('  + ' . (count($this->newClients) - 15) . ' more');
            }
        }

        // Recent Status Changes (sample)
        if (!empty($this->changedRecords)) {
            $this->newLine();
            $this->info('STATUS CHANGES (first 20)');
            $changeRows = [];
            foreach (array_slice($this->changedRecords, 0, 20) as $rec) {
                $changeRows[] = [
                    $rec['cid'],
                    $rec['old_status'],
                    '->',
                    $rec['new_status'],
                    $rec['crm_status'] ?? '-',
                ];
            }
            $this->table(['Client ID', 'Old Status', '', 'New Status', 'CRM Update'], $changeRows);
            if (count($this->changedRecords) > 20) {
                $this->line('  + ' . (count($this->changedRecords) - 20) . ' more changes');
            }
        }

        // Action Summary
        $this->newLine();
        $this->info('ACTIONS ' . ($this->dryRun ? '(would be taken)' : 'TAKEN'));
        $this->table(
            ['Action', 'Count'],
            [
                ['Funded workflow triggered', $this->totalFunded],
                ['CRM status updates', $this->totalCrmUpdated],
                ['Status history records', $this->totalProcessed],
            ]
        );

        // Footer
        $this->newLine();
        if ($this->dryRun) {
            $this->warn('DRY RUN MODE - No database or CRM changes were made.');
            $this->line('  Run without --dry-run to apply changes.');
        } else {
            $this->info('Sync completed successfully.');
        }
        $this->line('--------------------------------------------------------------------------------');
    }

    protected function padRight(string $str, int $len): string
    {
        return str_pad($str, $len);
    }

    // ─── Email Report ────────────────────────────────────────────────────

    protected function sendEmailReport(string $connection): void
    {
        $this->info('[6/6] Sending email report...');

        $reportData = new ReportData();
        $reportData
            ->setConnection(strtoupper($connection))
            ->setDryRun($this->dryRun)
            ->setStats(
                $this->totalProcessed,
                count($this->changedRecords),
                $this->totalCrmUpdated,
                $this->totalSkipped,
                $this->totalErrors
            )
            ->setFundedClients($this->fundedClients)
            ->setDeclinedClients($this->declinedClients)
            ->setNewClients($this->newClients)
            ->setChangedRecords($this->changedRecords)
            ->setStatusBreakdown($this->statusBreakdown);

        $formatter = new ReportFormatter();
        $sent = $formatter->sendReport($this->connector, $reportData, $this);

        if ($sent) {
            Log::info('UpdateLendingUSAStatuses: email report sent.', ['connection' => $connection]);
        } else {
            Log::warning('UpdateLendingUSAStatuses: email report failed or no recipients.', ['connection' => $connection]);
        }
    }
}
