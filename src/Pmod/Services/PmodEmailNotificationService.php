<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Support\PmodEmailSettings;
use Cmd\Reports\Services\EmailSenderService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PmodEmailNotificationService
{
    public function __construct(private readonly ?PmodEmailSettings $settings = null)
    {
    }

    public function sendResult(PmodWorkItem $workItem, PmodResult $result): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $success   = in_array($result->status, ['updated', 'success'], true);
        $captured  = $result->status === 'captured_for_manual_review';

        return $this->send(
            workItem:      $workItem,
            statusLabel:   $success ? 'Success' : ($captured ? 'Captured' : 'Failure'),
            subjectPrefix: $success ? 'PMOD Success' : ($captured ? 'ACTION REQUIRED: PMOD Manual Review' : 'HIGH PRIORITY: PMOD Failure'),
            summary:       $result->message,
            details:       $result->metadata,
        );
    }

    public function sendException(PmodWorkItem $workItem, Throwable $exception): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->send(
            workItem: $workItem,
            statusLabel: 'Failure',
            subjectPrefix: 'HIGH PRIORITY: PMOD API Failure',
            summary: $exception->getMessage(),
            details: [
                'failure_type' => 'api_failure',
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function send(
        PmodWorkItem $workItem,
        string $statusLabel,
        string $subjectPrefix,
        string $summary,
        array $details,
    ): bool {
        try {
            $failureType = $this->failureType($details);
            $dbStatus = match ($statusLabel) {
                'Success'  => 'success',
                'Captured' => 'captured',
                default    => 'failure',
            };
            $settings = ($this->settings ?? new PmodEmailSettings())->for(
                workItem: $workItem,
                status: $dbStatus,
                failureType: $failureType,
            );

            $to = $settings['to'];
            $cc = $settings['cc'];
            if ($statusLabel === 'Failure' && $failureType === 'api') {
                $cc = array_values(array_unique([...$cc, ...$this->apiFailureCcFallback()]));
            }

            if ($to === []) {
                Log::warning('PMOD email notification has no configured recipients.', [
                    'action_type' => $workItem->actionType->value,
                    'status' => $statusLabel,
                    'failure_type' => $failureType,
                ]);

                return false;
            }

            $sent = (new EmailSenderService())->sendMailHtml(
                sprintf('%s - %s - Contact %s', $subjectPrefix, $this->actionLabel($workItem), $workItem->contactId),
                $this->body($workItem, $statusLabel, $summary, $details, $settings['high_priority']),
                $to,
                $cc,
                $settings['bcc'],
            );

            if (! $sent) {
                Log::warning('PMOD email notification not sent.', [
                    'action_type' => $workItem->actionType->value,
                    'contact_id' => $workItem->contactId,
                ]);
            }

            return $sent;
        } catch (Throwable $e) {
            Log::error('PMOD email notification failed.', [
                'action_type' => $workItem->actionType->value,
                'contact_id' => $workItem->contactId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function enabled(): bool
    {
        return filter_var(env('PMOD_EMAIL_NOTIFICATIONS', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return list<string>
     */
    private function apiFailureCcFallback(): array
    {
        return (new PmodEmailSettings())->parseEmails((string) env('PMOD_API_FAILURE_CC', ''));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function failureType(array $metadata): string
    {
        $type = strtolower((string) ($metadata['failure_type'] ?? ''));
        $reason = strtolower((string) ($metadata['reason'] ?? ''));

        if ($type === 'api_failure' || str_contains($reason, 'api') || str_contains($reason, 'gateway')) {
            return 'api';
        }

        if ($reason !== '') {
            return 'data';
        }

        return '';
    }

    private function actionLabel(PmodWorkItem $workItem): string
    {
        return ucwords(str_replace('_', ' ', $workItem->actionType->value));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function body(PmodWorkItem $workItem, string $statusLabel, string $summary, array $details, bool $highPriority): string
    {
        if ($statusLabel === 'Captured') {
            return $this->capturedBody($workItem, $details);
        }

        $rows = [
            'Status'          => $statusLabel,
            'Action'          => $this->actionLabel($workItem),
            'Contact ID'      => $workItem->contactId,
            'Company'         => $workItem->company->value,
            'Requested By'    => $workItem->requestedBy,
            'Summary'         => $summary,
        ];

        $html = '<p>PMOD processing result:</p><table border="1" cellspacing="0" cellpadding="5" style="border-collapse:collapse">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th align="left">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th><td>'
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $html .= '</table>';

        if ($statusLabel === 'Failure' || $highPriority) {
            $html .= '<p><strong>Priority:</strong> High</p>';
        }

        return $html;
    }

    /** @param array<string, mixed> $details */
    private function capturedBody(PmodWorkItem $workItem, array $details): string
    {
        $e        = fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $action   = $this->actionLabel($workItem);
        $date     = now()->format('m/d/Y g:i A');
        $payload  = $workItem->normalizedPayload;
        $banking  = $workItem->bankingUpdate;
        $creditor = $workItem->creditorChange;
        $sponsor  = $workItem->sponsorUpdate;
        $amount   = $workItem->amount ?? ($workItem->amounts[0] ?? null);
        $months   = (int) ($payload['months_to_extend'] ?? $payload['months_to_decrease']
                        ?? $creditor['months_to_extend'] ?? $creditor['months_to_decrease'] ?? 0);

        // Build request detail rows from what was actually submitted
        $requestRows = array_filter([
            'Action'          => $action,
            'Contact ID'      => $workItem->contactId,
            'Company'         => strtoupper($workItem->company->value),
            'Requested By'    => $workItem->requestedBy,
            'Date'            => $date,
        ]);

        // Build the specific details the agent/client submitted
        $submittedRows = match ($workItem->actionType->value) {
            'add_bank_account' => array_filter([
                'Bank Name'        => $banking['bank_name'] ?? null,
                'Account Type'     => $banking['account_type'] ?? null,
                'Routing Number'   => !empty($banking['routing_number']) ? '***' . substr($banking['routing_number'], -4) : null,
                'Account Number'   => !empty($banking['account_number']) ? '***' . substr($banking['account_number'], -4) : null,
                'Account Holder'   => $banking['account_holder_name'] ?? $banking['name_on_account'] ?? null,
            ]),
            'capture_sponsor_banking' => array_filter([
                'Sponsor Name'           => $sponsor['sponsor_name'] ?? null,
                'Sponsor Bank Name'      => $sponsor['sponsor_bank_name'] ?? null,
                'Account Type'           => $sponsor['sponsor_account_type'] ?? null,
                'Routing Number'         => !empty($sponsor['sponsor_routing_number']) ? '***' . substr($sponsor['sponsor_routing_number'], -4) : null,
                'Account Number'         => !empty($sponsor['sponsor_account_number']) ? '***' . substr($sponsor['sponsor_account_number'], -4) : null,
            ]),
            'add_creditor_and_increase_payment' => array_filter([
                'Creditor Name'       => $creditor['creditor_name'] ?? null,
                'Creditor Account #'  => $creditor['account_number'] ?? null,
                'Balance'             => !empty($creditor['balance']) ? '$' . number_format((float) $creditor['balance'], 2) : null,
                'New Monthly Payment' => $amount ? '$' . number_format((float) $amount, 2) : null,
            ]),
            'add_creditor_and_extend_program' => array_filter([
                'Creditor Name'      => $creditor['creditor_name'] ?? null,
                'Creditor Account #' => $creditor['account_number'] ?? null,
                'Balance'            => !empty($creditor['balance']) ? '$' . number_format((float) $creditor['balance'], 2) : null,
                'Monthly Payment'    => $amount ? '$' . number_format((float) $amount, 2) : null,
                'Months to Extend'   => $months > 0 ? (string) $months : null,
            ]),
            'remove_creditor_and_decrease_payment' => array_filter([
                'Creditor'            => $creditor['creditor_name'] ?? $creditor['creditor_id'] ?? null,
                'New Monthly Payment' => $amount ? '$' . number_format((float) $amount, 2) : null,
            ]),
            'remove_creditor_and_decrease_term' => array_filter([
                'Creditor'          => $creditor['creditor_name'] ?? $creditor['creditor_id'] ?? null,
                'Months to Remove'  => $months > 0 ? (string) $months : null,
            ]),
            default => [],
        };

        $buildTable = function (array $rows) use ($e): string {
            $html = '<table border="1" cellspacing="0" cellpadding="8" style="border-collapse:collapse;width:100%;margin-bottom:16px">';
            foreach ($rows as $label => $value) {
                $html .= '<tr><th align="left" style="background:#f5f5f5;width:180px;font-weight:600">'
                    . $e($label) . '</th><td>' . $e($value) . '</td></tr>';
            }
            return $html . '</table>';
        };

        $submittedSection = !empty($submittedRows)
            ? '<p style="margin:16px 0 4px"><strong>Request Details:</strong></p>' . $buildTable($submittedRows)
            : '';

        return '<p>Hi,</p>'
            . '<p>The following payment modification request needs to be completed manually in Forth CRM.</p>'
            . $buildTable($requestRows)
            . $submittedSection
            . '<p>Once complete, please mark this request as processed:<br>'
            . '<a href="http://54.67.76.23/admin/pmod-requests">http://54.67.76.23/admin/pmod-requests</a></p>';
    }
}
