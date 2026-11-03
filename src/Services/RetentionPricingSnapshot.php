<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** The actual selected rates, frozen in worksheet order; never queries or recalculates a tier. */
final class RetentionPricingSnapshot
{
    private const FIELDS = ['detailRow', 'clientId', 'agent', 'agentKey', 'retentionDate', 'paymentDate',
        'retainedMonth', 'currentTier', 'appliedTier', 'rates', 'amount'];

    public static function capture(string $source, string $period, bool $retainedMonthPolicy, array $lines, array $commissionResults): array
    {
        if (!array_is_list($lines)) self::invalid('rows must be a list in worksheet order');
        $rows = [];
        foreach ($lines as $line) {
            if (!is_array($line)) self::invalid('each selected line must be an object');
            $keys = self::FIELDS;
            if (!array_key_exists('agentKey', $line)) $keys = array_values(array_diff($keys, ['agentKey']));
            self::fields($line, $keys);
            if (!is_string($line['agent'] ?? null)) self::invalid('agent must be text');
            $agent = self::agent($line['agent']);
            $key = mb_strtolower($agent, 'UTF-8');
            if (array_key_exists('agentKey', $line) && $line['agentKey'] !== $key) self::invalid('supplied agent key does not match the normalized agent');
            $line['agent'] = $agent;
            $line['agentKey'] = $key;
            $rows[] = array_replace(array_fill_keys(self::FIELDS, null), $line);
        }
        return self::validate(['version' => 1, 'source' => $source, 'period' => $period,
            'policy' => $retainedMonthPolicy ? 'retained_month' : 'report_month_override', 'rows' => $rows], $commissionResults);
    }

    public static function validate(array $snapshot, array $commissionResults): array
    {
        self::fields($snapshot, ['version', 'source', 'period', 'policy', 'rows']);
        if (($snapshot['version'] ?? null) !== 1 || !in_array($snapshot['source'] ?? null, ['ldr', 'plaw'], true)
            || !is_string($snapshot['period'] ?? null) || !self::date($snapshot['period']) || substr($snapshot['period'], 8) !== '01'
            || !in_array($snapshot['policy'] ?? null, ['retained_month', 'report_month_override'], true)
            || !is_array($snapshot['rows'] ?? null) || !array_is_list($snapshot['rows'])) self::invalid('scope, policy or version is unsupported');
        $results = self::results($commissionResults, $snapshot['source'], $snapshot['period']);
        $previous = 1;
        $sums = [];
        foreach ($snapshot['rows'] as $row) {
            if (!is_array($row)) self::invalid('each selected line must be an object');
            self::fields($row, self::FIELDS);
            if (!is_int($row['detailRow']) || $row['detailRow'] <= $previous) self::invalid('detail rows must be unique ascending worksheet rows starting at 2');
            $previous = $row['detailRow'];
            if (!is_string($row['clientId']) || trim($row['clientId']) === ''
                || !is_string($row['agent']) || $row['agent'] === '' || self::agent($row['agent']) !== $row['agent']
                || !is_string($row['agentKey']) || $row['agentKey'] !== mb_strtolower($row['agent'], 'UTF-8')) self::invalid('selected line identity is missing or not normalized');
            foreach (['retentionDate', 'paymentDate', 'retainedMonth'] as $field) {
                if (!is_string($row[$field]) || !self::date($row[$field])) self::invalid('selected line dates must be real YYYY-MM-DD values');
            }
            if ($row['retainedMonth'] !== substr($row['retentionDate'], 0, 7) . '-01'
                || $row['retainedMonth'] > $snapshot['period']
                || substr($row['paymentDate'], 0, 7) !== substr($snapshot['period'], 0, 7)) self::invalid('selected line dates do not match the retained month and payable period');
            foreach (['currentTier', 'appliedTier'] as $field) {
                if (!is_int($row[$field]) || $row[$field] < 0 || $row[$field] > 4) self::invalid('tiers must be integers from 0 through 4');
            }
            if (($snapshot['policy'] === 'report_month_override' || $row['retainedMonth'] === $snapshot['period'])
                && $row['appliedTier'] !== $row['currentTier']) self::invalid('same-month or overridden pricing must use the captured current tier');
            if (!is_array($row['rates']) || !array_is_list($row['rates']) || count($row['rates']) !== 4) self::invalid('four captured T1 through T4 rates are required');
            foreach ($row['rates'] as $rate) if (!self::number($rate)) self::invalid('captured rates must be finite numbers');
            if (!self::number($row['amount'])) self::invalid('selected amount must be a finite number');
            $selected = $row['appliedTier'] === 0 ? 0.0 : (float) $row['rates'][$row['appliedTier'] - 1];
            if ((float) $row['amount'] !== $selected) self::invalid('selected amount differs from its captured applied tier rate');
            if (!array_key_exists($row['agentKey'], $results)) self::invalid('a selected line has no matching frozen commission result');
            $sums[$row['agentKey']] = ($sums[$row['agentKey']] ?? 0.0) + (float) $row['amount'];
            if (!is_finite($sums[$row['agentKey']])) self::invalid('raw selected amounts overflow their agent total');
        }
        foreach ($results as $key => $amount) {
            // Match the existing result writer: accumulate raw selections, then round once per agent.
            if (round($sums[$key] ?? 0.0, 2) !== $amount) self::invalid('selected line sum does not match the frozen commission result for ' . $key);
        }
        return $snapshot;
    }

    private static function results(array $component, string $source, string $period): array
    {
        self::fields($component, ['reportKind', 'source', 'period', 'component', 'complete', 'rows']);
        if (($component['reportKind'] ?? null) !== 'retention' || ($component['source'] ?? null) !== $source
            || ($component['period'] ?? null) !== $period || ($component['component'] ?? null) !== 'commission'
            || ($component['complete'] ?? null) !== true || !is_array($component['rows'] ?? null) || !array_is_list($component['rows'])) self::invalid('an exact complete regular Retention commission component is required');
        $results = [];
        foreach ($component['rows'] as $row) {
            if (!is_array($row)) self::invalid('commission result rows must be objects');
            self::fields($row, ['agent', 'agentKey', 'amount']);
            if (!is_string($row['agent']) || $row['agent'] === '' || self::agent($row['agent']) !== $row['agent']
                || !is_string($row['agentKey']) || $row['agentKey'] !== mb_strtolower($row['agent'], 'UTF-8')
                || array_key_exists($row['agentKey'], $results) || !self::number($row['amount'])
                || round((float) $row['amount'], 2) !== (float) $row['amount']) self::invalid('commission identities and rounded amounts must be canonical and unique');
            $results[$row['agentKey']] = (float) $row['amount'];
        }
        return $results;
    }

    private static function fields(array $value, array $expected): void
    {
        if (count($value) !== count($expected) || array_diff(array_keys($value), $expected) || array_diff($expected, array_keys($value))) self::invalid('snapshot fields are missing or unsupported');
    }
    private static function agent(string $value): string
    {
        return RetentionAgentIdentity::canonicalName(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }
    private static function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value); }
    private static function date(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
    private static function invalid(string $reason): never { throw new \InvalidArgumentException('Frozen regular Retention pricing: ' . $reason . '.'); }
}
