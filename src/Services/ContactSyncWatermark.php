<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use DateTimeImmutable;
use RuntimeException;

/** A shared checkpoint file: readers see complete JSON and parallel sources cannot lose keys. */
final class ContactSyncWatermark
{
    /** Above this many skipped records a source keeps its checkpoint instead of retrying by ID. */
    public const MAX_RETRY_IDS = 5000;

    public static function read(string $path, string $source): ?string
    {
        self::validateSource($source);
        // Atomic replacement means a reader sees either complete version. Reading never creates a lock.
        return self::readAll($path)[$source] ?? null;
    }

    public static function write(string $path, string $source, string $datetime): void
    {
        self::validateSource($source);
        self::validateDate($datetime);
        self::replace($path, fn (): array => self::readAll($path), function (array $data) use ($source, $datetime): array {
            // A delayed completion must never move a successfully recorded source backwards.
            $data[$source] = max($data[$source] ?? $datetime, $datetime);
            return $data;
        });
    }

    /** Native source IDs the last completed run skipped; the next incremental run fetches them by ID. */
    public static function readRetryIds(string $path, string $source): array
    {
        self::validateSource($source);
        return self::readRetryAll($path)[$source] ?? [];
    }

    public static function writeRetryIds(string $path, string $source, array $ids): void
    {
        self::validateSource($source);
        $ids = self::validateRetryIds($ids);
        self::replace($path, fn (): array => self::readRetryAll($path), function (array $data) use ($source, $ids): array {
            if ($ids === []) {
                unset($data[$source]);
            } else {
                $data[$source] = $ids;
            }
            return $data;
        });
    }

    private static function replace(string $path, callable $read, callable $change): void
    {
        $lock = fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the contact-sync checkpoint lock.');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the contact-sync checkpoint.');
            }
            $data = $change($read());
            $json = json_encode((object) $data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            $temporary = tempnam(dirname($path), '.contact-sync-');
            if ($temporary === false || dirname($temporary) !== realpath(dirname($path))) {
                throw new RuntimeException('Cannot stage the contact-sync checkpoint in its directory.');
            }
            if (file_put_contents($temporary, $json) !== strlen($json)) {
                throw new RuntimeException('Incomplete contact-sync checkpoint write.');
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish the contact-sync checkpoint.');
            }
            $temporary = null;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function readAll(string $path): array
    {
        $data = self::decode($path);
        foreach ($data as $source => $date) {
            self::validateSource((string) $source);
            if (!is_string($date)) {
                throw new RuntimeException('Invalid contact-sync checkpoint timestamp.');
            }
            self::validateDate($date);
        }
        return $data;
    }

    private static function readRetryAll(string $path): array
    {
        $data = self::decode($path);
        foreach ($data as $source => $ids) {
            self::validateSource((string) $source);
            if (!is_array($ids)) {
                throw new RuntimeException('Invalid contact-sync retry list.');
            }
            $data[$source] = self::validateRetryIds($ids);
        }
        return $data;
    }

    private static function decode(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Cannot read the contact-sync checkpoint.');
        }
        try {
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Invalid contact-sync checkpoint; refusing an implicit full refresh.', 0, $e);
        }
        if (!$object instanceof \stdClass) {
            throw new RuntimeException('Contact-sync checkpoint must be a JSON object.');
        }
        return (array) $object;
    }

    private static function validateRetryIds(array $ids): array
    {
        foreach ($ids as $id) {
            if (!is_string($id) || !ContactSyncIdentity::validNativeId($id)) {
                throw new RuntimeException('Invalid contact-sync retry ID.');
            }
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > self::MAX_RETRY_IDS) {
            throw new RuntimeException('Contact-sync retry list exceeds ' . self::MAX_RETRY_IDS . ' IDs.');
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    private static function validateSource(string $source): void
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{0,31}$/D', $source)) {
            throw new RuntimeException('Invalid contact-sync checkpoint source.');
        }
    }

    private static function validateDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date);
        if (!$parsed || $parsed->format('Y-m-d H:i:s') !== $date) {
            throw new RuntimeException('Invalid contact-sync checkpoint timestamp.');
        }
    }
}
