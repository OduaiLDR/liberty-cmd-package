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

        // 2) Pending settlements. Jennifer + Jacob 2026-07-21: a cancel VOIDS every
        //    active settlement (the client is refunded the escrow by the drop below — no
        //    creditor call). So when the DPP_ALLOW_SETTLEMENT_VOID switch is on, the
        //    caller passed the offer ids, and the client will NOT hit the EPF gate after
        //    voiding, we void all offers HERE (before EPF/drop, since voiding navigates
        //    away and step 4 re-confirms #cancelbtn) and continue to the drop. A clean void
        //    failure throws → the caller routes the whole contact to manual and the drop
        //    never runs. NOTE: voids commit irreversibly per offer, so a multi-offer void is
        //    NOT atomic — a mid-sequence failure throws a typed settlement_void_partial error
        //    that the caller turns into a LOUD reconcile-this alert (not a silent backlog).
        //    Switch OFF → route to manual exactly as before (today's safe default).
        $offers = (array) ($form['settlement_offers'] ?? []);
        $voidedOfferIds = [];
        if ($settlements > 0) {
            $canVoid = getenv('DPP_ALLOW_SETTLEMENT_VOID') === '1'
                && $offers !== []
                && !($balance > 0 && $epf > 0); // don't void if the EPF gate would then block the drop
            if (!$canVoid) {
                return [
                    'status' => 'manual_audit',
                    'message' => $balance >= $settlements
                        ? 'balance >= scheduled settlements; manual review required'
                        : 'pending settlements present; manual review',
                ];
            }
            $voidedOfferIds = $this->voidSettlements($client, $tenant, $contactId, $offers);
            Log::info('DPP: settlements voided, continuing to drop', ['contact_id' => $contactId, 'voided_offer_ids' => $voidedOfferIds]);
        }

        // NARROW EPF GATE (2026-07-02): the EPF-capture path below is unverified (the
        // "chosen" system-account picker + whether the form's Available Refund Amount
        // reflects the just-scheduled EPF). No backlog client reaches it — every
        // positive+EPF client has settlements and is gated above — but if one ever does,
        // route it to manual review instead of running the unproven capture unsupervised.
        // REMOVE this block (after validating one such cancel) to re-enable auto capture.
        if ($balance > 0 && $epf > 0) {
            Log::warning('DPP: EPF_UNVERIFIED — positive+EPF+no-settlement reached the capture path; routing to manual', [
                'tenant' => $tenant,
                'contact_id' => $contactId,
                'balance' => $balance,
                'epf' => $epf,
            ]);

            return [
                'status' => 'manual_audit',
                'message' => 'EPF_UNVERIFIED: positive balance + EPF, no settlement — capture path not yet validated; needs a supervised cancel',
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
            $formCleared = $this->executeDrop($client, $balance, $processDate, $today, $dropReason, $note);
        } catch (\Throwable $e) {
            throw new DppSeleniumException(
                'cancel drop failed: ' . $e->getMessage(),
                tenant: $tenant,
                contactId: $contactId,
                stage: 'drop',
                previous: $e,
            );
        }

        // VERIFY the drop actually landed. executeDrop can run to completion without a
        // Selenium error yet leave the client un-dropped (Returned Payments Hold / refund).
        // Two signals, biased so a false "failed" only routes to a manual cancel:
        //   1) PRIMARY — the save form CLEARED (#savebtn gone) = the server accepted the drop;
        //   2) GUARD — after a reload #cancelbtn is no longer "Cancel Program". A dropped
        //      contact loses #cancelbtn entirely (probe-cancel 2026-07-14: "not found"), so
        //      absence is fine; only a definitive "Cancel Program" here can DOWNGRADE to
        //      failed. It can NEVER false-fail a real drop (whose button is gone).
        $stillCancellable = false;
        try {
            $client->request('GET', $toolsUrl);
            $client->waitFor('#cancelbtn', 10);
            $stillCancellable = trim($client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#cancelbtn'))->getText()) === 'Cancel Program';
        } catch (\Throwable $e) {
            // #cancelbtn absent/unreadable — consistent with a dropped contact; leave false.
        }

        Log::info('DPP: drop verification', [
            'contact_id' => $contactId,
            'form_cleared' => $formCleared,
            'still_cancellable' => $stillCancellable,
            'balance_branch' => $branch,
        ]);

        // Not dropped if the form never cleared, or the contact is still cancellable —
        // return failed so the caller never sets the "System Cancel" status (which fires
        // the client's Termination Notice on a drop that never happened). Root cause of
        // the repeat-cancel + duplicate-notice bug found 2026-07-14.
        if (!$formCleared || $stillCancellable) {
            Log::warning('DPP: drop did NOT land', [
                'tenant' => $tenant,
                'contact_id' => $contactId,
                'form_cleared' => $formCleared,
                'still_cancellable' => $stillCancellable,
                'balance_branch' => $branch,
            ]);

            return [
                'status' => 'failed',
                'message' => 'drop did not take effect (still cancellable after save) — likely refund rejection or a Returned Payments Hold',
                'balance_branch' => $branch,
            ];
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
     * READ-ONLY probe for the settlement-void screen (2026-07-21). Dumps how a contact's
     * settlements are laid out — any "settlement" tab links, the settlements table rows
     * (#tablelist per the VBA: id / creditor / amount / status cells), all <select> ids
     * (to locate #sett_void_reasons) and its options if present. Clicks NOTHING that
     * could void — no edit / #voidbtn / confirm. Used to confirm the real selectors +
     * settlement-id semantics BEFORE the destructive voidSettlements() is written.
     *
     * @return array<string, mixed>
     */
    public function probeVoidSettlements(string $tenant, string $contactId): array
    {
        $tenant = strtoupper(trim($tenant));
        $contactId = trim($contactId);

        Log::info('DPP: probeVoidSettlements (read-only) requested', ['tenant' => $tenant, 'contact_id' => $contactId]);

        $this->ensureLogin($tenant);
        $client = $this->client();
        $driver = $client->getWebDriver();
        $viewUrl = self::DPP_BASE_URL . '/index.php?module=contacts&page=view2&cid=' . rawurlencode($contactId);

        $report = ['login' => 'ok', 'contact_id' => $contactId, 'committed' => false];

        try {
            $client->request('GET', $viewUrl);
            usleep(1500000); // let the contact page settle
            $report['url'] = $driver->getCurrentURL();
        } catch (\Throwable $e) {
            $report['error'] = 'could not load contact page: ' . $e->getMessage();

            return $report;
        }

        // Links whose text/href mentions "settlement" — to find the real settlements tab.
        $report['settlement_links'] = [];
        try {
            foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::tagName('a')) as $a) {
                $txt = trim((string) $a->getText());
                $href = (string) ($a->getAttribute('href') ?? '');
                if (stripos($txt, 'settlement') !== false || stripos($href, 'settlement') !== false) {
                    $report['settlement_links'][] = ['text' => $txt, 'href' => $href, 'id' => (string) ($a->getAttribute('id') ?? '')];
                }
            }
        } catch (\Throwable $e) {
            $report['settlement_links_error'] = $e->getMessage();
        }

        // All <select> ids (to spot #sett_void_reasons) + its options if already present.
        $report['select_ids'] = [];
        try {
            foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::tagName('select')) as $s) {
                $id = (string) ($s->getAttribute('id') ?? '');
                if ($id !== '') {
                    $report['select_ids'][] = $id;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $report['sett_void_reasons_options'] = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#sett_void_reasons'))) > 0
            ? $this->optionTexts($driver, '#sett_void_reasons')
            : 'not present here (the reason dropdown likely only appears inside the void dialog)';

        // Drill into the FIRST per-settlement dispatch page (module=settlements&page=dispatch
        // &sid=<offer_id>) to reveal the actual void controls. Read-only — no clicks on
        // void/edit/confirm, so nothing is committed.
        $sidHref = '';
        foreach ($report['settlement_links'] as $lnk) {
            if (stripos((string) ($lnk['href'] ?? ''), 'sid=') !== false) {
                $sidHref = (string) $lnk['href'];
                break;
            }
        }
        $report['settlement_page'] = $sidHref !== ''
            ? $this->probeSettlementPage($client, $driver, $sidHref)
            : 'no per-settlement (sid=) dispatch link found on the contact page';

        // Back to the contact page — nothing clicked into edit/void/confirm, nothing committed.
        $client->request('GET', $viewUrl);
        $report['result'] = 'read-only DOM dump; no edit / #voidbtn / confirm clicked — nothing committed';

        return $report;
    }

    /**
     * Dump the first ~25 rows of a table as arrays of cell text — read-only, for probes.
     *
     * @return list<list<string>>|string
     */
    private function dumpTableRows(mixed $driver, string $css): array|string
    {
        try {
            $table = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector($css));
            $rows = [];
            foreach ($table->findElements(\Facebook\WebDriver\WebDriverBy::tagName('tr')) as $i => $tr) {
                if ($i > 25) {
                    break;
                }
                $cells = [];
                foreach ($tr->findElements(\Facebook\WebDriver\WebDriverBy::tagName('td')) as $td) {
                    $cells[] = trim((string) preg_replace('/\s+/', ' ', $td->getText()));
                }
                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }

            return $rows;
        } catch (\Throwable $e) {
            return 'dump failed: ' . $e->getMessage();
        }
    }

    /**
     * READ-ONLY dump of a single settlement's dispatch page (module=settlements&page=
     * dispatch&sid=<offer_id>) — the page where the void happens. Reports the void/edit
     * controls, all <select> ids, whether #voidbtn / #editbtn / #sett_void_reasons are
     * present (+ the reason options if statically rendered), and the first table. Clicks
     * NOTHING that could void. Used to design voidSettlements() against the real UI.
     *
     * @return array<string, mixed>
     */
    private function probeSettlementPage(mixed $client, mixed $driver, string $href): array
    {
        // Panther needs an ABSOLUTE url; the settlement links are relative ("/index.php?…").
        $absUrl = str_starts_with($href, 'http') ? $href : self::DPP_BASE_URL . '/' . ltrim($href, '/');
        $out = ['href' => $href, 'abs_url' => $absUrl, 'committed' => false];
        try {
            $client->request('GET', $absUrl);
            usleep(1500000);
            $out['url'] = $driver->getCurrentURL();
        } catch (\Throwable $e) {
            $out['error'] = 'could not load: ' . $e->getMessage();

            return $out;
        }

        // Anchors/buttons mentioning void or edit (with id/href) — the void trigger.
        $out['void_edit_controls'] = [];
        try {
            foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('a, button')) as $el) {
                $t = trim((string) $el->getText());
                $id = (string) ($el->getAttribute('id') ?? '');
                $href2 = (string) ($el->getAttribute('href') ?? '');
                if (stripos($t . ' ' . $id . ' ' . $href2, 'void') !== false || stripos($t . ' ' . $id, 'edit') !== false) {
                    $out['void_edit_controls'][] = ['tag' => $el->getTagName(), 'text' => $t, 'id' => $id, 'href' => $href2];
                }
            }
        } catch (\Throwable $e) {
            $out['controls_error'] = $e->getMessage();
        }

        // Select ids + the void-reason options if the dropdown is already in the DOM.
        $out['select_ids'] = [];
        try {
            foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::tagName('select')) as $s) {
                $id = (string) ($s->getAttribute('id') ?? '');
                if ($id !== '') {
                    $out['select_ids'][] = $id;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $out['voidbtn_present'] = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#voidbtn'))) > 0;
        $out['editbtn_present'] = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#editbtn'))) > 0;
        $out['sett_void_reasons_options'] = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#sett_void_reasons'))) > 0
            ? $this->optionTexts($driver, '#sett_void_reasons')
            : 'not statically present (likely appears only after #voidbtn opens the dialog)';

        // First table on the page (settlement detail / status).
        $out['first_table'] = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('table'))) > 0
            ? $this->dumpTableRows($driver, 'table')
            : 'no table on the settlement page';

        // Open the void dialog to read the reason options + confirm button — click
        // #editbtn (accept any JS confirm the VBA accepted), then #voidbtn, dump, and
        // navigate AWAY without ever clicking a confirm/void. Mirrors probeCancel: opens
        // the destructive dialog, reads it, commits nothing.
        if ($out['editbtn_present']) {
            try {
                $this->safeClick($client, '#editbtn');
                try {
                    $driver->switchTo()->alert()->accept();
                } catch (\Throwable $e) {
                    // no alert on #editbtn
                }
                usleep(1200000);
                $voidBtn = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#voidbtn'))) > 0;
                $out['after_editbtn'] = ['voidbtn_present' => $voidBtn, 'url' => $driver->getCurrentURL()];

                if ($voidBtn) {
                    $this->safeClick($client, '#voidbtn');
                    try {
                        $driver->switchTo()->alert()->accept(); // #voidbtn may raise its own JS confirm
                    } catch (\Throwable $e) {
                        // no alert on #voidbtn
                    }
                    usleep(3500000); // the reason dropdown + confirm load async after #voidbtn
                    $hasReasons = \count($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#sett_void_reasons'))) > 0;
                    $out['void_dialog'] = [
                        'url' => $driver->getCurrentURL(),
                        'sett_void_reasons_present' => $hasReasons,
                        'sett_void_reasons_options' => $hasReasons ? $this->optionTexts($driver, '#sett_void_reasons') : 'not present',
                        'displayed_buttons' => $this->dumpDialogButtons($driver),
                    ];
                }
            } catch (\Throwable $e) {
                $out['void_dialog_error'] = $e->getMessage();
            }

            // Discard: reload the settlement page — no confirm clicked, nothing voided.
            try {
                $client->request('GET', $absUrl);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $out;
    }

    /**
     * Dump visible action buttons (button/a/span/input with ok/yes/confirm/void/save/
     * cancel/close text) — read-only, to locate the void dialog's confirm button for the
     * probe (the VBA used a fragile //body/div[12] index that has drifted).
     *
     * @return list<array{tag:string,text:string,id:string}>
     */
    private function dumpDialogButtons(mixed $driver): array
    {
        $btns = [];
        try {
            foreach ($driver->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('button, a, span[role=button], input[type=button], input[type=submit]')) as $el) {
                try {
                    if (!$el->isDisplayed()) {
                        continue;
                    }
                    $t = trim((string) $el->getText());
                    $id = (string) ($el->getAttribute('id') ?? '');
                    if ($t === '' && $id === '') {
                        continue;
                    }
                    $btns[] = ['tag' => (string) $el->getTagName(), 'id' => $id, 'text' => strlen($t) > 40 ? substr($t, 0, 40) : $t];
                    if (\count($btns) >= 30) {
                        break;
                    }
                } catch (\Throwable $e) {
                    // stale element; skip
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $btns;
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
    private function executeDrop(mixed $client, float $balance, string $processDate, string $today, string $dropReason, string $note): bool
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

        $didRefund = false;
        if ($availableRefund !== null && $availableRefund > 0) {
            $didRefund = true;
            $client->findElement($css('#dorefund'))->click();
            $amountEl = $client->findElement($css('#amount'));
            $amountEl->clear();
            $amountEl->sendKeys($this->money($availableRefund));
            $startDate = $client->findElement($css('#start_date'));
            // #start_date is a READONLY pick-only datepicker (confirmed 2026-07-21:
            // readonly=true, empty by default) → sendKeys silently no-ops and it stays
            // blank, leaving the refund with no process date. Strip the readonly
            // attribute so we can type; the field's own input mask then formats the
            // value (mirrors #cancellation_request_date, which stores YYYYMMDD).
            $driver->executeScript("arguments[0].removeAttribute('readonly');", [$startDate]);
            $startDate->sendKeys($processDate);
            $startDate->sendKeys(\Facebook\WebDriver\WebDriverKeys::TAB);
        }

        $this->select($client, '#complete_delete', 'Complete');
        // Footer-safe click: on a refund the form has expanded and this checkbox slides
        // under DPP's sticky "System Status" footer → "element click intercepted" (the
        // live held-client failure, 2026-07-21). safeClick centers it first.
        $this->safeClick($client, '#delete_events');

        // Optional fields — the VBA wrapped these in On Error Resume Next.
        try {
            $client->findElement($css('#cancellation_request_date'))->sendKeys($today);
            // Only the NON-refund path fills #start_date here (to $today). On a refund
            // the branch above already set it to $processDate, and sendKeys APPENDS
            // (never replaces) → re-writing garbles the date and the save is rejected.
            // Guarding by !$didRefund keeps the negative-balance path byte-for-byte
            // identical while fixing held/refund clients (verified 2026-07-21).
            if (!$didRefund) {
                $startDate2 = $client->findElement($css('#start_date'));
                $startDate2->sendKeys($today);
                $startDate2->sendKeys(\Facebook\WebDriver\WebDriverKeys::ENTER);
            }
        } catch (\Throwable $e) {
            // best-effort only
        }

        $this->safeClick($client, '#savebtn');

        // Accept the mandatory cancel-enrollment confirm, then drain up to 2 bounded,
        // LOGGED follow-up confirms (the refund path can raise a second refund confirm
        // that a single accept() would miss, leaving the form open — the historical
        // "drop did not take effect"). Always accept, never dismiss ("No" aborts the
        // drop). If a stray alert doesn't complete the drop, the 60s #savebtn-gone
        // check below still fails safe to "failed" → manual review, never a phantom
        // success (2026-07-21, workflow-verified).
        $driver->switchTo()->alert()->accept();
        for ($i = 0; $i < 2; $i++) {
            try {
                $client->wait(4)->until(\Facebook\WebDriver\WebDriverExpectedCondition::alertIsPresent());
                $alert = $driver->switchTo()->alert();
                Log::info('DPP: drop - follow-up confirm accepted', ['text' => (string) $alert->getText()]);
                $alert->accept();
            } catch (\Throwable $e) {
                break; // no further alert → done
            }
        }

        // A successful drop CLEARS the form (#savebtn disappears); a drop the server
        // rejects (Returned Payments Hold / refund) leaves the form open. Return whether
        // it cleared — this is the reliable success signal. Re-reading #cancelbtn after a
        // reload is racy: a dropped contact loses #cancelbtn entirely (verified via
        // probe-cancel 2026-07-14: "not found on tools page"), so "absent" can't be told
        // apart from "slow to load".
        try {
            $client->wait(60)->until(
                static fn($d): bool => \count($d->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('#savebtn'))) === 0
            );

            return true;
        } catch (\Throwable $e) {
            return false; // form still open after 60s → the server did not accept the drop
        }
    }

    /**
     * Click an element robustly on a page with a fixed/sticky footer (DPP's "System
     * Status" bar). WebDriver only auto-scrolls an element to the viewport EDGE, where
     * the footer paints over it → "element click intercepted" (seen 2026-07-21 on
     * #delete_events once the refund branch expanded the form). scrollIntoView with
     * block:center puts the target mid-viewport, clear of the footer; then a SINGLE
     * NATIVE click — native (not JS) so confirm() dialogs still surface to WebDriver
     * and checkboxes toggle exactly once (no JS fallback → no double-toggle, no
     * auto-dismissed confirm).
     */
    private function safeClick(mixed $client, string $css): void
    {
        $driver = $client->getWebDriver();
        $el = $client->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector($css));
        $driver->executeScript('arguments[0].scrollIntoView({block: "center", inline: "center"});', [$el]);
        usleep(150000); // let the sticky layout settle after the scroll
        $el->click();
    }

    /**
     * Void ALL of a contact's pending settlement offers, then let the caller drop the
     * client (Jennifer + Jacob 2026-07-21: a cancel voids every active settlement; the
     * escrow is refunded by the drop, no creditor call). DESTRUCTIVE — fires only from
     * the settlement gate when DPP_ALLOW_SETTLEMENT_VOID=1. Per offer, drives the exact
     * UI confirmed via --probe-void-settlements + a live walkthrough (2026-07-22):
     *   settlements&page=dispatch&sid={offer_id} → #editbtn (accept the JS "OK" confirm)
     *   → #voidbtn (the void icon) → select "CLIENT CANCELLING" in #sett_void_reasons →
     *   the modal "Ok".
     * STOPS ON FIRST FAILURE — but a void commits IRREVERSIBLY the instant its modal "Ok"
     * lands, so this is NOT atomic across multiple offers: if a later offer fails, earlier
     * ones are already voided. A clean (nothing-voided-yet) failure rethrows so the caller
     * routes to manual and the drop never runs; a PARTIAL failure rethrows a typed
     * settlement_void_partial DppSeleniumException carrying the already-voided ids so the
     * caller alerts a human to reconcile. Each void is VERIFIED (the modal must close) before
     * it is recorded, so a non-committing "Ok" can never be reported as a successful void.
     *
     * @param list<array{offer_id:string}> $offers
     * @return list<string> voided offer ids
     */
    private function voidSettlements(mixed $client, string $tenant, string $contactId, array $offers): array
    {
        $driver = $client->getWebDriver();
        $voided = [];

        // Validate ALL offer ids BEFORE any irreversible void — a blank id is malformed input.
        // Failing up front means a bad list can never leave a client half-voided, nor silently
        // return [] and let the caller drop a client whose settlements were never voided.
        foreach ($offers as $offer) {
            if ((string) ($offer['offer_id'] ?? '') === '') {
                throw new \RuntimeException("voidSettlements: blank offer_id for contact {$contactId}");
            }
        }

        foreach ($offers as $offer) {
            $offerId = (string) ($offer['offer_id'] ?? '');

            try {
                $this->voidOneSettlement($client, $driver, $contactId, $offerId);
            } catch (\Throwable $e) {
                if ($voided !== []) {
                    // At least one void already committed — this contact can no longer be made
                    // whole by bailing. Surface which offers are gone LOUDLY and rethrow a typed
                    // error so the caller alerts a human + routes to manual (not silent backlog).
                    Log::error('DPP: PARTIAL SETTLEMENT VOID — reconcile manually', [
                        'tenant' => $tenant,
                        'contact_id' => $contactId,
                        'voided_offer_ids' => $voided,
                        'failed_offer_id' => $offerId,
                        'error' => $e->getMessage(),
                    ]);

                    throw new DppSeleniumException(
                        'PARTIAL settlement void: already voided [' . implode(',', $voided) . '] then failed on ' . $offerId . ' — ' . $e->getMessage(),
                        tenant: $tenant,
                        contactId: $contactId,
                        stage: 'settlement_void_partial',
                        previous: $e,
                    );
                }

                // Nothing voided yet — a clean failure. Rethrow so the caller routes the whole
                // contact to manual/backlog and the drop never runs.
                throw $e;
            }

            $voided[] = $offerId;
        }

        // The gate only calls us when offers were passed (and a blank id throws above), so a
        // clean loop always voids ≥1. Belt-and-suspenders: if we somehow voided NONE, never let
        // the caller drop a client whose live settlements are untouched.
        if ($voided === []) {
            throw new \RuntimeException("voidSettlements: no offers voided for contact {$contactId}");
        }

        return $voided;
    }

    /**
     * Void ONE settlement offer and VERIFY it committed before returning. Drives the confirmed
     * UI: dispatch&sid → #editbtn (accept the JS confirm) → #voidbtn → select "CLIENT
     * CANCELLING" in #sett_void_reasons → the modal's own "Ok" (scoped to the modal, never the
     * page's "Save Offer"). Throws on any missing selector, an unpopulated reason dropdown, or
     * a void that did not commit — the caller treats a throw as a failed void.
     */
    private function voidOneSettlement(mixed $client, mixed $driver, string $contactId, string $offerId): void
    {
        Log::info('DPP: void settlement - start', ['contact_id' => $contactId, 'offer_id' => $offerId]);

        // Open the settlement by its offer id (confirmed: sid == SETTLEMENT_OFFERS.ID). An
        // already-voided offer no longer renders #editbtn/#voidbtn, so the waitFor times out
        // and throws — a safe no-op (we never re-void).
        $client->request('GET', self::DPP_BASE_URL . '/index.php?module=settlements&page=dispatch&sid=' . rawurlencode($offerId));

        // "edit this settlement" → its JS confirm → the edit form.
        $client->waitFor('#editbtn', 20);
        $this->safeClick($client, '#editbtn');
        $this->acceptAlertIfPresent($driver);

        // The void icon → the "Are you sure you want to void this offer?" modal. #voidbtn may
        // raise its own native confirm, so accept one if it appears.
        $client->waitFor('#voidbtn', 20);
        $this->safeClick($client, '#voidbtn');
        $this->acceptAlertIfPresent($driver);

        // Wait for the required "Settlement Voided Reason" to actually POPULATE (its options
        // load async; a fixed sleep was flaky), then select CLIENT CANCELLING (confirmed
        // 2026-07-22). A throw here means the dialog never rendered → failed void.
        $this->waitForOption($driver, '#sett_void_reasons', 'CLIENT CANCELLING', 12);
        $this->select($client, '#sett_void_reasons', 'CLIENT CANCELLING');

        // Confirm with the modal's OWN "Ok" — scoped to the dialog that hosts the reason
        // select, so a stray page "Ok" / "Save Offer" can never be clicked instead.
        $this->clickModalOk($driver);

        // The "Ok" may itself raise a native confirm — accept it (mirrors #editbtn/#voidbtn) so
        // no alert is left open to block the commit OR to be mis-read as success by the verify
        // below.
        $this->acceptAlertIfPresent($driver);

        // VERIFY the void committed: a successful void closes the dialog (the reason select is
        // gone / the page navigates); a validation error, CSRF/session rejection, or a mis-click
        // leaves it open. If it is still open — or the state can't be read — the void did NOT
        // verifiably land, so we throw and the caller never records a phantom void or drops this
        // client.
        $this->assertVoidDialogClosed($driver, $offerId);

        Log::info('DPP: void settlement - committed', ['contact_id' => $contactId, 'offer_id' => $offerId]);
    }

    /** Accept a native JS alert/confirm if one is open right now; no-op otherwise. */
    private function acceptAlertIfPresent(mixed $driver): void
    {
        try {
            $driver->switchTo()->alert()->accept();
        } catch (\Throwable $e) {
            // no alert open
        }
    }

    /**
     * Poll up to $timeoutSec for a <select> to contain an option with the given visible text
     * (DPP loads the void-reason options asynchronously). Throws if it never appears.
     */
    private function waitForOption(mixed $driver, string $css, string $optionText, int $timeoutSec): void
    {
        $deadline = time() + max(1, $timeoutSec);
        do {
            foreach ($this->optionTexts($driver, $css) as $txt) {
                if (strcasecmp(trim((string) $txt), $optionText) === 0) {
                    return;
                }
            }
            usleep(300000);
        } while (time() < $deadline);

        throw new \RuntimeException("option '{$optionText}' not present in {$css} within {$timeoutSec}s");
    }

    /**
     * Click the void dialog's OWN "Ok", scoped to the dialog element that hosts
     * #sett_void_reasons — so a page-level "Ok" / "Save Offer" outside the dialog can never be
     * clicked by mistake. Matches button/anchor/span text and input[value]. Throws if the
     * dialog or its Ok button isn't found.
     */
    private function clickModalOk(mixed $driver): void
    {
        // The dialog = the NEAREST ancestor of the reason select that also contains an "Ok".
        $modal = $driver->findElement(\Facebook\WebDriver\WebDriverBy::xpath(
            "//*[@id='sett_void_reasons']/ancestor::*[.//button[normalize-space(.)='Ok'] or .//a[normalize-space(.)='Ok'] or .//span[normalize-space(.)='Ok'] or .//input[@value='Ok']][1]"
        ));

        // Selector set kept in SYNC with the detection xpath above (button/a/span/input) so any
        // element that made the xpath match the dialog is also reachable here — an Ok rendered
        // as a plain <span> or <input value="Ok"> is clicked, not skipped into a spurious failure.
        foreach ($modal->findElements(\Facebook\WebDriver\WebDriverBy::cssSelector('button, a, span, input')) as $el) {
            try {
                if (!$el->isDisplayed()) {
                    continue;
                }
                $label = trim((string) $el->getText());
                if ($label === '') {
                    $label = trim((string) ($el->getAttribute('value') ?? ''));
                }
                if (strcasecmp($label, 'Ok') === 0) {
                    $driver->executeScript('arguments[0].scrollIntoView({block:"center"});', [$el]);
                    usleep(150000);
                    $el->click();

                    return;
                }
            } catch (\Throwable $e) {
                // stale element; keep looking
            }
        }

        throw new \RuntimeException('void dialog "Ok" button not found in the reason dialog');
    }

    /**
     * Assert the void dialog closed after "Ok" — the signal that the server committed the void.
     * A validation error / rejection / mis-click leaves #sett_void_reasons still displayed; in
     * that case throw so the caller never treats the offer as voided.
     */
    private function assertVoidDialogClosed(mixed $driver, string $offerId): void
    {
        $deadline = time() + 8;
        do {
            try {
                $displayed = $driver->findElement(\Facebook\WebDriver\WebDriverBy::cssSelector('#sett_void_reasons'))->isDisplayed();
            } catch (\Facebook\WebDriver\Exception\NoSuchElementException $e) {
                return; // reason select GONE from the DOM → dialog closed / page navigated → committed
            }
            // Present-but-hidden also means the dialog closed. FAIL-CLOSED on everything else:
            // an open native alert (UnexpectedAlertOpenException), a stale/session/transient
            // WebDriver error, or a login redirect must NOT be read as success — those throwables
            // propagate so an unverifiable void is treated as FAILED, never a phantom commit.
            if (!$displayed) {
                return;
            }
            usleep(300000);
        } while (time() < $deadline);

        throw new \RuntimeException("void not confirmed for offer {$offerId}: reason dialog still open after Ok");
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
