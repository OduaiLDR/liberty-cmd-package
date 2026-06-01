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
        $rows = [
            'Status' => $statusLabel,
            'Action' => $this->actionLabel($workItem),
            'Contact ID' => $workItem->contactId,
            'Company' => $workItem->company->value,
            'Tenant ID' => $workItem->tenantId,
            'Requested By' => $workItem->requestedBy,
            'Idempotency Key' => $workItem->idempotencyKey,
            'Summary' => $summary,
            'Details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $html = '<p>PMOD processing result:</p><table border="1" cellspacing="0" cellpadding="5" style="border-collapse:collapse">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th align="left">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th><td>'
                . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        $html .= '</table>';

        if ($statusLabel === 'Failure' || $highPriority) {
            $html .= '<p><strong>Priority:</strong> High</p>';
            $html .= '<p>Failure details are included above. Data/check failures should go to the configured managers for this PMOD. API failures that should have worked include the escalation CC list.</p>';
        }

        return $html;
    }
}
