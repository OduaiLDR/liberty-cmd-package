<?php

namespace Cmd\Reports\Services;

/** Shared normalization: preserve display names; normalize identifiers only. */
final class SmsProspectValues
{
    public static function phone(mixed $value): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $value);
        if (strlen($digits) === 11 && $digits[0] === '1') $digits = substr($digits, 1);
        return preg_match('/^[0-9]{10}$/D', $digits) ? $digits : null;
    }

    public static function tier(mixed $value): ?string
    {
        return preg_match('/^(?:TIER\s*|T)?([1-9])$/iD', trim((string) $value), $m) ? 'T'.$m[1] : null;
    }

    /** Only pass an internal SQL expression, never user input. */
    public static function tierSql(string $column): string
    {
        $clean = "UPPER(REPLACE(LTRIM(RTRIM({$column})), ' ', ''))";
        return "CASE WHEN {$clean} LIKE 'T[1-9]' THEN {$clean} WHEN {$clean} LIKE 'TIER[1-9]' THEN 'T' + RIGHT({$clean}, 1) WHEN {$clean} LIKE '[1-9]' THEN 'T' + {$clean} END";
    }
}
