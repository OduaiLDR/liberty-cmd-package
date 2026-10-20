<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use Closure;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use Throwable;

/** Operational events only: never pass bodies, provider responses, credentials or exception objects. */
final class ApprovalRunLog
{
    public readonly string $id;
    private float $started;
    private array $message = [];
    private string $stage = 'run_started';
    private bool $sinkWarningShown = false;

    // Only fixed application diagnostics are safe to repeat. Unknown/provider errors may contain PII.
    private const SAFE_ERRORS = [
        'Live mode requires the explicit enable switch and cannot be combined with --dry-run.',
        'Company must be all, ldr or plaw.',
        'Limit must be between 1 and 500.',
        'Saved files can only be used in preview mode.',
        '--offer-file requires --file.',
        'File sender does not match --company.',
        'Publish and apply the settlement-approval-email migration before enabling live processing.',
        'Inbox scan bound reached; narrow the company/message selection.',
        'Graph returned an empty approval message ID.',
        'Message routing changed during processing.',
        'Graph pagination did not advance.',
        'Selected message was not found in the matching Inbox route.',
        'Offer fixture must be an object or null.',
        'Preview file is missing, unreadable or over 2 MB.',
        'Could not read preview file.',
        'Unexpected Graph approval pagination URL.',
        'Graph Inbox response is missing messages.',
        'Approval email body is missing.',
        'Graph token response had no access_token.',
        'Microsoft Graph is not configured: set GRAPH_TENANT_ID, GRAPH_CLIENT_ID and GRAPH_CLIENT_SECRET in .env.',
        'Not a recognized settlement approval email.',
        'Conflicting settlement approval fields.',
        'Approval email requires numeric customer/settlement IDs, creditor and four account digits.',
        'Live processing dependencies are missing.',
        'Settlement status could not be verified; reconcile before repeating.',
        'Unrecognized settlement approval processing stage.',
        'Settlement/customer mismatch; no approval will be applied.',
        'Offer status is missing or invalid.',
        'Offer payment type is missing or invalid.',
        'Conflicting approval details for this settlement; manual review required.',
        'Invalid ledger update.',
        'Approval processing lease lost; no further writes are allowed.',
        'Unexpected Forth status catalog format.',
        'Invalid Forth status ID.',
        'Forth target status is missing or ambiguous; no update allowed.',
        'Configure the company note type and explicit note visibility before live processing.',
        'Invalid target status.',
        'Forth did not confirm a created note ID; reconcile before repeating.',
        'Forth API key is not configured.',
        'Forth request failed; mutation outcomes require reconciliation before retry.',
        'Invalid Forth response.',
        'Unexpected Forth operation status.',
        'Forth reported an unsuccessful operation.',
        'Forth response record is missing.',
        'Configure Settlement Approval recipients in TblReports before live processing.',
        'Settlement Approval recipients contain an invalid email address; correct TblReports before live processing.',
        'Approval notification delivery is uncertain; reconcile before resending.',
        'A unique Forth API key is required for LDR.',
        'A unique Forth API key is required for PLAW.',
    ];

    public function __construct(
        private bool $live,
        private Closure $persist,
        private Closure $console,
        private bool $verbose = false,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->started = microtime(true);
    }

    public function message(?string $id = null, ?string $company = null, ?string $settlementId = null): void
    {
        $this->message = [];
        if ($id !== null && $id !== '') $this->message['message_ref'] = substr(hash('sha256', $id), 0, 16);
        if (in_array($company, ['LDR', 'PLAW'], true)) $this->message['company'] = $company;
        if ($settlementId !== null && ctype_digit($settlementId)) $this->message['settlement_id'] = $settlementId;
    }

    public function event(string $event, array $context = [], string $level = 'info'): void
    {
        $this->stage = $event;
        $record = [
            'run_id' => $this->id, 'mode' => $this->live ? 'live' : 'preview',
            'elapsed_ms' => $this->elapsedMs(),
        ] + $this->message + $context;
        try {
            ($this->persist)($level, 'settlement_approval.' . $event, $record);
        } catch (Throwable) {
            if (!$this->sinkWarningShown) {
                $this->sinkWarningShown = true;
                $this->writeConsole('Settlement approval logging unavailable; processing continues. Use -v for console details. run_id=' . $this->id);
            }
        }
        if ($this->verbose) {
            $this->writeConsole('[settlement-approval] ' . $event . ' ' . json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    public function failure(Throwable $error, string $event): string
    {
        $message = $error->getMessage();
        $safe = in_array($message, self::SAFE_ERRORS, true)
            || preg_match('/\AForth request rejected \(HTTP [1-5][0-9]{2}\)\.\z/', $message);
        $diagnostic = $safe ? $message : 'Operation failed; raw provider/database details omitted.';
        if ($error instanceof JsonException) $diagnostic = 'Invalid JSON in the provider response or preview fixture.';
        $context = ['stage' => $this->stage, 'error_type' => get_class($error)];
        for ($cause = $error, $depth = 0; $cause !== null && $depth < 8; $cause = $cause->getPrevious(), $depth++) {
            if ($cause instanceof RequestException && $cause->hasResponse()) {
                $context['http_status'] = $cause->getResponse()->getStatusCode();
                if (!$safe) $diagnostic = 'Provider request failed (HTTP ' . $context['http_status'] . '); response details omitted.';
                break;
            }
        }
        $context['error'] = $diagnostic;
        $this->event($event, $context, 'error');
        return $diagnostic . ' [stage=' . $context['stage'] . ' run_id=' . $this->id . ']';
    }

    public function elapsedMs(): int
    {
        return (int) round((microtime(true) - $this->started) * 1000);
    }

    private function writeConsole(string $line): void
    {
        try {
            ($this->console)($line);
        } catch (Throwable) {
            // A log sink or console failure must never alter approval/recovery behavior.
        }
    }
}
