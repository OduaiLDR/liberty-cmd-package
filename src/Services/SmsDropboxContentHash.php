<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

/** Dropbox's documented SHA256-of-binary-4MiB-block-hashes, not ordinary file SHA256. */
final class SmsDropboxContentHash
{
    public const BLOCK_BYTES = 4194304;

    /** Reads bounded blocks from offset zero and restores the caller's original position. */
    public static function stream(mixed $stream): array
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream'
            || ! (stream_get_meta_data($stream)['seekable'] ?? false) || ($position = ftell($stream)) === false
            || fseek($stream, 0) !== 0) throw new RuntimeException('Dropbox hashing requires a seekable CSV stream.');
        $whole = hash_init('sha256'); $blocks = hash_init('sha256'); $total = 0;
        try {
            while (! feof($stream)) {
                $block = '';
                while (strlen($block) < self::BLOCK_BYTES && ! feof($stream)) {
                    $bytes = fread($stream, self::BLOCK_BYTES - strlen($block));
                    if ($bytes === false || ($bytes === '' && ! feof($stream))) throw new RuntimeException('Unable to read the approved CSV for Dropbox hashing.');
                    $block .= $bytes;
                }
                if ($block === '') break;
                $total += strlen($block); hash_update($whole, $block);
                hash_update($blocks, hash('sha256', $block, true));
            }
            return ['sha256' => hash_final($whole), 'content_hash' => hash_final($blocks), 'bytes' => $total];
        } finally {
            if (fseek($stream, $position) !== 0) throw new RuntimeException('Unable to restore the approved CSV stream position.');
        }
    }

    /** Used only for bounded upload-session chunks; an empty chunk has no block. */
    public static function bytes(string $bytes): string
    {
        $blocks = hash_init('sha256');
        for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset += self::BLOCK_BYTES) {
            hash_update($blocks, hash('sha256', substr($bytes, $offset, self::BLOCK_BYTES), true));
        }
        return hash_final($blocks);
    }
}
