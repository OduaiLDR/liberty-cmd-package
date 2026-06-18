<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Support\PmodBusinessDateResolver;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

final class ForthPayPmodExecutionGateway implements PmodExecutionGateway
{
    private const FORTH_CRM_BASE_URL = 'https://api.forthcrm.com/v1';
    private const FORTH_PAY_BASE_URL = 'https://api.forthpay.com/v1';

    private ?DBConnector $dbConnector = null;
    private array $apiKeyCache = [];

    private function getDbConnector(): DBConnector
    {
        if ($this->dbConnector === null) {
            $this->dbConnector = DBConnector::fromEnvironment('plaw');
            $this->dbConnector->initializeSqlServer();
        }
        return $this->dbConnector;
    }

    private function getApiKey(string $tenantId): string
    {
        $category = strtoupper($tenantId);

        if (isset($this->apiKeyCache[$category])) {
            return $this->apiKeyCache[$category];
        }

        $cacheKey = "pmod_api_key_{$category}";
        $apiKey = Cache::get($cacheKey);

        if ($apiKey) {
            $this->apiKeyCache[$category] = $apiKey;
            return $apiKey;
        }

        try {
            $connector = $this->getDbConnector();
            $sql = "SELECT API_Key FROM TblAPIKeys WHERE Category = ?";
            $result = $connector->querySqlServer($sql, [$category]);

            if ($result['success'] && !empty($result['data'])) {
                $apiKey = $result['data'][0]['API_Key'] ?? null;

                if ($apiKey) {
                    Cache::put($cacheKey, $apiKey, now()->addHours(1));
                    $this->apiKeyCache[$category] = $apiKey;

                    Log::info('PMOD: Fetched API key from TblAPIKeys', [
                        'tenant' => $tenantId,
                        'category' => $category,
                    ]);

                    return $apiKey;
                }
            }

            Log::warning('PMOD: API key not found in TblAPIKeys, falling back to env', [
                'tenant' => $tenantId,
                'category' => $category,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PMOD: Failed to fetch API key from database, falling back to env', [
                'tenant' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }

        $apiKey = match ($tenantId) {
            'ldr' => config('services.crm.ldr_api_key'),
            'plaw' => config('services.crm.plaw_api_key'),
            'lt' => config('services.crm.lt_api_key'),
            default => throw new \InvalidArgumentException("Unknown tenant: {$tenantId}"),
        };

        if (empty($apiKey)) {
            throw new \RuntimeException("CRM API key not configured for tenant: {$tenantId}");
        }

        return $apiKey;
    }

    private function crmClient(string $tenantId): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()
            ->timeout(120)
            ->baseUrl(self::FORTH_CRM_BASE_URL)
            ->withHeaders([
                'Api-Key' => $this->getApiKey($tenantId),
            ]);
    }

    private function payClient(string $tenantId): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()
            ->timeout(120)
            ->baseUrl(self::FORTH_PAY_BASE_URL)
            ->withHeaders([
                'Api-Key' => $this->getApiKey($tenantId),
            ]);
    }

    public function getContactTransactions(PmodWorkItem $workItem): array
    {
        Log::info('PMOD: Fetching contact transactions via ForthPay reports', [
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'idempotency_key' => $workItem->idempotencyKey,
        ]);

        // ForthPay /reports/transactions returns ALL records; the Forth CRM
        // /contacts/{id}/transactions endpoint is capped at 100 with no
        // pagination (confirmed with Forth team), so we use the reports endpoint.
        $response = $this->payClient($workItem->tenantId)
            ->post('/reports/transactions', [
                'client_id' => ['values' => [[$workItem->contactId]]],
                '_offset' => null,
                '_limit' => 5000,
            ]);

        if (!$response->successful()) {
            Log::error('PMOD: Failed to fetch contact transactions', [
                'contact_id' => $workItem->contactId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return [];
        }

        $items = $response->json('response') ?? [];

        // Map ForthPay reports shape to the structure all action handlers
        // and PmodTransactionMatcher expect. Critical conversions:
        //  - transaction_type "Draft" -> type "D" (filters check uppercase 'D')
        //  - active bool -> "1"/"0" string (creditor actions strict-compare === '1')
        //  - transaction_status "Cancel" -> cancelled true (filters use empty())
        //  - id -> draft_id (for D-type, used by extractAuthoritativeDraftId)
        $typeMap = [
            'Draft'            => 'D',
            'DPG Fee'          => 'DPG',
            'ACH Credit Fee'   => 'C',
            'Disbursement Fee' => 'DF',
            'Settlement'       => 'SF',
            'Performance Fee'  => 'PF',
        ];

        $transactions = [];
        foreach ($items as $tx) {
            if (!is_array($tx)) {
                continue;
            }

            $shortType = $typeMap[$tx['transaction_type'] ?? ''] ?? '?';
            $isActive = (bool) ($tx['active'] ?? false);
            $status = $tx['transaction_status'] ?? null;

            $mapped = [
                'transaction_id'   => isset($tx['id']) ? (string) $tx['id'] : null,
                'type'             => $shortType,
                'transaction_type' => $tx['transaction_type'] ?? null,
                'process_date'     => $tx['process_date'] ?? null,
                'amount'           => $tx['amount'] ?? null,
                'memo'             => $tx['memo'] ?? null,
                'active'           => $isActive ? '1' : '0',
                'cancelled'        => $status === 'Cancel',
                'completed'        => (bool) ($tx['completed'] ?? false),
                'status_label'     => $status,
                'client_id'        => $tx['client_id'] ?? null,
                'linked_to'        => $tx['linked_to'] ?? null,
                'sub_type'         => $tx['sub_type'] ?? null,
                'cleared_date'     => $tx['cleared_date'] ?? null,
                'returned_date'    => $tx['returned_date'] ?? null,
            ];

            if ($shortType === 'D' && $mapped['transaction_id'] !== null) {
                $mapped['draft_id'] = $mapped['transaction_id'];
            }

            $transactions[] = $mapped;
        }

        Log::info('PMOD: Contact transactions fetched', [
            'contact_id' => $workItem->contactId,
            'transaction_count' => count($transactions),
        ]);

        return $transactions;
    }

    public function createContactNote(PmodWorkItem $workItem, string $content, bool $public = true): array
    {
        Log::info('PMOD: Creating contact note', [
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'public' => $public,
            'dry_run' => $workItem->dryRun,
            'idempotency_key' => $workItem->idempotencyKey,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would create contact note', [
                'contact_id' => $workItem->contactId,
                'content_length' => strlen($content),
            ]);
            return [
                'note_id' => 'dry_run_note_' . uniqid(),
                'contact_id' => $workItem->contactId,
                'content' => $content,
                'dry_run' => true,
            ];
        }

        $response = $this->crmClient($workItem->tenantId)
            ->post("/contacts/{$workItem->contactId}/notes", [
                'content' => $content,
                'public' => $public,
                'note_type' => 2,
            ]);

        if (!$response->successful()) {
            Log::error('PMOD: Failed to create contact note', [
                'contact_id' => $workItem->contactId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to create contact note');
        }

        $noteData = $response->json('response', []);

        Log::info('PMOD: Contact note created', [
            'contact_id' => $workItem->contactId,
            'note_id' => $noteData['note_id'] ?? null,
        ]);

        return $noteData;
    }

    public function createDraft(PmodWorkItem $workItem, array $payload): array
    {
        $payload = $this->withBusinessProcessDate($payload);

        Log::info('PMOD: Creating draft', [
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'payload' => $payload,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run' => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would create draft', ['payload' => $payload]);
            return ['draft_id' => 'dry_run_' . uniqid(), 'status' => 'dry_run'];
        }

        $response = null;
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $response = $this->payClient($workItem->tenantId)
                ->post('/drafts', $payload);

            if ($response->successful() || ! $this->shouldRetryWithNextDate($response->body(), $payload)) {
                break;
            }

            $payload['process_date'] = PmodBusinessDateResolver::nextBusinessDay(
                PmodBusinessDateResolver::nextDay((string) $payload['process_date'])
            );
        }

        if ($response === null || !$response->successful()) {
            Log::error('PMOD: Failed to create draft', [
                'contact_id' => $workItem->contactId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to create draft');
        }

        $draftData = $response->json();

        Log::info('PMOD: Draft created', [
            'contact_id' => $workItem->contactId,
            'draft_id' => $draftData['draft_id'] ?? null,
        ]);

        return $draftData;
    }

    public function updateDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        $payload = $this->withBusinessProcessDate($payload);

        Log::info('PMOD: Updating draft', [
            'draft_id' => $draftId,
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'payload' => $payload,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run' => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would update draft', ['draft_id' => $draftId, 'payload' => $payload]);
            return ['draft_id' => $draftId, 'status' => 'dry_run'];
        }

        $response = null;
        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $response = $this->payClient($workItem->tenantId)
                ->put("/drafts/{$draftId}", $payload);

            if ($response->successful() || ! $this->shouldRetryWithNextDate($response->body(), $payload)) {
                break;
            }

            $payload['process_date'] = PmodBusinessDateResolver::nextBusinessDay(
                PmodBusinessDateResolver::nextDay((string) $payload['process_date'])
            );
        }

        if ($response === null || !$response->successful()) {
            Log::error('PMOD: Failed to update draft', [
                'draft_id' => $draftId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to update draft');
        }

        $draftData = $response->json();

        Log::info('PMOD: Draft updated', ['draft_id' => $draftId]);

        return $draftData;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withBusinessProcessDate(array $payload): array
    {
        if (! empty($payload['process_date'])) {
            $payload['process_date'] = PmodBusinessDateResolver::nextBusinessDay((string) $payload['process_date']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function shouldRetryWithNextDate(string $responseBody, array $payload): bool
    {
        return ! empty($payload['process_date'])
            && PmodBusinessDateResolver::looksLikeDateRejection($responseBody);
    }

    public function cancelDraft(PmodWorkItem $workItem, string $draftId): array
    {
        Log::info('PMOD: Cancelling draft', [
            'draft_id' => $draftId,
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run' => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would cancel draft', ['draft_id' => $draftId]);
            return ['draft_id' => $draftId, 'status' => 'dry_run_cancelled'];
        }

        $response = $this->payClient($workItem->tenantId)
            ->post("/drafts/{$draftId}/cancel");

        if (!$response->successful()) {
            Log::error('PMOD: Failed to cancel draft', [
                'draft_id' => $draftId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to cancel draft');
        }

        $draftData = $response->json();

        Log::info('PMOD: Draft cancelled', ['draft_id' => $draftId]);

        return $draftData;
    }

    public function voidSettlementOffer(PmodWorkItem $workItem, string $settlementId): array
    {
        Log::info('PMOD: Voiding settlement offer', [
            'settlement_id' => $settlementId,
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run' => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would void settlement', ['settlement_id' => $settlementId]);
            return ['settlement_id' => $settlementId, 'status' => 'dry_run_voided'];
        }

        $response = $this->crmClient($workItem->tenantId)
            ->post("/settlements/{$settlementId}/void");

        if (!$response->successful()) {
            Log::error('PMOD: Failed to void settlement offer', [
                'settlement_id' => $settlementId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to void settlement offer');
        }

        $settlementData = $response->json('response', []);

        Log::info('PMOD: Settlement offer voided', ['settlement_id' => $settlementId]);

        return $settlementData;
    }

    public function resumePayments(PmodWorkItem $workItem): array
    {
        Log::info('PMOD: Resuming payments', [
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run' => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would resume payments', ['contact_id' => $workItem->contactId]);
            return ['contact_id' => $workItem->contactId, 'status' => 'dry_run_resumed'];
        }

        $response = $this->crmClient($workItem->tenantId)
            ->post("/contacts/{$workItem->contactId}/resume-payments");

        if (!$response->successful()) {
            Log::error('PMOD: Failed to resume payments', [
                'contact_id' => $workItem->contactId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to resume payments');
        }

        $paymentData = $response->json('response', []);

        Log::info('PMOD: Payments resumed', ['contact_id' => $workItem->contactId]);

        return $paymentData;
    }

    /**
     * Resume payments for a single contact without constructing a full
     * PmodWorkItem. Used by Generate:resume-payments Phase 4, which processes a
     * Snowflake-derived list of contacts rather than PMOD work items.
     *
     * Throws on any non-2xx so the caller can fall back to the VBA's
     * "(Unable to Resume)" reporting path.
     *
     * @return array<string, mixed>
     */
    public function resumePaymentsForContact(string $tenantId, string $contactId, bool $dryRun = false): array
    {
        Log::info('PMOD: Resuming payments (contact)', [
            'contact_id' => $contactId,
            'tenant_id' => $tenantId,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            return ['contact_id' => $contactId, 'status' => 'dry_run_resumed'];
        }

        $response = $this->crmClient($tenantId)
            ->post("/contacts/{$contactId}/resume-payments");

        if (!$response->successful()) {
            Log::warning('PMOD: resume-payments not successful', [
                'contact_id' => $contactId,
                'tenant_id' => $tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to resume payments: HTTP ' . $response->status());
        }

        // Log the success status + a body snippet so the first live test gives a
        // clear yes/no on whether this endpoint actually reschedules the draft.
        Log::info('PMOD: resume-payments succeeded', [
            'contact_id' => $contactId,
            'tenant_id' => $tenantId,
            'status' => $response->status(),
            'response' => mb_substr($response->body(), 0, 300),
        ]);

        return $response->json('response', []);
    }

    /**
     * Update a contact via Forth CRM. Accepts integer `stage` and `status` IDs
     * (per Forth release notes 2023-07), plus any other contact fields the
     * Update Contact endpoint supports.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateContact(PmodWorkItem $workItem, array $payload): array
    {
        Log::info('PMOD: Updating contact', [
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'payload' => $payload,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run' => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would update contact', [
                'contact_id' => $workItem->contactId,
                'payload' => $payload,
            ]);
            return [
                'contact_id' => $workItem->contactId,
                'status' => 'dry_run_updated',
                'payload' => $payload,
            ];
        }

        $response = $this->crmClient($workItem->tenantId)
            ->put("/contacts/{$workItem->contactId}", $payload);

        if (!$response->successful()) {
            Log::error('PMOD: Failed to update contact', [
                'contact_id' => $workItem->contactId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to update contact');
        }

        $data = $response->json('response', []);

        Log::info('PMOD: Contact updated', ['contact_id' => $workItem->contactId]);

        return $data;
    }

    /**
     * List all contact stages and their statuses for a tenant. Returns the raw
     * Forth response so callers can extract the integer IDs needed by
     * updateContact()'s `stage` / `status` fields.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Contact "stages" are Forth's top-level categories (Underwriting, Client,
     * NSF, Cancel, Graduated, Lead, Admin). Each has an integer `id` that a
     * status links back to via its `cat_id`.
     *
     * @return list<array<string, mixed>>
     */
    public function listContactStages(string $tenantId): array
    {
        Log::info('PMOD: Listing contact stages', ['tenant_id' => $tenantId]);

        $response = $this->crmClient($tenantId)
            ->get('/contact-stages');

        if (!$response->successful()) {
            Log::error('PMOD: Failed to list contact stages', [
                'tenant_id' => $tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to list contact stages');
        }

        return $response->json('response.results', []);
    }

    /**
     * Contact "statuses" are the account-specific lead statuses (e.g.
     * "LDR Enrolled (NSF-1)", "System Cancel (NSF-3)"). Each carries an integer
     * `id` (what Update Contact wants) and a `cat_id` pointing at its stage.
     *
     * @return list<array<string, mixed>>
     */
    public function listContactStatuses(string $tenantId): array
    {
        Log::info('PMOD: Listing contact statuses', ['tenant_id' => $tenantId]);

        $response = $this->crmClient($tenantId)
            ->get('/contact-statuses');

        if (!$response->successful()) {
            Log::error('PMOD: Failed to list contact statuses', [
                'tenant_id' => $tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to list contact statuses');
        }

        return $response->json('response.results', []);
    }

    public function createRefund(PmodWorkItem $workItem, array $payload): array
    {
        Log::info('PMOD: Creating refund', [
            'contact_id' => $workItem->contactId,
            'tenant_id' => $workItem->tenantId,
            'payload' => $payload,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run' => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would create refund', ['payload' => $payload]);
            return ['refund_id' => 'dry_run_' . uniqid(), 'status' => 'dry_run'];
        }

        $response = $this->payClient($workItem->tenantId)
            ->post('/refunds', $payload);

        if (!$response->successful()) {
            Log::error('PMOD: Failed to create refund', [
                'contact_id' => $workItem->contactId,
                'tenant_id' => $workItem->tenantId,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to create refund');
        }

        $refundData = $response->json();

        Log::info('PMOD: Refund created', [
            'contact_id' => $workItem->contactId,
            'refund_id' => $refundData['id'] ?? $refundData['refund_id'] ?? null,
        ]);

        return $refundData;
    }

    public function refundDraft(PmodWorkItem $workItem, string $draftId, array $payload): array
    {
        return $this->createRefund($workItem, [
            ...$payload,
            'client_id' => $payload['client_id'] ?? $workItem->contactId,
            'draft_id' => $draftId,
        ]);
    }

    public function getContactDebts(PmodWorkItem $workItem): array
    {
        Log::info('PMOD: Fetching contact debts', [
            'contact_id' => $workItem->contactId,
            'tenant_id'  => $workItem->tenantId,
        ]);

        $response = $this->crmClient($workItem->tenantId)
            ->get("/contacts/{$workItem->contactId}/debts");

        if (!$response->successful()) {
            Log::error('PMOD: Failed to fetch contact debts', [
                'contact_id' => $workItem->contactId,
                'status'     => $response->status(),
                'response'   => $response->body(),
            ]);
            return [];
        }

        return $response->json('response.results', []);
    }

    public function getContactSummary(PmodWorkItem $workItem): array
    {
        Log::info('PMOD: Fetching contact summary', [
            'contact_id' => $workItem->contactId,
            'tenant_id'  => $workItem->tenantId,
        ]);

        $response = $this->crmClient($workItem->tenantId)
            ->get("/contacts/{$workItem->contactId}/summary");

        if (!$response->successful()) {
            Log::error('PMOD: Failed to fetch contact summary', [
                'contact_id' => $workItem->contactId,
                'status'     => $response->status(),
                'response'   => $response->body(),
            ]);
            return [];
        }

        return $response->json('response', []);
    }

    public function addBankAccount(PmodWorkItem $workItem, array $payload): array
    {
        Log::info('PMOD: Adding bank account', [
            'contact_id'      => $workItem->contactId,
            'tenant_id'       => $workItem->tenantId,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run'         => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would add bank account', ['contact_id' => $workItem->contactId]);
            return ['bank_account_id' => 'dry_run_' . uniqid(), 'status' => 'dry_run'];
        }

        // Try PUT (update) first, then POST (create) if that fails
        $response = $this->crmClient($workItem->tenantId)
            ->put("/contacts/{$workItem->contactId}/bank-account", $payload);

        if (!$response->successful()) {
            $response = $this->crmClient($workItem->tenantId)
                ->post("/contacts/{$workItem->contactId}/bank-account", $payload);
        }

        if (!$response->successful()) {
            $body = $response->body();
            // Treat "already existing" as success — the account is already set, goal achieved
            if (str_contains($body, 'already existing') || str_contains($body, 'Bank Account already')) {
                Log::info('PMOD: Bank account already exists — treating as success', ['contact_id' => $workItem->contactId]);
                return ['status' => 'already_exists', 'contact_id' => $workItem->contactId];
            }
            Log::error('PMOD: Failed to add bank account', [
                'contact_id' => $workItem->contactId,
                'status'     => $response->status(),
                'response'   => $body,
            ]);
            throw new \RuntimeException('Failed to add bank account');
        }

        $data = $response->json('response', []);
        Log::info('PMOD: Bank account added', ['contact_id' => $workItem->contactId]);
        return $data;
    }

    public function uploadContactDocument(PmodWorkItem $workItem, string $base64Content, string $fileName, string $description): array
    {
        Log::info('PMOD: Uploading contact document', [
            'contact_id' => $workItem->contactId,
            'tenant_id'  => $workItem->tenantId,
            'file_name'  => $fileName,
            'dry_run'    => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would upload document', ['file_name' => $fileName]);
            return ['document_id' => 'dry_run_' . uniqid(), 'status' => 'dry_run'];
        }

        $response = $this->crmClient($workItem->tenantId)
            ->post("/contacts/{$workItem->contactId}/documents", [
                'file_name'   => $fileName,
                'description' => $description,
                'content'     => $base64Content,
            ]);

        if (!$response->successful()) {
            Log::error('PMOD: Failed to upload contact document', [
                'contact_id' => $workItem->contactId,
                'status'     => $response->status(),
                'response'   => $response->body(),
            ]);
            throw new \RuntimeException('Failed to upload contact document');
        }

        $data = $response->json('response', []);
        Log::info('PMOD: Document uploaded', ['contact_id' => $workItem->contactId, 'file_name' => $fileName]);
        return $data;
    }

    public function createDebt(PmodWorkItem $workItem, array $payload): array
    {
        Log::info('PMOD: Creating debt', [
            'contact_id'      => $workItem->contactId,
            'tenant_id'       => $workItem->tenantId,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run'         => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would create debt', ['payload' => $payload]);
            return ['debt_id' => 'dry_run_' . uniqid(), 'status' => 'dry_run'];
        }

        $response = $this->crmClient($workItem->tenantId)
            ->post('/debts', array_merge($payload, ['client_id' => $workItem->contactId]));

        if (!$response->successful()) {
            Log::error('PMOD: Failed to create debt', [
                'contact_id' => $workItem->contactId,
                'status'     => $response->status(),
                'response'   => $response->body(),
            ]);
            throw new \RuntimeException('Failed to create debt');
        }

        $data = $response->json('response', []);
        Log::info('PMOD: Debt created', ['contact_id' => $workItem->contactId]);
        return $data;
    }

    public function cancelDebt(PmodWorkItem $workItem, string $debtId): array
    {
        Log::info('PMOD: Cancelling debt', [
            'debt_id'         => $debtId,
            'contact_id'      => $workItem->contactId,
            'tenant_id'       => $workItem->tenantId,
            'idempotency_key' => $workItem->idempotencyKey,
            'dry_run'         => $workItem->dryRun,
        ]);

        if ($workItem->dryRun) {
            Log::info('PMOD: DRY RUN - Would cancel debt', ['debt_id' => $debtId]);
            return ['debt_id' => $debtId, 'status' => 'dry_run_cancelled'];
        }

        $response = $this->crmClient($workItem->tenantId)
            ->post("/debts/{$debtId}/cancel");

        if (!$response->successful()) {
            Log::error('PMOD: Failed to cancel debt', [
                'debt_id'  => $debtId,
                'status'   => $response->status(),
                'response' => $response->body(),
            ]);
            throw new \RuntimeException('Failed to cancel debt');
        }

        $data = $response->json('response', []);
        Log::info('PMOD: Debt cancelled', ['debt_id' => $debtId]);
        return $data;
    }
}
