<?php

namespace Cmd\Reports\Support;

use Carbon\Carbon;
use DateTimeInterface;

trait CsvFormatting
{
    /**
     * Format a value as a CSV-friendly date string (m/d/Y by default).
     */
    protected function formatCsvDate(mixed $value, string $format = 'm/d/Y'): string
    {
        if (!$value) {
            return '';
        }

        try {
            if ($value instanceof DateTimeInterface) {
                return $value->format($format);
            }

            return Carbon::parse($value)->format($format);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * Format a numeric value with the given precision (default 2 decimal places).
     */
    protected function formatCsvDecimal(mixed $value, int $precision = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $precision, '.', '');
    }

    /**
     * Format a ratio/percentage-like value with a higher precision (default 4 decimal places).
     */
    protected function formatCsvRatio(mixed $value, int $precision = 4): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return number_format((float) $value, $precision, '.', '');
    }

    /**
     * Format a value as currency with a leading dollar sign.
     */
    protected function formatCsvCurrency(mixed $value, int $precision = 2): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $normalized = function_exists('format_currency_string')
            ? format_currency_string($value)
            : $value;

        return '$' . number_format((float) $normalized, $precision, '.', '');
    }
}
