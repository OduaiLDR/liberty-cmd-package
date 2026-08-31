<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use Cmd\Reports\Pmod\Contracts\PmodCreditorDirectory;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Support\PmodBusinessDateResolver;
use Cmd\Reports\Services\DBConnector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

final class ForthPayPmodExecutionGateway implements PmodExecutionGateway, PmodCreditorDirectory
{
    private const FORTH_CRM_BASE_URL = 'https://api.forthcrm.com/v1';
    private const FORTH_PAY_BASE_URL = 'https://api.forthpay.com/v1';

    /** Forth honours a perPage large enough to return the whole catalogue in one call. */
    private const CREDITOR_PAGE_SIZE = 25000;

    /**
     * Only reached if a future server-side cap forces real paging. Sized so any
     * sane cap (>= 200/page) still covers the catalogue. If Forth ever caps
     * perPage low enough that this truncates, the map logs
     * "creditor catalogue incomplete" and names fail closed to manual review —
     * but the real fix then is a warm-ahead command, not a bigger cap: 500+
     * sequential requests inside a PMOD job would blow the job timeout and
     * never write the cache at all.
     */
    private const CREDITOR_MAX_PAGES = 60;

    private ?DBConnector $dbConnector = null;
    private array $apiKeyCache = [];

    /** @var array<string, array<string, list<string>>> in-request memo of the creditor catalogue */
    private array $creditorMapCache = [];

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
     * Diagnostic for the Forth resume-payments API. READ-ONLY by default (safe on
     * ANY contact, including real ones): checks token health, whether the contact
     * resolves, and which transactions path is real — surfacing any enrollment id
     * distinct from the contact id. Only when $execute is true does it fire the
     * resume POST itself (an ACTION — TEST FILES ONLY).
     *
     * Disambiguates the AI-inferred paths (developer.setforth.com, 2026-06-30) from
     * the real ones, and rules out token/contact-state confounds before we trust
     * any 404. Never throws — every call's raw status + body is reported.
     *
     * @return array<string, mixed>
     */
    public function probeResumePayments(string $tenantId, string $contactId, bool $execute = false): array
    {
        $out = ['tenant' => $tenantId, 'contact_id' => $contactId, 'execute' => $execute, 'checks' => []];

        $record = function (string $label, string $method, string $path, $resp) use (&$out): void {
            $out['checks'][] = [
                'label'  => $label,
                'call'   => $method . ' ' . $path,
                'status' => $resp->status(),
                'ok'     => $resp->successful(),
                'body'   => mb_substr($resp->body(), 0, 500),
            ];
        };

        // --- READ-ONLY (safe on real contacts) ---

        // Token health: /contact-stages is a known-good CRM endpoint. A 404 here
        // means the token is stale (refresh:forth-api-tokens), not a bad path.
        $record(
            'token_health (contact-stages)',
            'GET',
            '/contact-stages',
            $this->crmClient($tenantId)->get('/contact-stages')
        );

        // Does the contact resolve under this token? Body may reveal an enrollment id.
        $record(
            'contact resolves',
            'GET',
            "/contacts/{$contactId}",
            $this->crmClient($tenantId)->get("/contacts/{$contactId}")
        );

        // Real CRM transactions path (the gateway already uses /contacts/{id}/...).
        $record(
            'transactions (real /contacts path)',
            'GET',
            "/contacts/{$contactId}/transactions",
            $this->crmClient($tenantId)->get("/contacts/{$contactId}/transactions")
        );

        // AI-inferred transactions path — confirms whether the servicing/ prefix is real.
        $record(
            'transactions (inferred servicing path)',
            'GET',
            "/servicing/transactions/get-contact-transactions?id={$contactId}",
            $this->crmClient($tenantId)->get('/servicing/transactions/get-contact-transactions', ['id' => $contactId])
        );

        // --- ACTION (test files only) ---
        // Real path confirmed from the live doc page (2026-06-30):
        //   POST /contacts/{id}/resume  ({id} = contact id; 200 => {"response":{"paused":false}})
        // NOTE: /resume checks the real "payments paused" flag, NOT the CRM lead
        // status ("Paused / Hold") or a gateway hold. To confirm the 200 path we
        // pause first (paused=true) then resume (paused=false) — net state returns
        // to active. Safe on a test file.
        if ($execute) {
            $record(
                'pause (real /contacts/{id}/pause)',
                'POST',
                "/contacts/{$contactId}/pause",
                $this->crmClient($tenantId)->post("/contacts/{$contactId}/pause")
            );
            $record(
                'resume (real /contacts/{id}/resume)',
                'POST',
                "/contacts/{$contactId}/resume",
                $this->crmClient($tenantId)->post("/contacts/{$contactId}/resume")
            );
        }

        Log::info('PMOD: probeResumePayments', $out);

        return $out;
    }

    /**
     * Diagnostic for the Forth endpoints behind the six capture-only banking and
     * creditor actions (add/remove creditor, client banking, sponsor banking).
     *
     * Those handlers were written, tested live in 2026-06, then reverted to
     * capture-only because the Forth calls came back 404 or 400 - but which of
     * the two was never recorded per endpoint, and they are different problems:
     * 404 means the path is wrong, 400 means the path is right and the payload
     * is wrong. This separates them, endpoint by endpoint.
     *
     * READ-ONLY by default and safe on any contact, including real ones.
     *
     * $execute additionally creates a debt and immediately cancels it. That is
     * an ACTION - TEST FILES ONLY - but self-cleaning, mirroring the
     * pause/resume round-trip in probeResumePayments().
     *
     * $executeBanking is deliberately separate and NOT implied by $execute:
     * banking writes change where a client's money is drafted from. It sends an
     * EMPTY body, so it cannot set an account - a live path answers 400 (bad
     * payload) and a wrong path answers 404, which is the whole signal we need.
     *
     * Never throws - every call's raw status + body is reported.
     *
     * @return array<string, mixed>
     */
    public function probeCreditorAndBankingEndpoints(
        string $tenantId,
        string $contactId,
        bool $execute = false,
        bool $executeBanking = false,
    ): array {
        $out = [
            'tenant' => $tenantId,
            'contact_id' => $contactId,
            'execute' => $execute,
            'execute_banking' => $executeBanking,
            'checks' => [],
        ];

        $call = function (string $label, string $method, string $path, callable $send) use (&$out): ?array {
            try {
                $response = $send();

                $out['checks'][] = [
                    'label'  => $label,
                    'call'   => $method . ' ' . $path,
                    'status' => $response->status(),
                    'ok'     => $response->successful(),
                    'body'   => mb_substr($response->body(), 0, 1000),
                ];

                return $response->successful() ? ($response->json() ?? []) : null;
            } catch (\Throwable $e) {
                $out['checks'][] = [
                    'label'  => $label,
                    'call'   => $method . ' ' . $path,
                    'status' => 0,
                    'ok'     => false,
                    'body'   => 'EXCEPTION: ' . $e->getMessage(),
                ];

                return null;
            }
        };

        // --- READ-ONLY (safe on real contacts) ---

        // Token health first: a 404 here means the token is stale
        // (refresh:forth-api-tokens), not that the paths below are wrong.
        $call('token health', 'GET', '/contact-stages',
            fn () => $this->crmClient($tenantId)->get('/contact-stages'));

        $call('contact resolves', 'GET', "/contacts/{$contactId}",
            fn () => $this->crmClient($tenantId)->get("/contacts/{$contactId}"));

        // Both Remove Creditor actions call this before cancelling a debt.
        $call('list debts (remove-creditor step 1)', 'GET', "/contacts/{$contactId}/debts",
            fn () => $this->crmClient($tenantId)->get("/contacts/{$contactId}/debts"));

        // Does a creditor catalogue exist? Both Add Creditor actions hard-require
        // a Forth creditor_id and capture without one, so if portals only send a
        // creditor name we need a name -> id lookup before they can go live.
        $call('creditor catalogue (name -> id lookup)', 'GET', '/creditors',
            fn () => $this->crmClient($tenantId)->get('/creditors'));

        // addBankAccount() targets this resource. A GET establishes whether it
        // exists at all without writing anything.
        $call('bank account resource', 'GET', "/contacts/{$contactId}/bank-account",
            fn () => $this->crmClient($tenantId)->get("/contacts/{$contactId}/bank-account"));

        // Void Settlement is note-only too, and voidSettlementOffer() is the
        // same call PmodLumpSumAction and SkipPaymentAction make on their live
        // path when settlement ids are present - so this path matters beyond
        // the note-only action itself, and has never been confirmed either.
        $settlements = $call('list settlements', 'GET', "/contacts/{$contactId}/settlements",
            fn () => $this->crmClient($tenantId)->get("/contacts/{$contactId}/settlements"));

        // A void cannot be undone, so there is no safe write probe for it.
        // GETting the void path is the non-destructive substitute: a route that
        // does not exist answers 404, one that exists but only accepts POST
        // answers 405. Either way nothing is voided.
        $settlementId = $settlements['response']['results'][0]['id']
            ?? $settlements['response'][0]['id']
            ?? null;

        if ($settlementId !== null) {
            $call('void route exists (GET, 405 = yes)', 'GET', "/settlements/{$settlementId}/void",
                fn () => $this->crmClient($tenantId)->get("/settlements/{$settlementId}/void"));
        } else {
            $out['checks'][] = [
                'label'  => 'void route exists (GET, 405 = yes)',
                'call'   => 'GET /settlements/{id}/void',
                'status' => 0,
                'ok'     => false,
                'body'   => 'SKIPPED: no settlement id on this contact to probe with. Re-run against a contact that has one.',
            ];
        }

        // --- ACTION: debt create then immediate cancel (test files only) ---
        if ($execute) {
            // 'creditor' is omitted on purpose: createDebt() sends it as the Forth
            // creditor id, which we do not have here. If the path is live, Forth's
            // 400 names the fields it wants - which is exactly what we are after.
            $created = $call('create debt (add-creditor step 1)', 'POST', '/debts',
                fn () => $this->crmClient($tenantId)->post('/debts', [
                    'client_id'       => $contactId,
                    'account_number'  => 'PMOD-PROBE',
                    'balance'         => '1.00',
                    'original_amount' => '1.00',
                ]));

            $debtId = $created['response']['id'] ?? $created['id'] ?? $created['debt_id'] ?? null;

            if ($debtId !== null) {
                $call('delete probe debt (cleanup)', 'DELETE', "/debts/{$debtId}",
                    fn () => $this->crmClient($tenantId)->delete("/debts/{$debtId}"));
            } else {
                $out['checks'][] = [
                    'label'  => 'delete probe debt (cleanup)',
                    'call'   => 'DELETE /debts/{id}',
                    'status' => 0,
                    'ok'     => false,
                    'body'   => 'SKIPPED: create returned no debt id, so nothing was left behind.',
                ];
            }
        }

        // --- ACTION: banking write paths, empty body so nothing can be set ---
        if ($executeBanking) {
            $call('bank account write (PUT, empty body)', 'PUT', "/contacts/{$contactId}/bank-account",
                fn () => $this->crmClient($tenantId)->put("/contacts/{$contactId}/bank-account", []));

            $call('bank account write (POST, empty body)', 'POST', "/contacts/{$contactId}/bank-account",
                fn () => $this->crmClient($tenantId)->post("/contacts/{$contactId}/bank-account", []));
        }

        Log::info('PMOD: probeCreditorAndBankingEndpoints', $out);

        return $out;
    }

    /**
     * Resume a paused contact via the real Forth CRM endpoint (confirmed from the
     * doc page 2026-06-30): POST /contacts/{id}/resume, where {id} is the contact
     * id. Success is HTTP 200 with response.paused === false. This replaces the
     * headless-browser #resumebtn click for Phase 4's high-volume daily resume.
     *
     * Returns a structured outcome rather than throwing on business conflicts:
     *   - 'resumed'   : HTTP 200, response.paused === false (was paused, now active).
     *   - 'not_paused': HTTP 409 "Client is not paused" (already active — no-op,
     *                   confirmed live 2026-06-30). Not an error.
     *   - 'dry_run'   : no call made.
     * Only a genuine failure (other non-2xx / transport error) throws, so the caller
     * can report "(Unable to Resume)" while still writing the status.
     *
     * @return array{result: string, paused: bool|null, status: int, message: string}
     */
    public function resumeContact(string $tenantId, string $contactId, bool $dryRun = false): array
    {
        Log::info('PMOD: resume contact', [
            'contact_id' => $contactId,
            'tenant_id'  => $tenantId,
            'dry_run'    => $dryRun,
        ]);

        if ($dryRun) {
            return ['result' => 'dry_run', 'paused' => null, 'status' => 0, 'message' => 'dry_run'];
        }

        $response = $this->crmClient($tenantId)->post("/contacts/{$contactId}/resume");
        $status = $response->status();
        $message = (string) ($response->json('status.message') ?? '');

        if ($response->successful()) {
            $paused = $response->json('response.paused');

            Log::info('PMOD: contact resumed', [
                'contact_id' => $contactId,
                'tenant_id'  => $tenantId,
                'paused'     => $paused,
            ]);

            return [
                'result'  => 'resumed',
                'paused'  => is_bool($paused) ? $paused : null,
                'status'  => $status,
                'message' => $message,
            ];
        }

        // 409 "Client is not paused" — already active; nothing to resume.
        if ($status === 409) {
            Log::info('PMOD: resume contact - already not paused', [
                'contact_id' => $contactId,
                'tenant_id'  => $tenantId,
                'message'    => $message,
            ]);

            return ['result' => 'not_paused', 'paused' => false, 'status' => 409, 'message' => $message];
        }

        Log::warning('PMOD: resume contact failed', [
            'contact_id' => $contactId,
            'tenant_id'  => $tenantId,
            'status'     => $status,
            'response'   => mb_substr($response->body(), 0, 300),
        ]);

        throw new \RuntimeException("Failed to resume contact {$contactId}: HTTP {$status} {$message}");
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

        // Forth deletes a debt with DELETE /debts/{id}. POST /debts/{id}/cancel
        // answers 404 — verified against production 2026-08-28, which is why both
        // Remove Creditor actions failed. A success returns
        // {"code":200,"message":"Successfully deleted object"}.
        $response = $this->crmClient($workItem->tenantId)
            ->delete("/debts/{$debtId}");

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

    /**
     * Resolve a creditor NAME to Forth's creditor id. Returns null when the name
     * is unknown or matches more than one creditor — see PmodCreditorDirectory
     * for why this fails closed rather than guessing.
     *
     * Matching is done on a normalised form (uppercase, punctuation flattened to
     * spaces) so "SYNCB/ABCWH" and "SYNCB ABCWH" resolve identically. An exact
     * normalised hit wins; only if there is none does it fall back to a substring
     * match, and then only for names of 5+ characters, so a short name like
     * "AT T" cannot sweep up unrelated creditors.
     */
    /**
     * Decide which Forth creditor id to use, given whatever the consumer claimed
     * and the creditor name they sent.
     *
     * A claimed id is VALIDATED, never trusted. Measured 2026-08-31: of four
     * creditor ids seen in real payloads, only one existed in the Forth
     * catalogue. 10016072, 10100974 and 601 are from another id space (DPP
     * legacy), while the catalogue runs 25,399,828..28,315,871. Sending one of
     * those to POST /debts would attach the debt to nothing, or to the wrong
     * creditor.
     *
     * Order: a claimed id that exists wins; otherwise resolve the name; otherwise
     * null, and the caller captures for manual review.
     */
    public function resolveCreditorId(string $tenantId, ?string $claimedId, string $creditorName): ?string
    {
        $claimedId = trim((string) $claimedId);

        if ($claimedId !== '') {
            if ($this->creditorExists($tenantId, $claimedId)) {
                return $claimedId;
            }

            Log::warning('PMOD: payload creditor_id is not in the Forth catalogue, ignoring it', [
                'tenant' => $tenantId, 'claimed_creditor_id' => $claimedId, 'creditor_name' => $creditorName,
            ]);
        }

        return $this->findCreditorId($tenantId, $creditorName);
    }

    public function creditorExists(string $tenantId, string $creditorId): bool
    {
        $creditorId = trim($creditorId);

        return $creditorId !== '' && isset($this->creditorCatalogue($tenantId)['ids'][$creditorId]);
    }

    /**
     * Resolve a creditor NAME to a Forth creditor id. Returns null when the name
     * is unknown or matches more than one creditor.
     *
     * Matching is on a normalised form (uppercase, punctuation flattened to
     * spaces), so SYNCB/ABCWH and SYNCB ABCWH resolve identically. An exact hit
     * wins; only without one does it try a substring match, and then only for
     * names of 5+ characters so a short name cannot sweep up unrelated rows.
     *
     * Ambiguity is real, not theoretical: the LDR catalogue holds 55 CITI* rows
     * including CITIBANK N. A., CITIBANKNA, CITI/costco and the typo CITI/cosco,
     * so a bare Citibank correctly resolves to nothing.
     */
    public function findCreditorId(string $tenantId, string $creditorName): ?string
    {
        $needle = self::normalizeCreditorName($creditorName);

        if ($needle === '') {
            return null;
        }

        $names = $this->creditorCatalogue($tenantId)['names'];

        if (isset($names[$needle])) {
            if (count($names[$needle]) === 1) {
                Log::info('PMOD: creditor resolved by exact name', [
                    'tenant' => $tenantId, 'name' => $creditorName, 'creditor_id' => $names[$needle][0],
                ]);

                return $names[$needle][0];
            }

            Log::warning('PMOD: creditor name is ambiguous, refusing to guess', [
                'tenant' => $tenantId, 'name' => $creditorName, 'candidates' => $names[$needle],
            ]);

            return null;
        }

        if (mb_strlen($needle) < 5) {
            return null;
        }

        $hits = [];
        foreach ($names as $name => $ids) {
            if (mb_strlen((string) $name) < 5) {
                continue;
            }

            if (str_contains((string) $name, $needle) || str_contains($needle, (string) $name)) {
                foreach ($ids as $id) {
                    $hits[$id] = $name;
                }
            }
        }

        if (count($hits) === 1) {
            $id = (string) array_key_first($hits);

            Log::info('PMOD: creditor resolved by substring', [
                'tenant' => $tenantId, 'name' => $creditorName, 'matched' => reset($hits), 'creditor_id' => $id,
            ]);

            return $id;
        }

        Log::warning('PMOD: creditor could not be resolved', [
            'tenant' => $tenantId, 'name' => $creditorName, 'match_count' => count($hits),
            'candidates' => array_slice($hits, 0, 5, true),
        ]);

        return null;
    }

    /**
     * The creditor catalogue, indexed both ways: normalised name => list of ids
     * for resolution, and id => name for validating a claimed id. Cached 24h per
     * tenant; 10,077 rows on LDR as of 2026-08-31 and effectively static.
     *
     * @return array{names: array<string, list<string>>, ids: array<string, string>}
     */
    private function creditorCatalogue(string $tenantId): array
    {
        // v2: the cached shape changed when id-validation was added. The suffix
        // stops a v1 map (names only) being read back as a v2 one.
        $cacheKey = 'pmod_creditors_v2_' . strtolower($tenantId);

        if (isset($this->creditorMapCache[$cacheKey])) {
            return $this->creditorMapCache[$cacheKey];
        }

        $catalogue = Cache::remember($cacheKey, now()->addHours(24), function () use ($tenantId): array {
            $names = [];
            $ids = [];
            $expected = null;

            // Forth pages with pageNo/perPage - camelCase, echoed back in the
            // envelope alongside total. It honours a perPage large enough to
            // return the whole catalogue in one request, so the normal path is a
            // single call. The loop stays so a future server-side cap degrades to
            // real paging rather than silently truncating. (_limit/_offset are
            // ignored here - that convention belongs to the ForthPay reports API.)
            for ($pageNo = 1; $pageNo <= self::CREDITOR_MAX_PAGES; $pageNo++) {
                [$rows, $total] = $this->fetchCreditorPage($tenantId, $pageNo, self::CREDITOR_PAGE_SIZE);
                $expected ??= $total;

                if ($rows === []) {
                    break;
                }

                $new = 0;
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $id = trim((string) ($row['id'] ?? ''));
                    $name = self::normalizeCreditorName((string) ($row['company_name'] ?? ''));

                    if ($id === '' || isset($ids[$id])) {
                        continue;
                    }

                    $ids[$id] = $name;
                    $new++;

                    if ($name !== '') {
                        $names[$name][] = $id;
                    }
                }

                // No new ids means paging is not advancing - stop rather than spin.
                if ($new === 0) {
                    break;
                }

                if ($expected !== null && count($ids) >= $expected) {
                    break;
                }
            }

            if ($expected !== null && count($ids) < $expected) {
                Log::warning('PMOD: creditor catalogue incomplete - names may fail to resolve', [
                    'tenant' => $tenantId, 'fetched' => count($ids), 'total_reported' => $expected,
                ]);
            }

            Log::info('PMOD: creditor catalogue loaded', [
                'tenant' => $tenantId, 'creditors' => count($ids),
                'distinct_names' => count($names), 'total_reported' => $expected,
            ]);

            return ['names' => $names, 'ids' => $ids];
        });

        $this->creditorMapCache[$cacheKey] = $catalogue;

        return $catalogue;
    }

    /**
     * One page of the creditor catalogue.
     *
     * @return array{0: list<array<string, mixed>>, 1: int|null} rows, and the
     *         total the envelope reports (null when unavailable)
     */
    private function fetchCreditorPage(string $tenantId, int $pageNo, int $perPage): array
    {
        $response = $this->crmClient($tenantId)->get('/creditors', [
            'pageNo' => $pageNo,
            'perPage' => $perPage,
        ]);

        if (! $response->successful()) {
            Log::error('PMOD: Failed to list creditors', [
                'tenant_id' => $tenantId, 'page_no' => $pageNo,
                'status' => $response->status(), 'response' => mb_substr($response->body(), 0, 300),
            ]);

            return [[], null];
        }

        // Envelope: {"response":{"data":[...],"total":N,"pageNo":N,"perPage":N}}
        $rows = $response->json('response.data');
        $total = $response->json('response.total');

        return [
            is_array($rows) ? array_values($rows) : [],
            is_numeric($total) ? (int) $total : null,
        ];
    }

    private static function normalizeCreditorName(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9 ]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
