<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Exact computed writer rows, frozen alongside their source workbook; never reads mutable SQL. */
final class PayrollCommissionResultSnapshot
{
    public const NSF_ALL_PRICED_AGENTS = 'all_priced_agents_v1';

    public static function component(string $reportKind, string $source, string $period, string $component, array $rows, ?string $coverageBasis = null): array
    {
        if (!in_array($reportKind, ['retention', 'nsf'], true) || !in_array($source, ['ldr', 'plaw'], true)
            || !in_array($component, $reportKind === 'nsf' ? ['commission'] : ['commission', 'bonusCommission'], true)
            || !preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])-01$/', $period)) {
            throw new \InvalidArgumentException('Invalid frozen commission component scope.');
        }
        if ($coverageBasis !== null && ($reportKind !== 'nsf' || $coverageBasis !== self::NSF_ALL_PRICED_AGENTS)) {
            throw new \InvalidArgumentException('Invalid frozen commission coverage basis.');
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
        return ['reportKind' => $reportKind, 'source' => $source, 'period' => $period, 'component' => $component, 'complete' => true, 'rows' => array_values($saved)]
            + ($coverageBasis === null ? [] : ['coverageBasis' => $coverageBasis]);
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
                $context = $artifacts[$family . '_' . $source]['context'] ?? [];
                $captured = $context['commissionResults'] ?? null;
                if (!is_array($captured) || ($captured['complete'] ?? false) !== true
                    || ($captured['reportKind'] ?? null) !== $kind || ($captured['source'] ?? null) !== $source
                    || ($captured['period'] ?? null) !== $period || ($captured['component'] ?? null) !== $component
                    || isset($captured['coverageBasis']) && !is_string($captured['coverageBasis'])
                    || !is_array($captured['rows'] ?? null) || !array_is_list($captured['rows'])) {
                    throw new \RuntimeException('Exact complete source commission components are required. Rerun the correlated CS sources.');
                }
                try { $checked = self::component($kind, $source, $period, $component, $captured['rows'], $captured['coverageBasis'] ?? null); }
                catch (\InvalidArgumentException $error) { throw new \RuntimeException('Frozen source commission values are invalid.', 0, $error); }
                foreach ($captured['rows'] as $row) {
                    if (($row['agentKey'] ?? null) !== mb_strtolower((string) ($row['agent'] ?? ''), 'UTF-8')) {
                        throw new \RuntimeException('Frozen source commission identity is invalid.');
                    }
                }
                if ($family === 'retention' && array_key_exists('regularPricing', $context)) {
                    if (!is_array($context['regularPricing'])) throw new \RuntimeException('Frozen regular Retention pricing evidence must be a valid versioned record.');
                    RetentionPricingSnapshot::validate($context['regularPricing'], $captured);
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
            // Preserve old manifests exactly; never upgrade roster-filtered NSF
            // archives merely because this reader is a newer package version.
            $coverage[] = ['reportKind' => $kind, 'source' => $source, 'period' => $period, 'complete' => true]
                + (isset($checked['coverageBasis']) ? ['coverageBasis' => $checked['coverageBasis']] : []);
        }
        return ['agentResults' => $results, 'agentResultsCoverage' => $coverage];
    }
}
