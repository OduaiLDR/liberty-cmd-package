<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

/** Immutable, reviewed LT fallback for C_SOURCE IDs absent from current DATA_SOURCES. */
final class SmsLegacyDataSourceMap
{
    public const SOURCE_SHA256 = '96593abf2ac53287b6882a4f3e02e6a0b8021770474473bcc9da50af9ed6d4a2';
    public const ROWS = 142;
    private static ?array $cached = null;

    public static function path(): string
    {
        return dirname(__DIR__, 2).'/resources/sms/legacy-lt-data-sources-20261028.csv';
    }

    /** @return array<string,string> Exact decimal ID text => reviewed name. */
    public static function all(): array
    {
        if (self::$cached !== null) return self::$cached;
        $path = self::path();
        if (! is_file($path) || ! hash_equals(self::SOURCE_SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Legacy LT Data Source mapping bytes changed.');
        }
        $handle = fopen($path, 'rb');
        if (! $handle) throw new RuntimeException('Legacy LT Data Source mapping is unreadable.');
        try {
            $headers = fgetcsv($handle);
            if ($headers !== ['ID','NAME']) throw new RuntimeException('Legacy LT Data Source mapping headers changed.');
            $map = [];
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) !== 2 || preg_match('/\A[0-9]{1,38}\z/D', $row[0]) !== 1
                    || $row[1] === '' || str_contains($row[1], "\0") || preg_match('//u', $row[1]) !== 1
                    || mb_strlen($row[1]) > 50) {
                    throw new RuntimeException('Legacy LT Data Source mapping contains an invalid row.');
                }
                if (isset($map[$row[0]]) && $map[$row[0]] !== $row[1]) {
                    throw new RuntimeException('Legacy LT Data Source mapping contains a conflicting ID.');
                }
                $map[$row[0]] = $row[1];
            }
            if (! feof($handle) || count($map) !== self::ROWS) {
                throw new RuntimeException('Legacy LT Data Source mapping row count changed.');
            }
            return self::$cached = $map;
        } finally {
            fclose($handle);
        }
    }

    /** Current official names always win; only unresolved LT IDs use the immutable fallback. */
    public static function resolve(string $company, ?string $sourceId, ?string $currentName, int $currentVariants = 0): array
    {
        if ($currentVariants < 0 || $currentVariants > 1) {
            throw new RuntimeException('Conflicting current Data Source names.');
        }
        if ($currentName !== null) {
            self::text($currentName);
            $sourceId = self::id($sourceId);
            if ($currentVariants !== 1 || $sourceId === null) throw new RuntimeException('Incomplete current Data Source evidence.');
            return ['name' => $currentName, 'id' => $sourceId, 'origin' => 'current', 'mapping_sha256' => null];
        }
        $sourceId = self::id($sourceId);
        if ($currentVariants !== 0) throw new RuntimeException('Incomplete current Data Source evidence.');
        if ($company !== 'lt' || $sourceId === null) {
            return ['name' => null, 'id' => $sourceId, 'origin' => null, 'mapping_sha256' => null];
        }
        $name = self::all()[$sourceId] ?? null;
        return ['name' => $name, 'id' => $sourceId, 'origin' => $name === null ? null : 'legacy',
            'mapping_sha256' => $name === null ? null : self::SOURCE_SHA256];
    }

    private static function id(?string $value): ?string
    {
        if ($value === null) return null;
        if (preg_match('/\A[0-9]{1,38}\z/D', $value) !== 1) throw new RuntimeException('Invalid Data Source ID.');
        return $value;
    }

    private static function text(string $value): void
    {
        if (str_contains($value, "\0") || preg_match('//u', $value) !== 1 || mb_strlen($value) > 50) {
            throw new RuntimeException('Invalid current Data Source name.');
        }
    }
}
