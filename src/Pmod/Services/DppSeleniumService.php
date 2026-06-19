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
     * Resume payments for a single contact by clicking #resumebtn on the tools
     * page — the action the VBA's `If ResumePayments = "Resume Payments"` block
     * performed (Forth has no REST equivalent; the API endpoint 404s).
     *
     * Returns success only when the button was present, read "Resume Payments",
     * and the confirm alert was accepted. A non-actionable button (already
     * resumed, paused, etc.) returns status=failed so the caller logs the VBA's
     * "(Unable to Resume)".
     *
     * @return array{status: 'success'|'failed', message: string}
     */
    public function resumePayments(string $tenant, string $contactId, bool $dryRun = false): array
    {
        $tenant = strtoupper(trim($tenant));
        $contactId = trim($contactId);

        Log::info('DPP: resumePayments requested', [
            'tenant' => $tenant,
            'contact_id' => $contactId,
            'dry_run' => $dryRun,
        ]);

        if ($dryRun) {
            return ['status' => 'success', 'message' => 'dry-run: would click #resumebtn'];
        }

        $this->ensureLogin($tenant);
        $client = $this->client();

        $url = self::DPP_BASE_URL . '/index.php?module=tools&page=view&cid=' . rawurlencode($contactId);

        try {
            $client->request('GET', $url);
            $client->waitFor('#resumebtn', 15);
        } catch (\Throwable $e) {
            // No resume button on the page → not in a resumable state.
            return ['status' => 'failed', 'message' => '#resumebtn not present'];
        }

        try {
            $button = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#resumebtn'));
            $text = trim($button->getText());

            if ($text !== 'Resume Payments') {
                return ['status' => 'failed', 'message' => "resume button not actionable (text: '{$text}')"];
            }

            $button->click();
            // Clicking triggers a JS confirm() — accept it, like VBA's SwitchToAlert.Accept.
            $client->getWebDriver()->switchTo()->alert()->accept();

            Log::info('DPP: payments resumed', ['tenant' => $tenant, 'contact_id' => $contactId]);

            return ['status' => 'success', 'message' => 'payments resumed'];
        } catch (\Throwable $e) {
            throw new DppSeleniumException(
                'resumePayments failed: ' . $e->getMessage(),
                tenant: $tenant,
                contactId: $contactId,
                stage: 'resume',
                previous: $e,
            );
        }
    }

    /**
     * Lazily create and reuse one headless Chromium session for the whole run.
     * Returns a Symfony Panther Client; referenced via FQN so this file still
     * loads when symfony/panther isn't installed (only calling it requires it).
     */
    private function client(): \Symfony\Component\Panther\Client
    {
        if ($this->client === null) {
            // Panther picks up the Chromium binary from this env var.
            if ($this->chromeBinary !== '') {
                putenv('PANTHER_CHROME_BINARY=' . $this->chromeBinary);
                $_SERVER['PANTHER_CHROME_BINARY'] = $this->chromeBinary;
            }

            $this->client = \Symfony\Component\Panther\Client::createChromeClient(
                $this->chromeDriverBinary !== '' ? $this->chromeDriverBinary : null,
                [
                    '--headless=new',
                    '--no-sandbox',
                    '--disable-gpu',
                    '--disable-dev-shm-usage',
                    '--window-size=1920,1080',
                ],
            );
        }

        return $this->client;
    }

    /**
     * Make sure we have a live, authenticated browser session for the tenant.
     * No-op if we've already logged in this run. Mirrors CRMLoginLDR/PLAW:
     * fill #Username / #Password / #Acct (= tenant name) and submit.
     *
     * NOTE: the login URL is the DPP root; if the form isn't found there we'll
     * see it in the failure and adjust to the exact CRMURLLogin path.
     */
    private function ensureLogin(string $tenant): void
    {
        if (isset($this->loggedIn[$tenant])) {
            return;
        }

        $this->assertCredentials($tenant);
        $creds = $this->tenantCredentials[$tenant];

        $client = $this->client();

        try {
            $client->request('GET', self::DPP_BASE_URL . '/');
            $client->waitFor('#Username', 20);

            $username = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#Username'));
            $username->clear();
            $username->sendKeys((string) $creds['username']);

            $password = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#Password'));
            $password->clear();
            $password->sendKeys((string) $creds['password']);

            // #Acct = the DPP account name, which is the tenant ("LDR" / "PLAW").
            $account = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#Acct'));
            $account->clear();
            $account->sendKeys($tenant);

            $client->findElement(\Facebook\WebDriver\WebDriverBy::name('Submit'))->click();

            // Wait for the login form to go away (post-login redirect), like the
            // VBA's wait after Submit — we don't assert a specific landing element.
            $client->wait(20)->until(
                \Facebook\WebDriver\WebDriverExpectedCondition::invisibilityOfElementLocated(
                    \Facebook\WebDriver\WebDriverBy::cssSelector('#Username')
                )
            );
        } catch (\Throwable $e) {
            throw new DppSeleniumException(
                'DPP login failed: ' . $e->getMessage(),
                tenant: $tenant,
                stage: 'login',
                previous: $e,
            );
        }

        $this->loggedIn[$tenant] = true;
        Log::info('DPP: logged in', ['tenant' => $tenant]);
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
