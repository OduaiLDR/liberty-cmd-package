<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Support;

final class PmodBusinessDateResolver
{
    public static function nextBusinessDay(string $date): string
    {
        $cursor = new \DateTimeImmutable($date);

        while (self::isWeekend($cursor)) {
            $cursor = $cursor->modify('+1 day');
        }

        return $cursor->format('Y-m-d');
    }

    public static function nextDay(string $date): string
    {
        return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    public static function looksLikeDateRejection(string $message): bool
    {
        $normalized = strtolower($message);

        foreach (['holiday', 'weekend', 'business day', 'process date', 'draft date', 'invalid date'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function isWeekend(\DateTimeImmutable $date): bool
    {
        return in_array((int) $date->format('N'), [6, 7], true);
    }
}
