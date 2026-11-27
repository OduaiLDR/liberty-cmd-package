<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Rates used to render a saved Retention leadership report on its first download. */
final class RetentionLeadershipRules
{
    /** Preserve these defaults for archived bundles that predate saved Retention parameters. */
    public const V1 = [
        'version' => 'retention_leadership_v1',
        'managerThresholds' => [0.30, 0.35, 0.40, 0.45],
        'managerAmounts' => [0, 250, 500, 750, 1000],
        'bonusThresholds' => [0.40, 0.45, 0.50, 0.60],
        'bonusAmounts' => [0, 250, 500, 750, 1000],
        'teamLeaderThresholds' => [0.30, 0.40, 0.50],
        'teamLeaderDebtCeilings' => [15000, 30000, 60000],
        'teamLeaderRates' => [[0.9, 1.0, 2.0, 3.0], [4.0, 5.0, 6.0, 7.0], [8.0, 9.0, 10.0, 11.0]],
        'teamLeaderCap' => 2000.0,
    ];

    public static function validate(array $rules): void
    {
        if (count($rules) !== count(self::V1) || array_diff_key(self::V1, $rules)
            || $rules['version'] !== self::V1['version'] || !self::nonnegativeNumber($rules['teamLeaderCap'])) {
            throw new \InvalidArgumentException('Frozen Retention leadership rules are invalid.');
        }
        foreach (['managerThresholds' => 4, 'bonusThresholds' => 4, 'teamLeaderThresholds' => 3, 'teamLeaderDebtCeilings' => 3] as $field => $count) {
            self::validateNumbers($rules[$field], $count);
            $previous = 0;
            foreach ($rules[$field] as $threshold) {
                if ($threshold <= $previous || ($field !== 'teamLeaderDebtCeilings' && $threshold > 1)) {
                    throw new \InvalidArgumentException('Frozen Retention leadership thresholds are invalid.');
                }
                $previous = $threshold;
            }
        }
        foreach (['managerAmounts', 'bonusAmounts'] as $field) {
            self::validateNumbers($rules[$field], 5);
            if (count(array_filter($rules[$field], 'is_int')) !== 5) {
                throw new \InvalidArgumentException('Frozen Retention manager amounts are invalid.');
            }
        }
        $rates = $rules['teamLeaderRates'];
        if (!is_array($rates) || !array_is_list($rates) || count($rates) !== 3) {
            throw new \InvalidArgumentException('Frozen Retention Team Leader rates are invalid.');
        }
        foreach ($rates as $row) {
            self::validateNumbers($row, 4);
        }
    }

    private static function validateNumbers(mixed $values, int $length): void
    {
        if (!is_array($values) || !array_is_list($values) || count($values) !== $length) {
            throw new \InvalidArgumentException('Frozen Retention leadership rules are invalid.');
        }
        foreach ($values as $value) {
            if (!self::nonnegativeNumber($value)) {
                throw new \InvalidArgumentException('Frozen Retention leadership rules are invalid.');
            }
        }
    }

    private static function nonnegativeNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }
}
