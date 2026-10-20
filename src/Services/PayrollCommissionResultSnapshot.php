<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Exact computed writer rows, frozen alongside their source workbook; never reads mutable SQL. */
final class PayrollCommissionResultSnapshot
{
    public static function component(string $reportKind, string $source, string $period, string $component, array $rows): array
    {
        if (!in_array($reportKind, ['retention', 'nsf'], true) || !in_array($source, ['ldr', 'plaw'], true)
            || !in_array($component, $reportKind === 'nsf' ? ['commission'] : ['commission', 'bonusCommission'], true)
            || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])-01$/', $period)) {
            throw new \InvalidArgumentException('Invalid frozen commission component scope.');
        }
        $saved = [];
        foreach ($rows as $row) {
            $agent = preg_replace('/\s+/u', ' ', trim((string) ($row['agent'] ?? ''))) ?? '';
            $key = mb_strtolower($agent, 'UTF-8');
            if ($key === '' || isset($saved[$key]) || !isset($row['amount']) || !is_numeric($row['amount']) || !is_finite((float) $row['amount'])) {
                throw new \InvalidArgumentException('Frozen commission rows require unique employee names and finite computed amounts.');
            }
            $saved[$key] = ['agent' => $agent, 'agentKey' => $key, 'amount' => round((float) $row['amount'], 2)];
        }
        ksort($saved, SORT_STRING);
        return ['reportKind' => $reportKind, 'source' => $source, 'period' => $period, 'component' => $component, 'complete' => true, 'rows' => array_values($saved)];
    }

    /** Merge only captured full components. Missing employees remain missing; explicit zero rows survive. */
    public static function merge(array $artifacts, string $period): array
    {
        $results = [];
        $coverage = [];
        foreach (['retention', 'nsf'] as $kind) foreach (['ldr', 'plaw'] as $source) {
            $agents = [];
            $families = $kind === 'retention' ? ['retention' => 'commission', 'lookback' => 'bonusCommission'] : ['nsf' => 'commission'];
            foreach ($families as $family => $component) {
                $captured = $artifacts[$family . '_' . $source]['context']['commissionResults'] ?? null;
                if (!is_array($captured) || ($captured['complete'] ?? false) !== true
                    || ($captured['reportKind'] ?? null) !== $kind || ($captured['source'] ?? null) !== $source
                    || ($captured['period'] ?? null) !== $period || ($captured['component'] ?? null) !== $component
                    || !is_array($captured['rows'] ?? null) || !array_is_list($captured['rows'])) {
                    throw new \RuntimeException('Exact complete source commission components are required. Rerun the correlated CS sources.');
                }
                try { $checked = self::component($kind, $source, $period, $component, $captured['rows']); }
                catch (\InvalidArgumentException $error) { throw new \RuntimeException('Frozen source commission values are invalid.', 0, $error); }
                foreach ($captured['rows'] as $row) {
                    if (($row['agentKey'] ?? null) !== mb_strtolower((string) ($row['agent'] ?? ''), 'UTF-8')) {
                        throw new \RuntimeException('Frozen source commission identity is invalid.');
                    }
                }
                foreach ($checked['rows'] as $row) {
                    $key = $row['agentKey'];
                    $agents[$key] ??= ['reportKind' => $kind, 'source' => $source, 'period' => $period, 'agent' => $row['agent'], 'agentKey' => $key,
                        'commission' => 0.0, 'bonusCommission' => 0.0];
                    $agents[$key][$component] = $row['amount'];
                }
            }
            ksort($agents, SORT_STRING);
            array_push($results, ...array_values($agents));
            $coverage[] = ['reportKind' => $kind, 'source' => $source, 'period' => $period, 'complete' => true];
        }
        return ['agentResults' => $results, 'agentResultsCoverage' => $coverage];
    }
}
