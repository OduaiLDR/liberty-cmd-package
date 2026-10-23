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
        if (($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION
            || ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) {
            throw new RuntimeException('Publish only the explicit approved SMS artifact generation.');
        }
        // One complete bundle verification, not one whole-ZIP hash for each member.
        $path = (new SmsRunArtifactBundle('v2'))->verify($runDirectory, $manifest, $options, $selected);
        $zip = null;
        try {
            if ($manifest['download']['format'] === 'zip') {
                $zip = new ZipArchive;
                if ($zip->open($path) !== true) throw new RuntimeException('Unable to open the verified approved SMS bundle.');
            }
            foreach ($manifest['parts'] as $part) {
                $source = $zip === null ? fopen($path, 'rb') : $zip->getStream($part['name']);
                if (! is_resource($source)) throw new RuntimeException('Unable to read an approved SMS CSV part.');
                $private = null;
                try {
                    $private = tmpfile();
                    if (! is_resource($private)) throw new RuntimeException('Unable to create private SMS publication scratch.');
                    $meta = stream_get_meta_data($private);
                    if (! ($meta['seekable'] ?? false) || ! isset($meta['uri'])
                        || (PHP_OS_FAMILY !== 'Windows' && (! chmod($meta['uri'], 0600) || (fstat($private)['mode'] & 0077) !== 0))) {
                        throw new RuntimeException('SMS publication scratch must be private and seekable.');
                    }
                    $hash = hash_init('sha256');
                    $copied = 0;
                    while (! feof($source)) {
                        $bytes = fread($source, min(1048576, $part['bytes'] + 1 - $copied));
                        if ($bytes === false || ($bytes === '' && ! feof($source))) throw new RuntimeException('Unable to copy an approved SMS CSV part.');
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
                    if ($copied !== $part['bytes'] || ! hash_equals($part['sha256'], hash_final($hash))
                        || ! fflush($private) || ! rewind($private)) {
                        throw new RuntimeException('The approved SMS CSV bytes differ from their frozen part receipt.');
                    }
                    $consume($part, $private);
                } finally {
                    fclose($source);
                    // Closing tmpfile also removes scratch, including when the consumer throws.
                    if (is_resource($private)) fclose($private);
                }
            }
        } finally {
            if ($zip !== null) $zip->close();
        }
    }
}
