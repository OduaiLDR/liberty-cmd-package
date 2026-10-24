<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;
use ZipArchive;

/** Original historical files only: never generate recipient CSVs or read current sources. */
final class SmsExternalArtifact
{
    public const FORMAT = 'sms-external-nine-column-v1';
    public const HEADER = ['First name','address','debt load','phone1','phone2','phone3','phone4','phone5','send date'];

    public static function manifest(object $header): array
    {
        $text = $header->Original_Artifacts_JSON ?? null;
        if (! is_string($text) || strlen($text) > 100000) throw new RuntimeException('Missing external original artifact receipt.');
        $value = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value) || ($value['version'] ?? null) !== 1 || ($value['csv_format'] ?? null) !== self::FORMAT
            || ($value['header'] ?? null) !== self::HEADER || ! is_array($value['parts'] ?? null)
            || ! array_is_list($value['parts']) || count($value['parts']) !== 3) throw new RuntimeException('Invalid external original artifact receipt.');
        $rows = 0;
        foreach ($value['parts'] as $i => $part) {
            self::entry($part);
            if (($part['part'] ?? null) !== $i + 1 || $part['name'] !== 'SMS_Data_Part'.($i + 1).'.csv') throw new RuntimeException('External part identity changed.');
            $rows += $part['rows'];
        }
        if ($rows !== (int) ($header->Expected_Rows ?? -1)) throw new RuntimeException('External artifact rows do not reconcile.');
        self::entry($value['pk_list'] ?? null);
        if ($value['pk_list']['name'] !== 'selected_TblProspects_PKs.csv' || $value['pk_list']['rows'] !== $rows) throw new RuntimeException('External PK receipt does not reconcile.');
        self::entry($value['download'] ?? null, false);
        if (($value['download']['format'] ?? null) !== 'zip' || ! str_ends_with($value['download']['name'], '.zip')) throw new RuntimeException('External download must wrap the original files.');
        return $value;
    }

    public static function root(object $run): string
    {
        $id = strtolower((string) ($run->Request_ID ?? ''));
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)) throw new RuntimeException('Invalid external event identity.');
        $base = config('sms-exports.local_root') ?: storage_path('app/private/sms-exports');
        return rtrim(str_replace('\\', '/', (string) $base), '/').'/external-events/'.$id;
    }

    public static function fileView(object $run, object $header): ?array
    {
        if (empty($run->File_Path)) return null;
        $manifest = self::manifest($header);
        $entry = $manifest['download'];
        $path = self::path(self::root($run), $entry['private_relative_path'], false);
        if (str_replace('\\', '/', (string) $run->File_Path) !== $path || (int) $run->File_Bytes !== $entry['bytes']
            || ! is_string($run->File_Sha256) || ! hash_equals($entry['sha256'], $run->File_Sha256)) throw new RuntimeException('External primary file metadata changed.');
        return ['name' => $entry['name'], 'bytes' => $entry['bytes'], 'format' => 'zip', 'parts' => 3,
            'csv_format' => self::FORMAT, 'created_at' => $run->File_At ?? null, 'available' => is_file($path),
            'files' => array_map(static fn (array $part): array => ['name' => $part['name'], 'rows' => $part['rows']], $manifest['parts'])];
    }

    public static function verify(object $run, object $header): string
    {
        $view = self::fileView($run, $header);
        if ($view === null || ! $view['available']) throw new RuntimeException('Original external files are unavailable; they were not regenerated.');
        $manifest = self::manifest($header);
        $path = self::path(self::root($run), $manifest['download']['private_relative_path']);
        self::fingerprint($path, $manifest['download']);
        // The pinned ZIP hash protects the original bytes already deeply verified
        // at packaging. HTTP does not recount three million CSV rows.
        self::zip($path, $manifest['parts'], false);
        return $path;
    }

    /** $parts use the receipt's part/name/rows/bytes/sha256/private_relative_path keys. */
    public static function buildOriginalZip(string $root, array $parts, string $name): array
    {
        if (! array_is_list($parts) || count($parts) !== 3 || basename($name) !== $name || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.()-]{0,150}\.zip$/D', $name)) throw new RuntimeException('Invalid original ZIP request.');
        $target = self::path($root, $name, false);
        $lockPath = self::path($root, '.original-zip.lock', false);
        if (is_link($lockPath)) throw new RuntimeException('Unsafe external artifact lock.');
        $lock = fopen($lockPath, 'c+b');
        if ($lock === false || ! flock($lock, LOCK_EX)) throw new RuntimeException('Cannot lock original ZIP packaging.');
        try {
            foreach ($parts as $i => $entry) {
                self::entry($entry);
                if (($entry['part'] ?? null) !== $i + 1 || $entry['name'] !== 'SMS_Data_Part'.($i + 1).'.csv') throw new RuntimeException('Invalid original CSV identity.');
                $path = self::path($root, $entry['private_relative_path']);
                self::fingerprint($path, $entry);
                $stream = fopen($path, 'rb');
                if ($stream === false) throw new RuntimeException('Cannot read original CSV.');
                try {
                    if (fgetcsv($stream, 0, ',', '"', '') !== self::HEADER) throw new RuntimeException('Original CSV header changed.');
                    $rows = 0;
                    while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                        if (count($cells) !== 9) throw new RuntimeException('Original CSV width changed.');
                        $rows++;
                    }
                    if (! feof($stream) || $rows !== $entry['rows']) throw new RuntimeException('Original CSV row count changed.');
                } finally { fclose($stream); }
            }
            if (! file_exists($target)) {
                $scratch = self::path($root, '.original-'.bin2hex(random_bytes(8)).'.zip', false);
                $zip = new ZipArchive;
                if ($zip->open($scratch, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Cannot create original ZIP.');
                try {
                    foreach ($parts as $part) {
                        if (! $zip->addFile(self::path($root, $part['private_relative_path']), $part['name'])
                            || ! $zip->setCompressionName($part['name'], ZipArchive::CM_STORE)) throw new RuntimeException('Cannot package original CSV.');
                        $zip->setMtimeName($part['name'], 315532800);
                    }
                } finally { $zip->close(); }
                self::zip($scratch, $parts, true);
                if (PHP_OS_FAMILY !== 'Windows') {
                    if (! chgrp($scratch, filegroup($root)) || ! chmod($scratch, 0640)) throw new RuntimeException('Cannot protect original ZIP.');
                }
                if (file_exists($target) || ! rename($scratch, $target)) throw new RuntimeException('Cannot publish original ZIP without replacing evidence.');
            }
            self::zip($target, $parts, true);
            return ['name' => $name, 'format' => 'zip', 'bytes' => filesize($target), 'sha256' => hash_file('sha256', $target), 'private_relative_path' => $name];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function entry(mixed $entry, bool $rows = true): void
    {
        if (! is_array($entry) || ! is_string($entry['name'] ?? null) || basename($entry['name']) !== $entry['name']
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.()-]{0,180}$/D', $entry['name'])
            || ! is_int($entry['bytes'] ?? null) || $entry['bytes'] < 1
            || ! is_string($entry['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'])
            || ! is_string($entry['private_relative_path'] ?? null) || basename($entry['private_relative_path']) !== $entry['name']
            || ($rows && (! is_int($entry['rows'] ?? null) || $entry['rows'] < 1))) throw new RuntimeException('Invalid original file receipt.');
    }

    private static function path(string $root, string $relative, bool $exists = true): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (! preg_match('~^(?:[A-Za-z]:/|/)~', $root) || preg_match('/[\x00-\x1f\\\\:]/', $relative)
            || str_starts_with($relative, '/') || array_intersect(explode('/', $relative), ['', '.', '..']) !== []) throw new RuntimeException('Unsafe original artifact path.');
        $path = $root.'/'.$relative;
        for ($current = $path; ; $current = dirname($current)) {
            if (is_link($current)) throw new RuntimeException('Linked original artifact paths are not allowed.');
            if (dirname($current) === $current) break;
        }
        if (file_exists($path)) {
            $stat = lstat($path);
            if (! is_file($path) || $stat === false || $stat['nlink'] !== 1) throw new RuntimeException('Original artifact must be one regular private file.');
        } elseif ($exists) throw new RuntimeException('Original artifact is missing.');
        return $path;
    }

    private static function fingerprint(string $path, array $entry): void
    {
        if (filesize($path) !== $entry['bytes'] || ! hash_equals($entry['sha256'], hash_file('sha256', $path))) throw new RuntimeException('Original artifact bytes changed.');
    }

    private static function zip(string $path, array $parts, bool $deep): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Cannot verify original ZIP.');
        try {
            if ($zip->numFiles !== 3) throw new RuntimeException('Original ZIP must contain exactly three CSV files.');
            foreach ($parts as $i => $part) {
                $stat = $zip->statIndex($i);
                if ($stat === false || $stat['name'] !== $part['name'] || $stat['size'] !== $part['bytes']) throw new RuntimeException('Original ZIP membership changed.');
                $operatingSystem = $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $operatingSystem, $attributes) && $operatingSystem === ZipArchive::OPSYS_UNIX
                    && ! in_array(($attributes >> 16) & 0170000, [0, 0100000], true)) throw new RuntimeException('Linked or special ZIP members are not allowed.');
                if ($deep) {
                    $stream = $zip->getStream($part['name']);
                    if ($stream === false) throw new RuntimeException('Cannot read original ZIP member.');
                    try { $hash = hash_init('sha256'); hash_update_stream($hash, $stream); $actual = hash_final($hash); }
                    finally { fclose($stream); }
                    if (! hash_equals($part['sha256'], $actual)) throw new RuntimeException('Original ZIP member bytes changed.');
                }
            }
        } finally { $zip->close(); }
    }
}
