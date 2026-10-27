<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/** Non-overridable SMS admission and eligibility policy for current prospect State. */
final class SmsStatePolicy
{
    public const VERSION = 'sms-state-exclusion-v1';

    public const BLOCKED = ['CO', 'OR', 'RI', 'WI', 'WV'];

    /** Reviewed US state/DC postal codes. Territories require an explicit business rule. */
    public const KNOWN = [
        'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO',
        'MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','DC',
    ];

    public const ALLOWED = [
        'AL','AK','AZ','AR','CA','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO',
        'MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','PA','SC','SD','TN','TX','UT','VT','VA','WA','WY','DC',
    ];

    public static function holdReason(mixed $state): ?string
    {
        if ($state === null) return 'missing_state';
        if (! is_string($state) && ! is_int($state)) return 'invalid_state';

        $normalized = strtoupper(trim((string) $state));
        if ($normalized === '') return 'missing_state';
        if (! preg_match('/^[A-Z]{2}$/D', $normalized)) return 'invalid_state';
        if (! in_array($normalized, self::KNOWN, true)) return 'unknown_state';

        return in_array($normalized, self::BLOCKED, true) ? 'blocked_state' : null;
    }

    public static function normalize(mixed $state): ?string
    {
        if ($state === null || (! is_string($state) && ! is_int($state))) return null;
        $normalized = strtoupper(trim((string) $state));

        return preg_match('/^[A-Z]{2}$/D', $normalized) ? $normalized : null;
    }

    public static function applyEligible(Builder $query, string $column = 'State'): Builder
    {
        return $query->whereRaw(self::sqlPredicate($column, $query->getConnection()->getDriverName()));
    }

    public static function sqlPredicate(string $column, string $driver): string
    {
        $allowed = "'".implode("','", self::ALLOWED)."'";
        if ($driver === 'sqlsrv') {
            return "{$column} IS NOT NULL AND ".self::normalizedSql($column, $driver)." IN ({$allowed})";
        }
        if ($driver === 'sqlite') {
            return "{$column} IS NOT NULL AND ".self::normalizedSql($column, $driver)." IN ({$allowed})";
        }

        throw new InvalidArgumentException('SMS State policy supports only the reviewed SQL Server and SQLite test drivers.');
    }

    public static function normalizedSql(string $column, string $driver): string
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $column)) {
            throw new InvalidArgumentException('SMS State policy requires an internal column reference.');
        }
        if ($driver === 'sqlsrv') return "UPPER(LTRIM(RTRIM({$column}))) COLLATE Latin1_General_100_BIN2";
        if ($driver === 'sqlite') return "UPPER(TRIM({$column}))";

        throw new InvalidArgumentException('SMS State policy supports only the reviewed SQL Server and SQLite test drivers.');
    }
}
