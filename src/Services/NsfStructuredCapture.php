<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Structured NSF evidence for comparison with the workbook produced by the same calculation. */
final class NsfStructuredCapture
{
    public static function fromCalculation(
        string $runId,
        array $readiness,
        array $dataRows,
        array $pricedRows,
        array $unassigned,
        callable $isOnRoster,
        bool $managedRoster,
    ): array {
        return [
            'version' => 1,
            'source' => $readiness['source'],
            'period' => $readiness['period'],
            'runId' => $runId,
            'readiness' => $readiness,
            'rosterBasis' => $managedRoster ? 'managed' : 'fallback',
            'contacts' => array_map(static fn (array $row): array => [
                'contactId' => (string) $row['ID'],
                'agent' => self::sourceText($row['AGENT']),
                'returnedDate' => self::sourceText($row['NSF_RETURNED_DATE']),
                'action' => self::sourceText($row['NSF_ACTION']),
                'recoupDate' => self::sourceText($row['NSF_RECOUP_DATE']),
                'clearedDate' => self::sourceText($row['CLEARED_DATE']),
                'eligible' => $row['valid_commission'],
            ], $dataRows),
            'agents' => array_map(static fn (array $row): array => [
                'agent' => $row['agent'],
                'assignments' => $row['assignments'],
                'actions' => $row['actions'],
                'ratio' => $row['ratio'],
                'actionsTier' => $row['actions_tier'],
                'clearedTier' => $row['cleared_tier'],
                'rateCents' => (int) round($row['rate'] * 100),
                'clears' => $row['clears'],
                'commissionCents' => (int) round($row['commission'] * 100),
                'location' => $row['location'],
                'company' => $row['company'],
                'onRoster' => $isOnRoster($row['agent']),
            ], $pricedRows),
            'unassigned' => array_map(static fn (array $row): array => [
                'agent' => $row['agent'],
                'commissionCents' => (int) round($row['amount'] * 100),
            ], $unassigned),
        ];
    }

    private static function sourceText(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
