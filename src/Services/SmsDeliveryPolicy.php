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

    /** Explicit frozen format for a versioned run, never inferred from configuration. */
    public static function headerForFormat(string $format): array
    {
        return match ($format) {
            SmsRunOptions::CSV_FORMAT => ['First name', 'debt load', 'phone1', 'send date'],
            'legacy-nine-column-v1' => self::header(false),
            'legacy-five-column-v1' => self::header(true),
            default => throw new InvalidArgumentException('Unknown SMS vendor CSV format.'),
        };
    }

    /** The legacy address argument is deliberately omitted by the four-column format. */
    public static function rowForFormat(string $format, string $client, string $address, mixed $amount, string $phone, string $sendDate): array
    {
        return match ($format) {
            SmsRunOptions::CSV_FORMAT => [explode(' ', trim($client))[0], self::debtLoad($amount), $phone, $sendDate],
            'legacy-nine-column-v1' => self::row($client, $address, $amount, $phone, $sendDate, false),
            'legacy-five-column-v1' => self::row($client, $address, $amount, $phone, $sendDate, true),
            default => throw new InvalidArgumentException('Unknown SMS vendor CSV format.'),
        };
    }
}
