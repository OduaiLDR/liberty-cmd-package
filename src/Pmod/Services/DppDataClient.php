<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Writes contact fields (client_status, notebody) to DebtPayPro via its legacy
 * "post" data endpoint — the exact mechanism the retiring ResumePayments VBA
 * used (UpdateCRMDataLDR / UpdateCRMDataPLAW):
 *
 *   POST https://login.debtpaypro.com/post/{apiKey}/?updaterecord={id}&{field}={value}
 *
 * The endpoint takes the status TITLE string (e.g. "LDR Enrolled (NSF-1)"), not
 * an integer ID, so no stage/status-ID lookup is needed. Keys are static and
 * per-org (separate from the OAuth tokens in TblAPIKeys). The same endpoint also
 * exists under login.forthcrm.com (commented alt in the VBA); we use the DPP host.
 *
 * The VBA fired the request and only retried on transport failure (it ignored the
 * HTTP body); we mirror that — any completed 2xx/3xx counts as success.
 */
final class DppDataClient
{
    /**
     * @param array<string, string> $tenantKeys lowercased tenant => DPP post key
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $tenantKeys,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: rtrim((string) config('services.dpp_post.base_url', 'https://login.debtpaypro.com'), '/'),
            tenantKeys: [
                'ldr' => (string) config('services.dpp_post.ldr', ''),
                'plaw' => (string) config('services.dpp_post.plaw', ''),
            ],
        );
    }

    public function setClientStatus(string $tenant, string $contactId, string $statusTitle, bool $dryRun = false): bool
    {
        return $this->updateRecord($tenant, $contactId, 'client_status', $statusTitle, $dryRun);
    }

    public function addNote(string $tenant, string $contactId, string $note, bool $dryRun = false): bool
    {
        return $this->updateRecord($tenant, $contactId, 'notebody', $note, $dryRun);
    }

    /**
     * Mirrors UpdateCRMData*: strips any "LLG-"/prefix to the numeric id, posts a
     * single field, retries on transport failure. Returns true on a completed
     * 2xx/3xx response.
     */
    public function updateRecord(string $tenant, string $contactId, string $field, string $value, bool $dryRun = false): bool
    {
        $tenant = strtolower(trim($tenant));
        $id = $this->numericId($contactId);

        if ($dryRun) {
            Log::info('DPP post: DRY RUN - would update record', [
                'tenant' => $tenant,
                'contact_id' => $id,
                'field' => $field,
                'value' => $value,
            ]);

            return true;
        }

        $key = trim($this->tenantKeys[$tenant] ?? '', " \t\n\r\0\x0B/");
        if ($key === '') {
            throw new RuntimeException(
                "No DPP post API key configured for tenant {$tenant}. Set DPP_POST_API_KEY_" . strtoupper($tenant) . ' in .env.'
            );
        }

        // Build the URL exactly like the VBA: /post/{key}/?updaterecord={id}&{field}={value}.
        // rawurlencode the value (space -> %20, parens -> %28/%29) so titles like
        // "LDR Enrolled (NSF-1)" transmit safely; DPP url-decodes on receipt.
        $url = sprintf(
            '%s/post/%s/?updaterecord=%s&%s=%s',
            $this->baseUrl,
            rawurlencode($key),
            rawurlencode($id),
            $field,
            rawurlencode($value),
        );

        try {
            $response = Http::withOptions(['allow_redirects' => false])
                ->timeout(30)
                ->retry(3, 15000)
                ->post($url);
        } catch (Throwable $e) {
            Log::error('DPP post: request failed after retries', [
                'tenant' => $tenant,
                'contact_id' => $id,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $ok = $response->status() >= 200 && $response->status() < 400;

        Log::info('DPP post: update record', [
            'tenant' => $tenant,
            'contact_id' => $id,
            'field' => $field,
            'value' => $value,
            'http_status' => $response->status(),
            'ok' => $ok,
        ]);

        return $ok;
    }

    /**
     * VBA: `Data = Split(ID, "-"): ID = Data(1)` — keep the segment after the first
     * dash (e.g. "LLG-12345" -> "12345"). Snowflake CONTACT_IDs are already numeric.
     */
    private function numericId(string $contactId): string
    {
        $contactId = trim($contactId);
        if (str_contains($contactId, '-')) {
            $parts = explode('-', $contactId);

            return $parts[1] ?? $contactId;
        }

        return $contactId;
    }
}
