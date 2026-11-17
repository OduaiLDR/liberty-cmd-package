<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use InvalidArgumentException;

/** Rules for newly generated files, not imported historical sends. */
final class SmsDeliveryPolicy
{
    public const TIMEZONE = 'America/Los_Angeles';

    public static function followingMonday(?Carbon $generatedAt = null): Carbon
    {
        return ($generatedAt ?? Carbon::now(self::TIMEZONE))->copy()
            ->setTimezone(self::TIMEZONE)->startOfDay()->next(Carbon::MONDAY);
    }

    /** Strict calendar date, deliberately independent of the host timezone. */
    public static function plannedDate(string $date): Carbon
    {
        if (! preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $match)
            || ! checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw new InvalidArgumentException('SMS send date must be a valid YYYY-MM-DD calendar date.');
        }
        return Carbon::createFromFormat('!Y-m-d', $date, self::TIMEZONE);
    }

    /** Only new/unpublished work expires. Retained historical files stay immutable. */
    public static function assertNotPast(string $date): Carbon
    {
        $planned = self::plannedDate($date);
        if ($planned->lt(Carbon::now(self::TIMEZONE)->startOfDay())) {
            throw new InvalidArgumentException('SMS send date must be today or a future date in California. Start a fresh batch if its unbuilt date has passed.');
        }
        return $planned;
    }

    /** Exact decimal arithmetic: never round down, never expose cents. */
    public static function debtLoad(mixed $amount): string
    {
        $text = trim((string) $amount);
        if (! preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $text, $m)) {
            throw new InvalidArgumentException('SMS debt must be a positive decimal(12,2) amount.');
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        $rounded = intdiv($cents + 99999, 100000) * 1000;
        if ($cents <= 0 || $rounded > 9999999999) {
            throw new InvalidArgumentException('SMS debt cannot be safely rounded into decimal(12,2).');
        }
        return (string) $rounded;
    }

    public static function header(bool $singlePhone): array
    {
        return $singlePhone
            ? ['First name', 'address', 'debt load', 'phone1', 'send date']
            : ['First name', 'address', 'debt load', 'phone1', 'phone2', 'phone3', 'phone4', 'phone5', 'send date'];
    }

    public static function row(string $client, string $address, mixed $amount, string $phone, string $sendDate, bool $singlePhone): array
    {
        $row = [explode(' ', trim($client))[0], $address, self::debtLoad($amount), $phone];
        return [...$row, ...($singlePhone ? [] : ['', '', '', '']), $sendDate];
    }

    /** Client joins first, middle and last, so the surname is its last word, ignoring a trailing suffix. */
    public static function lastName(string $client): string
    {
        $words = preg_split('/\s+/', trim($client), -1, PREG_SPLIT_NO_EMPTY);
        while (count($words) > 2 && preg_match('/\A(JR|SR|II|III|IV|V)\.?\z/i', end($words))) array_pop($words);
        return count($words) > 1 ? end($words) : '';
    }

    /** Explicit frozen format for a versioned run, never inferred from configuration. */
    /**
     * date_first_received and date_last_received as YYYY-MM-DD. The first is never after the last; with only one of
     * them known both are that day; with neither known both are blank rather than guessed.
     *
     * @return array{0: string, 1: string}
     */
    public static function receivedDates(?string $first, ?string $last): array
    {
        $day = static function (?string $value): ?string {
            $text = substr(trim((string) $value), 0, 10);
            if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $text, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) return null;

            return $text;
        };
        $first = $day($first);
        $last = $day($last);
        if ($first === null && $last === null) return ['', ''];
        $first ??= $last;
        $last ??= $first;
        if (strcmp($first, $last) > 0) $first = $last;

        return [$first, $last];
    }

    /** How many columns a format has after send date (the two received dates), so callers can still find debt, phone and date. */
    public static function columnsAfterSendDate(string $format): int
    {
        return $format === SmsRunOptions::RECEIVED_DATES_CSV_FORMAT ? 2 : 0;
    }

    public static function headerForFormat(string $format): array
    {
        return match ($format) {
            SmsRunOptions::CSV_FORMAT => ['First name', 'debt load', 'phone1', 'send date'],
            SmsRunOptions::FIRST_LAST_CSV_FORMAT => ['First name', 'Last name', 'debt load', 'phone1', 'send date'],
            SmsRunOptions::FIELD_NAME_CSV_FORMAT => ['first_name', 'last_name', 'debt_load', 'phone1', 'send_date'],
            SmsRunOptions::RECEIVED_DATES_CSV_FORMAT => ['first_name', 'last_name', 'debt_load', 'phone1', 'send_date', 'date_first_received', 'date_last_received'],
            'legacy-nine-column-v1' => self::header(false),
            'legacy-five-column-v1' => self::header(true),
            default => throw new InvalidArgumentException('Unknown SMS vendor CSV format.'),
        };
    }

    /** The legacy address argument is deliberately omitted by the four-column format. */
    public static function rowForFormat(string $format, string $client, string $address, mixed $amount, string $phone, string $sendDate, ?string $firstReceived = null, ?string $lastReceived = null): array
    {
        return match ($format) {
            SmsRunOptions::RECEIVED_DATES_CSV_FORMAT => [explode(' ', trim($client))[0], self::lastName($client), self::debtLoad($amount), $phone, $sendDate,
                ...self::receivedDates($firstReceived, $lastReceived)],
            SmsRunOptions::CSV_FORMAT => [explode(' ', trim($client))[0], self::debtLoad($amount), $phone, $sendDate],
            SmsRunOptions::FIRST_LAST_CSV_FORMAT, SmsRunOptions::FIELD_NAME_CSV_FORMAT => [explode(' ', trim($client))[0], self::lastName($client), self::debtLoad($amount), $phone, $sendDate],
            'legacy-nine-column-v1' => self::row($client, $address, $amount, $phone, $sendDate, false),
            'legacy-five-column-v1' => self::row($client, $address, $amount, $phone, $sendDate, true),
            default => throw new InvalidArgumentException('Unknown SMS vendor CSV format.'),
        };
    }
}
