<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use Illuminate\Support\Facades\Log;

/**
 * Drives login.debtpaypro.com via Symfony Panther (headless Chromium) to perform
 * the "Cancel Program" UI flow for a given contact.
 *
 * Used by Generate:resume-payments Phase 5 when a contact hits the day-4 cancel
 * threshold. Forth's CRM API has no single endpoint that mirrors the #cancelbtn
 * click (with drop reason, refund, complete-and-delete events), so we drive the
 * UI directly — same as the retiring VBA macro did.
 *
 * Lifecycle:
 *   - One instance per `Generate:resume-payments` run (bound singleton in
 *     ReportsServiceProvider).
 *   - Lazy login per tenant on first cancelProgram() call; cookies/session are
 *     reused for subsequent contacts under the same tenant.
 *   - Browser is closed on __destruct.
 *
 * Selector / form-field documentation lives at the top of {@see cancelProgram()}.
 * The Panther-specific selector code is intentionally STUBBED — we cannot finalize
 * selectors until we record the DPP login flow against the real portal with the
 * actual credentials. Until then, attempts to call cancelProgram() throw with a
 * clear "not yet recorded" message so dry-runs and tests fail loudly.
 */
final class DppSeleniumService
{
    private const DPP_BASE_URL = 'https://login.debtpaypro.com';

    /** @var array<string, true> Tenants we've already logged in for this process. */
    private array $loggedIn = [];

    /**
     * Panther client lazily created on first use. We type-hint as mixed because
     * `symfony/panther` is not yet installed; once `composer require` runs the
     * type can tighten to `?\Symfony\Component\Panther\Client`.
     */
    private mixed $client = null;

    public function __construct(
        /** @var array<string, array{username: ?string, password: ?string}> */
        private readonly array $tenantCredentials,
        private readonly string $chromeBinary,
        private readonly string $chromeDriverBinary,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            tenantCredentials: [
                'LDR' => [
                    'username' => config('services.dpp_portal.ldr.username'),
                    'password' => config('services.dpp_portal.ldr.password'),
                ],
                'PLAW' => [
                    'username' => config('services.dpp_portal.plaw.username'),
                    'password' => config('services.dpp_portal.plaw.password'),
                ],
            ],
            chromeBinary: (string) config('services.panther.chrome_binary', '/usr/bin/chromium-browser'),
            chromeDriverBinary: (string) config('services.panther.chrome_driver', '/usr/bin/chromedriver'),
        );
    }

    /**
     * Execute the Cancel Program flow for a single contact.
     *
     * Form fields driven (mirroring the VBA):
     *   #cancelbtn                         click → triggers JS alert → accept
     *   #dropped_reason                    = "Unable to Resolve NSF" (select option)
     *   first <textarea>                   = "Attempted to resume payments 4 times."
     *   #dorefund                          checked if balance > 0
     *   #amount                            = $form['refund_amount'] when refunding
     *   #start_date                        = $form['process_date'] (next business day)
     *   #complete_delete                   = "Complete" (select option)
     *   #delete_events                     checked
     *   #cancellation_request_date         = today (best-effort, optional)
     *   #savebtn                           click → triggers JS alert → accept
     *
     * @param array{
     *   refund_amount?: float|int|null,
     *   process_date?: string,
     *   drop_reason?: string,
     *   note?: string,
     * } $form
     *
     * @return array{status: 'success'|'failed', message: string, screenshot_path?: string}
     */
    public function cancelProgram(string $tenant, string $contactId, array $form, bool $dryRun = false): array
    {
        $tenant = strtoupper(trim($tenant));
        $contactId = trim($contactId);

        Log::info('DPP: cancelProgram requested', [
            'tenant' => $tenant,
            'contact_id' => $contactId,
            'dry_run' => $dryRun,
            'has_refund' => !empty($form['refund_amount']),
        ]);

        if ($dryRun) {
            return [
                'status' => 'success',
                'message' => 'dry-run: would click #cancelbtn and fill form',
            ];
        }

        $this->assertCredentials($tenant);

        // TODO once symfony/panther is installed + DPP login form has been
        //      recorded with the real credentials, fill in:
        //        1. $this->ensureLogin($tenant)
        //        2. navigate to /index.php?module=tools&page=view&cid={$contactId}
        //        3. click #cancelbtn, accept alert
        //        4. fill the form fields (see docblock above)
        //        5. click #savebtn, accept alert
        //        6. wait for redirect / confirmation
        //        7. return ['status' => 'success', ...]
        //      Catch WebDriver exceptions and rethrow as DppSeleniumException
        //      with $tenant + $contactId + $stage context so the caller can log
        //      the failure to TblEnrollmentCancellations.Cancel_Result.
        throw new DppSeleniumException(
            'cancelProgram() is not yet wired — Panther selectors must be recorded against the DPP portal first.',
            tenant: $tenant,
            contactId: $contactId,
            stage: 'init',
        );
    }

    /**
     * Make sure we have a live, authenticated browser session for the tenant.
     * No-op if we've already logged in this run.
     */
    private function ensureLogin(string $tenant): void
    {
        if (isset($this->loggedIn[$tenant])) {
            return;
        }

        $this->assertCredentials($tenant);

        // TODO recordings needed:
        //   - Selector for username field on https://login.debtpaypro.com
        //   - Selector for password field
        //   - Selector for submit button
        //   - Post-login redirect URL / element that proves auth worked
        //   - Tenant-switch mechanism (if SystemAdmin sees LDR and PLAW from the
        //     same login, we may need to switch tenants per call; if separate
        //     logins, we re-login per tenant.)
        throw new DppSeleniumException(
            'ensureLogin() is not yet wired — DPP login form must be recorded first.',
            tenant: $tenant,
            stage: 'login',
        );
    }

    private function assertCredentials(string $tenant): void
    {
        $creds = $this->tenantCredentials[$tenant] ?? null;

        if ($creds === null || empty($creds['username']) || empty($creds['password'])) {
            throw new DppSeleniumException(
                "No DPP portal credentials configured for tenant {$tenant}. Set DPP_PORTAL_USERNAME_{$tenant} and DPP_PORTAL_PASSWORD_{$tenant} in .env.",
                tenant: $tenant,
                stage: 'config',
            );
        }
    }

    public function __destruct()
    {
        if ($this->client !== null && method_exists($this->client, 'quit')) {
            try {
                $this->client->quit();
            } catch (\Throwable $e) {
                Log::warning('DPP: failed to quit Panther client cleanly', ['error' => $e->getMessage()]);
            }
        }
    }
}
