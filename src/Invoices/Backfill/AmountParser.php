<?php

namespace Cmd\Reports\Invoices\Backfill;

/**
 * The one place an amount from an old marker/manifest becomes a number. The WHOLE value is validated; nothing is ever
 * "cleaned" (stripping characters first turned "1e3" into 13 and "unavailable-123" into -123). Anything that is not clearly a
 * dollar amount is null = unknown amount, which the Invoices page shows as "-".
 *
 * Accepted: JSON numbers (finite); strings that are, after trimming spaces/tabs/newlines, one of: plain (1000, 1234.5, .5),
 * exponent (1e3, 1.5E+3, 25e-1), thousands groups of exactly three (1,234,567.89; no exponent with commas), with an optional
 * single "$" and an optional single leading "-" before or after the "$" (-12.50, -$12.50, $-12.50), or accounting negatives
 * "(12.50)" / "($12.50)". Negatives are kept (a credit is a real amount). Rejected: empty, "+5", a second sign, "5-", "5.",
 * "1 000", "$$5", text before/after/inside, hex, NAN/INF/Infinity, anything that overflows to INF, arrays, booleans, null.
 */
final class AmountParser
{
    private const NUMBER = '(?:\d{1,3}(?:,\d{3})+(?:\.\d+)?|(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][+-]?\d+)?)';

    public static function dollars(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (! is_string($value)) {
            return null;
        }
        $text = trim($value, " \t\r\n");
        $negative = false;
        if (preg_match('/^\((.*)\)$/s', $text, $m) === 1) {
            $negative = true;
            $text = $m[1];
        }
        if (preg_match('/^(?:(?<sign>-)?\$?|\$(?<after>-))(?<n>' . self::NUMBER . ')$/D', $text, $m) !== 1) {
            return null;
        }
        $signed = ($m['sign'] ?? '') !== '' || ($m['after'] ?? '') !== '';
        if ($negative && $signed) {
            return null;
        }
        $number = (float) str_replace(',', '', $m['n']);
        if (! is_finite($number)) {
            return null;
        }

        return $negative || $signed ? -$number : $number;
    }
}
