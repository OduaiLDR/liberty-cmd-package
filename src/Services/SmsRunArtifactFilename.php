<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;

/** Pure bounded filename rules. Saved drop names, never the mutable global counter. */
final class SmsRunArtifactFilename
{
    /** Existing Drop_Name columns allow 32 characters, including the SMS prefix. */
    private const DROP = 'SMS[0-9]{1,29}';

    public static function isDropRange(mixed $range): bool
    {
        return is_string($range) && preg_match('/^('.self::DROP.')-('.self::DROP.')$/D', $range, $match) === 1
            && self::number($match[1]) !== '' && self::number($match[2]) !== ''
            && self::compare($match[1], $match[2]) <= 0;
    }

    /** @param list<array{Drop_Name:string}> $drops */
    public static function rangeFromDrops(array $drops): string
    {
        if (! array_is_list($drops) || $drops === []) throw new InvalidArgumentException('A saved SMS drop range is required before building files.');
        $names = []; $seen = [];
        foreach ($drops as $drop) {
            $name = is_array($drop) ? ($drop['Drop_Name'] ?? null) : null;
            if (! is_string($name) || ! preg_match('/^'.self::DROP.'$/D', $name) || self::number($name) === ''
                || isset($seen[self::number($name)])) {
                throw new InvalidArgumentException('Saved SMS drop names must be unique positive SMS numbers.');
            }
            $seen[self::number($name)] = true; $names[] = $name;
        }
        usort($names, self::compare(...));
        return $names[0].'-'.$names[count($names) - 1];
    }

    /** @return null|array{send_date:string,drop_name_range:?string,id_pending?:bool} */
    public static function parseStem(string $stem): ?array
    {
        if (! preg_match('/^SMS Export - ([0-9]{4}-[0-9]{2}-[0-9]{2})(?: - ('.self::DROP.'-'.self::DROP.'|ID Pending))?$/D', $stem, $match)) return null;
        try { SmsDeliveryPolicy::plannedDate($match[1]); }
        catch (InvalidArgumentException) { return null; }
        $range = $match[2] ?? null;
        if ($range === 'ID Pending') return ['send_date' => $match[1], 'drop_name_range' => null, 'id_pending' => true];
        if ($range !== null && ! self::isDropRange($range)) return null;
        return ['send_date' => $match[1], 'drop_name_range' => $range];
    }

    public static function stemFromDownload(string $name, string $format): string
    {
        if ($format === 'csv' && str_ends_with($name, ' - Part 1 of 1.csv')) {
            $stem = substr($name, 0, -strlen(' - Part 1 of 1.csv'));
            if (self::parseStem($stem) !== null) return $stem;
        }
        return substr($name, 0, -strlen('.'.$format));
    }

    private static function number(string $name): string { return ltrim(substr($name, 3), '0'); }

    private static function compare(string $left, string $right): int
    {
        $left = self::number($left); $right = self::number($right);
        return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
    }
}
