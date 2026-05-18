<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PmodEmailSettings
{
    /**
     * @return array{to: list<string>, cc: list<string>, bcc: list<string>, high_priority: bool}
     */
    public function for(PmodWorkItem $workItem, string $status, string $failureType = ''): array
    {
        $row = $this->findRow($workItem, $status, $failureType);

        return [
            'to' => $this->parseEmails($row['to_emails'] ?? ''),
            'cc' => $this->parseEmails($row['cc_emails'] ?? ''),
            'bcc' => $this->parseEmails($row['bcc_emails'] ?? ''),
            'high_priority' => (bool) ($row['high_priority'] ?? $status === 'failure'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findRow(PmodWorkItem $workItem, string $status, string $failureType): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('pmod_email_settings')) {
                return [];
            }

            $query = DB::table('pmod_email_settings')
                ->where('enabled', true)
                ->where('status', $status)
                ->where(function ($builder) use ($workItem): void {
                    $builder->where('action_type', $workItem->actionType->value)
                        ->orWhere('action_type', '*');
                });

            if ($failureType !== '') {
                $query->where(function ($builder) use ($failureType): void {
                    $builder->where('failure_type', $failureType)
                        ->orWhereNull('failure_type')
                        ->orWhere('failure_type', '');
                });
            }

            $row = $query
                ->orderByRaw("CASE WHEN action_type = ? THEN 0 ELSE 1 END", [$workItem->actionType->value])
                ->orderByRaw("CASE WHEN failure_type = ? THEN 0 ELSE 1 END", [$failureType])
                ->first();

            return $row === null ? [] : (array) $row;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    public function parseEmails(string $value): array
    {
        $parts = preg_split('/[,;|]+/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }
}
