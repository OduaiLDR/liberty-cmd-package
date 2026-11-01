<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use Closure;
use GuzzleHttp\Exception\ConnectException;
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
        'Unrecognized settlement approval processing outcome.',
        'Invalid approval Inbox page response.',
        'Invalid approval Inbox message response.',
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

    public function rejection(ApprovalEmailRejected $error): string
    {
        $stage = $this->stage;
        $this->event('message_rejected', [
            'stage' => $stage, 'outcome' => 'rejected', 'reason' => $error->reason,
            'fields' => $error->fields, 'recovery' => 'correct_email_data',
        ], 'warning');
        $fields = [];
        foreach ($error->fields as $field => $issue) $fields[] = $field . ':' . $issue;
        return $error->getMessage() . ' [reason=' . $error->reason
            . ($fields === [] ? '' : ' fields=' . implode(',', $fields)) . ' run_id=' . $this->id . ']';
    }

    public function failure(Throwable $error, string $event, ?string $stage = null): string
    {
        $operationStage = $this->stage;
        $message = $error->getMessage();
        $safe = in_array($message, self::SAFE_ERRORS, true)
            || preg_match('/\AForth request rejected \(HTTP [1-5][0-9]{2}\)\.\z/', $message);
        $diagnostic = $safe ? $message : 'Operation failed; raw provider/database details omitted.';
        if ($error instanceof JsonException) $diagnostic = 'Invalid JSON in the provider response or preview fixture.';
        $context = ['stage' => $stage ?? $operationStage, 'error_type' => get_class($error)];
        $httpStatus = null;
        $timeout = $connection = $database = $programming = $json = false;
        if (preg_match('/\AForth request rejected \(HTTP ([1-5][0-9]{2})\)\.\z/', $message, $matches)) {
            $httpStatus = (int) $matches[1];
        }
        for ($cause = $error, $depth = 0; $cause !== null && $depth < 8; $cause = $cause->getPrevious(), $depth++) {
            $database = $database || $cause instanceof \PDOException;
            $programming = $programming || $cause instanceof \Error;
            $json = $json || $cause instanceof JsonException;
            if ($cause instanceof ConnectException || $cause instanceof RequestException) {
                $timeout = $timeout || ($cause->getHandlerContext()['errno'] ?? null) === 28;
                $connection = $connection || $cause instanceof ConnectException;
            }
            if ($cause instanceof RequestException && $cause->hasResponse()) {
                $httpStatus ??= $cause->getResponse()->getStatusCode();
                $retryAfter = $cause->getResponse()->getHeaderLine('Retry-After');
                if (ctype_digit($retryAfter) && strlen($retryAfter) <= 5 && (int) $retryAfter <= 86400) {
                    $context['retry_after_seconds'] = (int) $retryAfter;
                }
            }
        }
        if ($httpStatus !== null) {
            $context['http_status'] = $httpStatus;
            if (!$safe) $diagnostic = 'Provider request failed (HTTP ' . $httpStatus . '); response details omitted.';
        }
        $configuration = $safe && (str_starts_with($message, 'Configure ') || str_starts_with($message, 'Microsoft Graph is not configured:')
            || str_starts_with($message, 'A unique Forth API key is required') || $message === 'Forth API key is not configured.'
            || str_starts_with($message, 'Settlement Approval recipients') || str_starts_with($message, 'Live mode requires')
            || str_starts_with($message, 'Publish and apply') || in_array($context['stage'], ['processor_initializing', 'live_configuration_check_started', 'notification_configuration_check_started'], true));
        $context['error_code'] = match (true) {
            $programming => 'programming_error',
            $database => 'database_error',
            $timeout => 'timeout',
            $connection => 'connection_failed',
            $httpStatus === 401 => 'authentication_failed',
            $httpStatus === 403 => 'access_denied',
            $httpStatus === 429 => 'rate_limited',
            $httpStatus !== null && $httpStatus >= 500 => 'provider_unavailable',
            $httpStatus !== null => 'provider_rejected',
            $json => 'invalid_json',
            $configuration => 'configuration_error',
            default => 'operation_failed',
        };
        $context['recovery'] = match (true) {
            in_array($context['stage'], ['note_create_started', 'notification_send_started'], true) => 'manual_review_before_retry',
            in_array($context['stage'], ['status_update_started', 'status_verification_started'], true) => 'reconcile_status_before_retry',
            in_array($context['stage'], ['message_mark_read_started', 'message_archive_started'], true) => 'retry_mailbox_only',
            in_array($context['error_code'], ['configuration_error', 'authentication_failed', 'access_denied'], true) => 'check_configuration',
            in_array($context['error_code'], ['timeout', 'connection_failed', 'rate_limited', 'provider_unavailable', 'database_error'], true) => 'retry_next_run',
            default => 'investigate',
        };
        $context['error'] = $diagnostic;
        $this->event($event, $context, 'error');
        // Error reporting must not replace the operation that actually failed (including cleanup errors).
        $this->stage = $operationStage;
        return $diagnostic . ' [stage=' . $context['stage'] . ' error_code=' . $context['error_code']
            . ' recovery=' . $context['recovery'] . (isset($context['http_status']) ? ' http_status=' . $context['http_status'] : '')
            . ' run_id=' . $this->id . ']';
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
