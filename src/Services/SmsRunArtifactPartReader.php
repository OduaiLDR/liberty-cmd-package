<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;
use ZipArchive;

/** Exact approved CSV bytes, privately staged with bounded memory before any consumer sees them. */
final class SmsRunArtifactPartReader
{
    /** Callback receives one manifest part and a seekable stream at offset zero; it must not retain it. */
    public function eachVerified(string $runDirectory, array $manifest, array $options, int $selected, callable $consume): void
    {
        $this->format($options);
        $path = (new SmsRunArtifactBundle('v2'))->verify($runDirectory, $manifest, $options, $selected);
        $zip = null;
        try {
            if ($manifest['download']['format'] === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($path) !== true) throw new RuntimeException('Unable to open the verified approved SMS bundle.');
            }
            foreach ($manifest['parts'] as $part) {
                $this->stage($zip === null ? fopen($path, 'rb') : $zip->getStream($part['name']), $part, $consume);
            }
        } finally {
            if ($zip !== null) $zip->close();
        }
    }

    /**
     * Reads one exact CSV without rescanning the full ZIP for every resumed upload.
     * The workflow verifies the whole original bundle when preparing and completing.
     * This operation proves the selected CSV's bytes, not a fresh whole-ZIP digest.
     */
    public function oneVerified(string $runDirectory, array $manifest, array $options, int $selected, int $number, callable $consume): void
    {
        $this->format($options);
        $runDirectory = str_replace('\\', '/', rtrim($runDirectory, '/\\'));
        $id = basename($runDirectory);
        (new SmsRunArtifactBundle('v2'))->validateManifest($manifest, $id, $options, $selected);
        if ($number < 1 || $number > count($manifest['parts'])) throw new RuntimeException('Invalid approved SMS part.');
        $part = $manifest['parts'][$number - 1];
        $directory = $runDirectory.'/v2/published';
        $path = $directory.'/'.$manifest['download']['name'];
        if (str_replace('\\', '/', (string) realpath($runDirectory)) !== $runDirectory
            || str_replace('\\', '/', (string) realpath($directory)) !== $directory
            || str_replace('\\', '/', (string) realpath($path)) !== $path || is_link($path)) {
            throw new RuntimeException('The approved SMS source path could not be confirmed.');
        }
        clearstatcache(true, $path);
        $before = lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] !== $manifest['download']['bytes']
            || (PHP_OS_FAMILY !== 'Windows' && !$this->permittedSource($before))) {
            throw new RuntimeException('The approved SMS source must remain a private regular file.');
        }
        $held = fopen($path, 'rb');
        if (!is_resource($held)) throw new RuntimeException('Unable to open the approved SMS source.');
        $zip = null;
        try {
            if (!flock($held, LOCK_SH | LOCK_NB) || !$this->sameFile($before, fstat($held))) {
                throw new RuntimeException('The approved SMS source identity changed.');
            }
            if ($manifest['download']['format'] === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($path) !== true || $zip->numFiles !== count($manifest['parts'])) {
                    throw new RuntimeException('The approved SMS bundle directory changed.');
                }
                foreach ($manifest['parts'] as $index => $expected) {
                    $entry = $zip->statIndex($index);
                    if ($entry === false || $entry['name'] !== $expected['name'] || $entry['size'] !== $expected['bytes']) {
                        throw new RuntimeException('The approved SMS bundle part metadata changed.');
                    }
                }
            }
            $input = $zip === null ? $held : $zip->getStream($part['name']);
            // A second handle protects ownership: stage() closes its input.
            if ($zip === null) $input = fopen($path, 'rb');
            $this->stage($input, $part, function (array $metadata, mixed $stream) use ($consume, $held, $before, $path): void {
                clearstatcache(true, $path);
                if (!$this->sameFile($before, fstat($held)) || !$this->sameFile($before, lstat($path)) || is_link($path)) {
                    throw new RuntimeException('The approved SMS source changed during the bounded read.');
                }
                $consume($metadata, $stream);
            });
        } finally {
            if ($zip !== null) $zip->close();
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    private function format(array $options): void
    {
        if (($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION
            || ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) {
            throw new RuntimeException('Publish only the explicit approved SMS artifact generation.');
        }
    }

    private function sameFile(array $before, array|false $after): bool
    {
        if ($after === false) return false;
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
            if ($before[$field] !== $after[$field]) return false;
        }
        return true;
    }

    /** Approved artifacts use 0640 with the queue/web workers' shared private group. */
    private function permittedSource(array $stat): bool
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getegid') || !function_exists('posix_getgroups')
            || ($stat['mode'] & 0137) !== 0 || ($stat['mode'] & 0400) === 0) return false;
        if ($stat['uid'] === posix_geteuid()) return true;
        $groups = posix_getgroups();
        return is_array($groups) && ($stat['mode'] & 0040) !== 0
            && in_array($stat['gid'], array_merge([posix_getegid()], $groups), true);
    }

    private function stage(mixed $source, array $part, callable $consume): void
    {
        if (!is_resource($source)) throw new RuntimeException('Unable to read an approved SMS CSV part.');
        $private = null;
        try {
            $private = tmpfile();
            if (!is_resource($private)) throw new RuntimeException('Unable to create private SMS publication scratch.');
            $meta = stream_get_meta_data($private);
            if (!($meta['seekable'] ?? false) || !isset($meta['uri'])
                || (PHP_OS_FAMILY !== 'Windows' && (!chmod($meta['uri'], 0600) || (fstat($private)['mode'] & 0077) !== 0))) {
                throw new RuntimeException('SMS publication scratch must be private and seekable.');
            }
            $hash = hash_init('sha256');
            $copied = 0;
            while (!feof($source)) {
                $bytes = fread($source, min(1048576, $part['bytes'] + 1 - $copied));
                if ($bytes === false || ($bytes === '' && !feof($source))) throw new RuntimeException('Unable to copy an approved SMS CSV part.');
                $copied += strlen($bytes);
                if ($copied > $part['bytes']) throw new RuntimeException('The approved SMS CSV part grew after verification.');
                hash_update($hash, $bytes);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($private, substr($bytes, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('Unable to stage an approved SMS CSV part.');
                    $offset += $written;
                }
            }
            if ($copied !== $part['bytes'] || !hash_equals($part['sha256'], hash_final($hash)) || !fflush($private) || !rewind($private)) {
                throw new RuntimeException('The approved SMS CSV bytes differ from their frozen part receipt.');
            }
            $consume($part, $private);
        } finally {
            fclose($source);
            if (is_resource($private)) fclose($private);
        }
    }
}
