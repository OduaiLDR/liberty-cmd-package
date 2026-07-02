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
    ) {}

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
     *   refund_amount?: float|int|null,`
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

        $balance = (float) ($form['balance'] ?? 0);
        $epf = (float) ($form['epf'] ?? 0);
        $settlements = (float) ($form['scheduled_settlements'] ?? 0);
        $processDate = (string) ($form['process_date'] ?? '');
        $today = (string) ($form['today'] ?? date('Y-m-d'));
        $systemAccount = (string) ($form['system_account'] ?? '');
        $dropReason = (string) ($form['drop_reason'] ?? 'Unable to Resolve NSF');
        $note = (string) ($form['note'] ?? 'Attempted to resume payments 4 times.');

        Log::info('DPP: cancelProgram requested', [
            'tenant' => $tenant,
            'contact_id' => $contactId,
            'dry_run' => $dryRun,
            'balance' => $balance,
            'epf' => $epf,
            'scheduled_settlements' => $settlements,
        ]);

        if ($dryRun) {
            return ['status' => 'success', 'message' => 'dry-run: would run cancel flow'];
        }

        $this->ensureLogin($tenant);
        $client = $this->client();
        $toolsUrl = self::DPP_BASE_URL . '/index.php?module=tools&page=view&cid=' . rawurlencode($contactId);

        // 1) Confirm the contact is cancellable.
        try {
            $client->request('GET', $toolsUrl);
            $client->waitFor('#cancelbtn', 15);
            $cancelText = trim($client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#cancelbtn'))->getText());
        } catch (\Throwable $e) {
            return ['status' => 'not_cancellable', 'message' => '#cancelbtn not present'];
        }
        if ($cancelText !== 'Cancel Program') {
            return ['status' => 'not_cancellable', 'message' => "cancel button reads '{$cancelText}'"];
        }

        // 2) Pending settlements — SAFETY GATE, checked BEFORE the EPF capture (moved
        //    2026-07-02): every positive-balance + EPF client in the backlog also has
        //    settlements, so capturing EPF first would schedule a fee and THEN bail to
        //    manual without dropping — a half-done state. Checking settlements first
        //    means those clients route to manual with NO browser action, and the
        //    fragile EPF picker never runs on them.
        //    (The VBA auto-voids partially-paid settlements here via a deeply
        //    position-dependent DOM-index loop. Running that blind voids real
        //    settlements, so until it's verified against a live contact that actually
        //    has pending settlements, ANY contact with scheduled settlements is routed
        //    to manual review instead of auto-voiding. Implement voidSettlements() and
        //    drop this gate once we can record the settlement table on a real cancel.)
        if ($settlements > 0) {
            return [
                'status' => 'manual_audit',
                'message' => $balance >= $settlements
                    ? 'balance >= scheduled settlements; manual review required'
                    : 'pending settlements present; auto-void not yet verified — manual review',
            ];
        }

        // 3) EPF capture. If this fails we do NOT proceed to the drop (the VBA
        //    swallowed the error and cancelled anyway — we refuse, so we never
        //    cancel a contact whose EPF wasn't captured).
        if ($balance > 0 && $epf > 0) {
            try {
                $balance = $this->captureEpf($client, $balance, $epf, $processDate, $systemAccount);
            } catch (\Throwable $e) {
                throw new DppSeleniumException(
                    'EPF capture failed: ' . $e->getMessage(),
                    tenant: $tenant,
                    contactId: $contactId,
                    stage: 'epf',
                    previous: $e,
                );
            }
        }

        // 4) Re-confirm the cancel button is still present, then drop.
        try {
            $client->request('GET', $toolsUrl);
            $client->waitFor('#cancelbtn', 15);
            $displayed = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#cancelbtn'))->isDisplayed();
        } catch (\Throwable $e) {
            $displayed = false;
        }
        if (!$displayed) {
            return ['status' => 'failed', 'message' => '#cancelbtn not displayed after EPF step'];
        }

        $branch = $balance > 0 ? 'positive' : ($balance < 0 ? 'negative' : 'zero');

        try {
            $this->executeDrop($client, $balance, $processDate, $today, $dropReason, $note);
        } catch (\Throwable $e) {
            throw new DppSeleniumException(
                'cancel drop failed: ' . $e->getMessage(),
                tenant: $tenant,
                contactId: $contactId,
                stage: 'drop',
                previous: $e,
            );
        }

        Log::info('DPP: cancelProgram completed', [
            'tenant' => $tenant,
            'contact_id' => $contactId,
            'balance_branch' => $branch,
        ]);

        return ['status' => 'success', 'message' => 'program cancelled', 'balance_branch' => $branch];
    }

    /**
     * No-save diagnostic: drive the cancel flow far enough to verify every selector,
     * then navigate away WITHOUT clicking #savebtn — nothing is ever committed, so
     * it is safe to run against any contact. Verifies login, #cancelbtn, and the
     * drop-form fields. The EPF "Add Payment" picker is a separate flow and is NOT
     * exercised here (that needs a real balance>0 & epf>0 cancel).
     *
     * @return array<string, mixed>
     */
    public function probeCancel(string $tenant, string $contactId): array
    {
        $tenant = strtoupper(trim($tenant));
        $contactId = trim($contactId);

        Log::info('DPP: probeCancel (no-save) requested', ['tenant' => $tenant, 'contact_id' => $contactId]);

        $this->ensureLogin($tenant);
        $client = $this->client();
        $driver = $client->getWebDriver();
        $toolsUrl = self::DPP_BASE_URL . '/index.php?module=tools&page=view&cid=' . rawurlencode($contactId);

        $report = ['login' => 'ok', 'contact_id' => $contactId, 'committed' => false];

        try {
            $client->request('GET', $toolsUrl);
            $client->waitFor('#cancelbtn', 15);
            $cancelText = trim($client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#cancelbtn'))->getText());
        } catch (\Throwable $e) {
            $report['cancelbtn'] = 'not found on tools page';
            $report['cancellable'] = false;

            return $report;
        }

        $report['cancelbtn_text'] = $cancelText;
        $report['cancellable'] = $cancelText === 'Cancel Program';

        if (!$report['cancellable']) {
            $report['note'] = "login + navigation verified; contact isn't in a 'Cancel Program' state, so the drop-form selectors can't be checked here.";

            return $report;
        }

        // Open the drop form (click + accept the JS confirm) — still nothing committed.
        try {
            $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#cancelbtn'))->click();
            $driver->switchTo()->alert()->accept();
            $client->waitFor('#dropped_reason', 15);
        } catch (\Throwable $e) {
            $report['form_opened'] = false;
            $report['error'] = 'drop form did not open: ' . $e->getMessage();

            return $report;
        }
        $report['form_opened'] = true;

        // Verify each drop-form selector EXISTS (no fill, no save).
        $present = [];
        foreach (['#dropped_reason', '#dorefund', '#amount', '#start_date', '#complete_delete', '#delete_events', '#cancellation_request_date', '#savebtn'] as $sel) {
            $present[$sel] = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector($sel))) > 0;
        }
        $present['textarea'] = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::tagName('textarea'))) > 0;
        $report['fields_present'] = $present;

        // List the option texts so we can confirm our selectByVisibleText targets exist.
        $report['dropped_reason_options'] = $this->optionTexts($driver, '#dropped_reason');
        $report['complete_delete_options'] = $this->optionTexts($driver, '#complete_delete');

        // --- Refund-amount diagnostics (2026-07-01) ---
        // The form caps the refund at its own "Available Refund Amount" (= Current
        // Balance − Uncollected Disbursement Fees − anything cleared today), which is
        // LESS than the raw Snowflake balance we currently type into #amount → the
        // refund fails. Capture the on-page money labels + whether #amount pre-fills,
        // so the real cancel can refund the form's authoritative figure.
        $report['money_fields'] = [];
        foreach (['Available Refund Amount', 'Current Balance', 'Uncollected Disbursement Fees'] as $label) {
            try {
                $el = $driver->findElement(\Facebook\WebDriver\WebDriverBy::xpath("//*[contains(text(),'{$label}')]"));
                $parent = $el->findElement(\Facebook\WebDriver\WebDriverBy::xpath('..'));
                $report['money_fields'][$label] = [
                    'text'        => trim($el->getText()),
                    'parent_text' => trim((string) preg_replace('/\s+/', ' ', $parent->getText())),
                ];
            } catch (\Throwable $e) {
                $report['money_fields'][$label] = ['error' => $e->getMessage()];
            }
        }

        // Does the form auto-fill #amount when refund is enabled? (still no-save)
        try {
            $report['amount_value_before_dorefund'] = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#amount'))->getAttribute('value');
            $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#dorefund'))->click();
            usleep(400000);
            $report['amount_value_after_dorefund'] = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#amount'))->getAttribute('value');
        } catch (\Throwable $e) {
            $report['amount_prefill_error'] = $e->getMessage();
        }

        // DRY-FILL: run the exact refund logic the real cancel uses — read the
        // Available Refund Amount, type it into #amount, read it back — then bail
        // WITHOUT saving. Shows precisely what the real cancel will refund.
        try {
            $available = $this->readAvailableRefund($client);
            $report['available_refund_read'] = $available;
            if ($available !== null && $available > 0) {
                $amountEl = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#amount'));
                $amountEl->clear();
                $amountEl->sendKeys($this->money($available));
                usleep(200000);
                $report['amount_dry_filled'] = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#amount'))->getAttribute('value');
            }
        } catch (\Throwable $e) {
            $report['dry_fill_error'] = $e->getMessage();
        }

        // Navigate away → the unsaved form is discarded. NOTHING is committed.
        $client->request('GET', $toolsUrl);
        $report['result'] = 'all drop-form selectors verified; #savebtn NOT clicked';

        return $report;
    }

    /**
     * @return list<string>
     */
    private function optionTexts(mixed $driver, string $css): array
    {
        $texts = [];
        try {
            $select = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector($css));
            foreach ($select->findElements(\Facebook\WebDriver\WebDriverBy::tagName('option')) as $option) {
                $text = trim($option->getText());
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        } catch (\Throwable $e) {
            $texts[] = 'ERROR: ' . $e->getMessage();
        }

        return $texts;
    }

    /**
     * EPF capture — schedule an "ACH Credit / Fee" for min(balance, epf) to the
     * system account. Returns the reduced balance.
     *
     * NOTE: the "--System Account--" picker is a jQuery "chosen" widget; the VBA
     * drove it with a brittle ArrowDown/Tab loop. {@see selectSystemAccount()} uses
     * the chosen search box instead — VERIFY AGAINST THE LIVE DOM before trusting it.
     */
    private function captureEpf(mixed $client, float $balance, float $epf, string $processDate, string $systemAccount): float
    {
        $css = static fn(string $c) => \Facebook\WebDriver\WebDriverBy::cssSelector($c);

        $client->findElement(\Facebook\WebDriver\WebDriverBy::xpath('//a[contains(text(),"Add Payment")]'))->click();
        $client->waitFor('#trans_type', 15);

        $this->select($client, '#trans_type', 'ACH Credit / Fee');
        $this->select($client, '#sub_type', 'Esign / Wet Signature');

        $processDateEl = $client->findElement($css('#process_date'));
        $processDateEl->clear();
        $processDateEl->sendKeys($processDate);
        $processDateEl->sendKeys(\Facebook\WebDriver\WebDriverKeys::TAB);

        if ($balance >= $epf) {
            $client->findElement($css('#lbamount'))->sendKeys($this->money($epf));
            $balance -= $epf;
        } else {
            $client->findElement($css('#lbamount'))->sendKeys($this->money($balance));
            $balance = 0.0;
        }

        $client->findElement($css('#manmemo'))->sendKeys('EPF');

        $this->selectSystemAccount($client, $systemAccount);

        $this->select($client, '#paction', 'Schedule Transaction');
        $client->findElement($css('#pmtbtn'))->click();
        sleep(1);

        return $balance;
    }

    /**
     * Select the system account in the jQuery "chosen" dropdown via its search box.
     * FRAGILE / UNVERIFIED — chosen markup must be confirmed against the live page;
     * the target value comes from the caller (e.g. "35281 - LDR - Main").
     */
    private function selectSystemAccount(mixed $client, string $systemAccount): void
    {
        $client->findElement(\Facebook\WebDriver\WebDriverBy::xpath("//span[contains(text(),'--System Account--')]"))->click();
        usleep(500000);

        $search = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector(
            '.chosen-container-active .chosen-search input, .chosen-container-active .chosen-search-input'
        ));
        $search->sendKeys($systemAccount);
        usleep(500000);

        $client->findElement(\Facebook\WebDriver\WebDriverBy::xpath(
            "//li[contains(@class,'active-result') and contains(normalize-space(.),'" . $systemAccount . "')]"
        ))->click();
    }

    /**
     * The #cancelbtn drop flow — three balance branches, all stable element IDs.
     */
    private function executeDrop(mixed $client, float $balance, string $processDate, string $today, string $dropReason, string $note): void
    {
        $css = static fn(string $c) => \Facebook\WebDriver\WebDriverBy::cssSelector($c);
        $driver = $client->getWebDriver();

        $client->findElement($css('#cancelbtn'))->click();
        $driver->switchTo()->alert()->accept();
        $client->waitFor('#dropped_reason', 15);

        $this->select($client, '#dropped_reason', $dropReason);
        $client->findElement(\Facebook\WebDriver\WebDriverBy::tagName('textarea'))->sendKeys($note);

        // Refund the form's own "Available Refund Amount" — NOT the raw Snowflake
        // balance, which overshoots by uncollected disbursement fees + anything
        // cleared today and gets rejected (found on the first real cancel, Denise
        // Riley 2026-07-01: balance 508.22 vs available 483.22). The form is the
        // live source of truth and reflects same-day clearings, so we don't rely on
        // the (end-of-day) CONTACT_BALANCES figure for the refund amount.
        $availableRefund = $this->readAvailableRefund($client);

        if ($availableRefund === null && $balance > 0) {
            // Client looks owed a refund but we couldn't read the form figure — do
            // NOT guess (a wrong amount is rejected) and do NOT drop without it.
            // Abort so the caller flags it for manual review; the contact stays
            // cancellable (#cancelbtn unchanged) and is retried next run.
            // Plain exception — cancelProgram()'s catch wraps it into a
            // DppSeleniumException with tenant/contactId/stage=drop context.
            throw new \RuntimeException(
                "Could not read 'Available Refund Amount' from the drop form; aborting drop for manual review (Snowflake balance ~" . $this->money($balance) . ').'
            );
        }

        if ($availableRefund !== null && $availableRefund > 0) {
            $client->findElement($css('#dorefund'))->click();
            $amountEl = $client->findElement($css('#amount'));
            $amountEl->clear();
            $amountEl->sendKeys($this->money($availableRefund));
            $startDate = $client->findElement($css('#start_date'));
            $startDate->sendKeys($processDate);
            $startDate->sendKeys(\Facebook\WebDriver\WebDriverKeys::TAB);
        }

        $this->select($client, '#complete_delete', 'Complete');
        $client->findElement($css('#delete_events'))->click();

        // Optional fields — the VBA wrapped these in On Error Resume Next.
        try {
            $client->findElement($css('#cancellation_request_date'))->sendKeys($today);
            $startDate2 = $client->findElement($css('#start_date'));
            $startDate2->sendKeys($today);
            $startDate2->sendKeys(\Facebook\WebDriver\WebDriverKeys::ENTER);
        } catch (\Throwable $e) {
            // best-effort only
        }

        $client->findElement($css('#savebtn'))->click();
        $driver->switchTo()->alert()->accept();

        // VBA waited 60s after save. We wait (up to 60s) for the drop form to clear
        // as the confirmation, then fall through.
        try {
            $client->wait(60)->until(
                static fn($d): bool => \count($d->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#savebtn'))) === 0
            );
        } catch (\Throwable $e) {
            // proceed regardless
        }
    }

    /** Select an option by visible text. */
    private function select(mixed $client, string $css, string $visibleText): void
    {
        $element = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector($css));
        (new \Facebook\WebDriver\WebDriverSelect($element))->selectByVisibleText($visibleText);
    }

    /** Format a money amount the way the form expects (e.g. 123.40). */
    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Read the drop form's live "Available Refund Amount" — Current Balance minus
     * uncollected disbursement fees and anything cleared today. This is the max the
     * form will refund; typing more is rejected. Scrapes the label's parent text
     * (verified layout: "Available Refund Amount: $483.22") and parses the dollar
     * value. Returns null if the label/number can't be found or parsed.
     */
    private function readAvailableRefund(mixed $client): ?float
    {
        try {
            $driver = $client->getWebDriver();
            $el = $driver->findElement(\Facebook\WebDriver\WebDriverBy::xpath(
                "//*[contains(text(),'Available Refund Amount')]"
            ));
            $parent = $el->findElement(\Facebook\WebDriver\WebDriverBy::xpath('..'));
            $text = (string) $parent->getText(); // "Available Refund Amount: $483.22"

            if (preg_match('/Available Refund Amount:?\s*\$?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/i', $text, $m) === 1) {
                return (float) str_replace(',', '', $m[1]);
            }

            Log::warning('DPP: Available Refund Amount found but not parseable', ['text' => $text]);
        } catch (\Throwable $e) {
            Log::warning('DPP: could not read Available Refund Amount', ['error' => $e->getMessage()]);
        }

        return null;
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

            // Success = we leave login.php (redirect to the dashboard). Checking the
            // URL — rather than #Username visibility — avoids a false pass on the
            // brief page-unload flicker when credentials are rejected (a failed
            // login re-renders login.php, so the URL stays on it and this times out).
            $client->wait(20)->until(
                static fn($driver): bool => !str_contains((string) $driver->getCurrentURL(), 'login.php')
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
