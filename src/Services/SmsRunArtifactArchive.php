<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;
use Throwable;

/** Exact-byte archive/restore core. It has no provider, retention or timing policy. */
final class SmsRunArtifactArchive
{
    public function __construct(private readonly SmsRunArchiveStore $store) {}

    /**
     * @param array<string,mixed> $manifest
     * @return array{version:int,request_id:string,key:string,object_version:string,bytes:int,sha256:string,manifest_sha256:string}
     */
    public function archive(string $requestId, string $sourcePath, array $manifest, string $key): array
    {
        $evidence = $this->evidence($requestId, $sourcePath, $manifest);
        return $this->storeFile($requestId, $sourcePath, $evidence, $key);
    }

    /** Retain the original JSON bytes, including their original encoding/whitespace. */
    public function archiveManifest(string $requestId, string $sourcePath, array $manifest, string $key): array
    {
        $this->requestId($requestId);
        $this->manifest($manifest, $requestId);
        if (basename(str_replace('\\', '/', $sourcePath)) !== 'manifest.json') {
            throw new RuntimeException('Archive the original published SMS manifest.');
        }
        $evidence = $this->fileEvidence($sourcePath);
        if ($evidence['bytes'] > 2097152 || $this->decodeManifest(file_get_contents($sourcePath)) !== $manifest) {
            throw new RuntimeException('The original SMS manifest differs from its frozen receipt.');
        }
        return $this->storeFile($requestId, $sourcePath, $evidence + ['manifest_sha256' => $this->manifestHash($manifest)], $key);
    }

    private function storeFile(string $requestId, string $sourcePath, array $evidence, string $key): array
    {
        $key = $this->key($key);
        $metadata = [
            'request-id' => $requestId,
            'content-bytes' => (string) $evidence['bytes'],
            'content-sha256' => $evidence['sha256'],
            'manifest-sha256' => $evidence['manifest_sha256'],
        ];
        $objectVersion = $this->version($this->store->putIfAbsent($key, $sourcePath, $metadata));
        $receipt = ['version' => 1, 'request_id' => $requestId, 'key' => $key,
            'object_version' => $objectVersion] + $evidence;
        $this->verifyStored($receipt);

        return $receipt;
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $manifest */
    public function verify(array $receipt, array $manifest): void
    {
        $receipt = $this->receipt($receipt, $manifest);
        $this->verifyStored($receipt);
    }

    public function verifyManifest(array $receipt, array $manifest): void
    {
        $this->objectReceipt($receipt);
        $this->manifest($manifest, $receipt['request_id']);
        if ($receipt['bytes'] > 2097152 || $receipt['manifest_sha256'] !== $this->manifestHash($manifest)) {
            throw new RuntimeException('The archived SMS manifest receipt differs from the frozen manifest.');
        }
        $this->verifyStored($receipt);
        $stream = $this->store->read($receipt['key'], $receipt['object_version']);
        if (! is_resource($stream)) throw new RuntimeException('The archived SMS manifest is unreadable.');
        try { $json = stream_get_contents($stream, 2097153); } finally { fclose($stream); }
        if ($this->decodeManifest($json) !== $manifest) throw new RuntimeException('The archived SMS manifest differs from its frozen receipt.');
    }

    private function verifyStored(array $receipt): void
    {
        $metadata = $this->store->metadata($receipt['key'], $receipt['object_version']);
        $expected = [
            'request-id' => $receipt['request_id'],
            'content-bytes' => (string) $receipt['bytes'],
            'content-sha256' => $receipt['sha256'],
            'manifest-sha256' => $receipt['manifest_sha256'],
        ];
        $actualMetadata = $metadata['metadata'] ?? null;
        if (is_array($actualMetadata)) {
            ksort($actualMetadata);
            ksort($expected);
        }
        if (! is_array($metadata) || ($metadata['bytes'] ?? null) !== $receipt['bytes']
            || $actualMetadata !== $expected) {
            throw new RuntimeException('The archived SMS object metadata differs from its immutable receipt.');
        }
        $stream = $this->store->read($receipt['key'], $receipt['object_version']);
        if (! is_resource($stream)) throw new RuntimeException('The archived SMS object is unreadable.');
        try {
            $actual = $this->streamEvidence($stream, $receipt['bytes']);
        } finally {
            fclose($stream);
        }
        if ($actual !== ['bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']]) {
            throw new RuntimeException('The archived SMS object bytes differ from its immutable receipt.');
        }
    }

    /**
     * Restore to a caller-approved existing private directory. Existing exact bytes
     * are accepted; different bytes are never overwritten.
     *
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $manifest
     */
    public function restore(array $receipt, array $manifest, string $destination, ?string $scratchDirectory = null): string
    {
        $receipt = $this->receipt($receipt, $manifest);
        $this->verify($receipt, $manifest);
        return $this->restoreStored($receipt, $destination, $manifest['download']['name'], $scratchDirectory);
    }

    public function restoreManifest(array $receipt, array $manifest, string $destination, ?string $scratchDirectory = null): string
    {
        $this->verifyManifest($receipt, $manifest);
        return $this->restoreStored($receipt, $destination, 'manifest.json', $scratchDirectory);
    }

    private function restoreStored(array $receipt, string $destination, string $name, ?string $scratchDirectory): string
    {
        $destination = $this->destination($destination, $name);
        if (file_exists($destination)) {
            if ($this->fileEvidence($destination) === ['bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']]) {
                return $destination;
            }
            throw new RuntimeException('The SMS restore target already contains different bytes.');
        }

        $scratch = $scratchDirectory === null ? $destination : $this->destination(rtrim($scratchDirectory, '/\\').'/'.$name, $name);
        $temporary = $scratch.'.restore-'.bin2hex(random_bytes(8));
        $input = $this->store->read($receipt['key'], $receipt['object_version']);
        if (! is_resource($input)) throw new RuntimeException('The archived SMS object is unreadable.');
        $output = null;
        try {
            $output = fopen($temporary, 'xb');
            if ($output === false) throw new RuntimeException('Unable to create private SMS restore scratch.');
            if (stream_copy_to_stream($input, $output, $receipt['bytes'] + 1) !== $receipt['bytes'] || ! fflush($output)
                || (function_exists('fsync') && ! fsync($output))) {
                throw new RuntimeException('Unable to restore and flush the archived SMS object.');
            }
            fclose($output); $output = null;
            fclose($input); $input = null;
            if (PHP_OS_FAMILY !== 'Windows') {
                $group = filegroup(dirname($destination));
                if ($group === false || ! chgrp($temporary, $group)) throw new RuntimeException('Unable to preserve the private SMS storage group.');
            }
            if (! chmod($temporary, PHP_OS_FAMILY === 'Windows' ? 0600 : 0640)) throw new RuntimeException('Unable to protect restored SMS bytes.');
            if ($this->fileEvidence($temporary) !== ['bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']]) {
                throw new RuntimeException('Restored SMS bytes failed integrity verification.');
            }
            // Same-directory hard-link creation is atomic and never replaces a
            // destination that appears after the preflight check.
            set_error_handler(static fn (): bool => true);
            try {
                $published = link($temporary, $destination);
            } finally {
                restore_error_handler();
            }
            if (! $published) {
                if (! file_exists($destination)
                    || $this->fileEvidence($destination) !== ['bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']]) {
                    throw new RuntimeException('A conflicting SMS restore target appeared; nothing was overwritten.');
                }
            }
            if (! unlink($temporary)) {
                throw new RuntimeException('Restored SMS bytes were published but their private scratch could not be removed.');
            }
            if ($this->fileEvidence($destination) !== ['bytes' => $receipt['bytes'], 'sha256' => $receipt['sha256']]) {
                throw new RuntimeException('Published SMS restore differs from its immutable receipt.');
            }

            return $destination;
        } catch (Throwable $error) {
            if (is_resource($output)) fclose($output);
            if (is_resource($input)) fclose($input);
            if (is_file($temporary)) unlink($temporary);
            throw $error;
        }
    }

    /** @param array<string,mixed> $manifest @return array{bytes:int,sha256:string,manifest_sha256:string} */
    private function evidence(string $requestId, string $sourcePath, array $manifest): array
    {
        $this->requestId($requestId);
        $download = $this->manifest($manifest, $requestId);
        if (! is_file($sourcePath) || is_link($sourcePath) || basename(str_replace('\\', '/', $sourcePath)) !== $download['name']) {
            throw new RuntimeException('Archive only the exact published SMS artifact named by its manifest.');
        }
        $actual = $this->fileEvidence($sourcePath);
        if ($actual !== ['bytes' => $download['bytes'], 'sha256' => $download['sha256']]) {
            throw new RuntimeException('The SMS artifact differs from its manifest and was not archived.');
        }
        return $actual + ['manifest_sha256' => $this->manifestHash($manifest)];
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $manifest @return array<string,mixed> */
    private function receipt(array $receipt, array $manifest): array
    {
        $this->objectReceipt($receipt);
        $requestId = $receipt['request_id'];
        $download = $this->manifest($manifest, $requestId);
        if (! is_string($receipt['key'] ?? null) || $this->key($receipt['key']) !== $receipt['key']
            || ! is_string($receipt['object_version'] ?? null) || $this->version($receipt['object_version']) !== $receipt['object_version']
            || ($receipt['bytes'] ?? null) !== $download['bytes']
            || ($receipt['sha256'] ?? null) !== $download['sha256']
            || ($receipt['manifest_sha256'] ?? null) !== $this->manifestHash($manifest)) {
            throw new RuntimeException('The SMS archive receipt differs from the frozen artifact manifest.');
        }
        return $receipt;
    }

    private function objectReceipt(array $receipt): void
    {
        if (($receipt['version'] ?? null) !== 1 || ! is_string($receipt['request_id'] ?? null)
            || ! is_string($receipt['key'] ?? null) || ! is_string($receipt['object_version'] ?? null)
            || ! is_int($receipt['bytes'] ?? null) || $receipt['bytes'] < 1
            || ! is_string($receipt['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['sha256'])
            || ! is_string($receipt['manifest_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $receipt['manifest_sha256'])) {
            throw new RuntimeException('Unrecognized SMS archive receipt.');
        }
        $this->requestId($receipt['request_id']); $this->key($receipt['key']); $this->version($receipt['object_version']);
    }

    private function decodeManifest(string|false $json): array
    {
        try { $value = $json === false ? null : json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('Invalid archived SMS manifest JSON.', 0, $error); }
        if (! is_array($value)) throw new RuntimeException('Invalid archived SMS manifest JSON.');
        return $value;
    }

    /** @param array<string,mixed> $manifest @return array{name:string,bytes:int,sha256:string} */
    private function manifest(array $manifest, string $requestId): array
    {
        $download = $manifest['download'] ?? null;
        if (($manifest['version'] ?? null) !== 1 || ($manifest['request_id'] ?? null) !== $requestId
            || ! is_array($download) || ! is_string($download['name'] ?? null)
            || (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,199}\.(?:csv|zip)$/D', $download['name'])
                && ! SmsRunArtifactBundle::isPlannedDownloadName($download['name']))
            || ! is_int($download['bytes'] ?? null) || $download['bytes'] < 1
            || ! is_string($download['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $download['sha256'])) {
            throw new RuntimeException('Invalid SMS artifact manifest for archival.');
        }
        return ['name' => $download['name'], 'bytes' => $download['bytes'], 'sha256' => $download['sha256']];
    }

    /** @param array<string,mixed> $manifest */
    private function manifestHash(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function requestId(string $requestId): void
    {
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $requestId)) {
            throw new RuntimeException('Invalid SMS archive request ID.');
        }
    }

    private function key(string $key): string
    {
        if ($key === '' || strlen($key) > 512 || str_starts_with($key, '/') || str_ends_with($key, '/')
            || str_contains($key, '\\') || str_contains($key, '//') || preg_match('/[\x00-\x1f\x7f]/', $key)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $key)
            || ! SmsRunArtifactBundle::isSafeArchiveKey($key)) {
            throw new RuntimeException('Use a safe explicit provider key for the SMS archive.');
        }
        return $key;
    }

    private function version(string $version): string
    {
        if ($version === '' || strlen($version) > 255 || preg_match('/[\x00-\x20\x7f]/', $version)) {
            throw new RuntimeException('The SMS archive provider returned an invalid immutable version.');
        }
        return $version;
    }

    private function destination(string $destination, string $name): string
    {
        $destination = str_replace('\\', '/', $destination);
        if (str_contains($destination, "\0") || preg_match('/[\r\n]/', $destination)
            || ! preg_match('~^(?:[A-Za-z]:/|/)(?!/)~D', $destination)
            || str_contains($destination, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $destination)
            || basename($destination) !== $name || is_link($destination)) {
            throw new RuntimeException('Use an absolute private restore path with the archived filename.');
        }
        $parent = dirname($destination);
        if (! is_dir($parent) || is_link($parent) || ! is_writable($parent)) {
            throw new RuntimeException('The private SMS restore directory is unavailable.');
        }
        $ancestor = $parent;
        while (dirname($ancestor) !== $ancestor) {
            if (is_link($ancestor)) throw new RuntimeException('SMS restore paths cannot contain symlinks.');
            $ancestor = dirname($ancestor);
        }
        return $destination;
    }

    /** @return array{bytes:int,sha256:string} */
    private function fileEvidence(string $path): array
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path)) throw new RuntimeException('SMS artifact bytes are unavailable.');
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if ($bytes === false || $bytes < 1 || $sha256 === false) throw new RuntimeException('SMS artifact bytes are unavailable.');
        return ['bytes' => $bytes, 'sha256' => $sha256];
    }

    /** @param resource $stream @return array{bytes:int,sha256:string} */
    private function streamEvidence($stream, int $expected): array
    {
        $hash = hash_init('sha256');
        $bytes = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) throw new RuntimeException('Unable to read archived SMS bytes.');
            if ($chunk === '' && ! feof($stream)) throw new RuntimeException('Archived SMS byte stream stopped making progress.');
            $bytes += strlen($chunk);
            if ($bytes > $expected) throw new RuntimeException('The archived SMS object exceeds its immutable byte count.');
            hash_update($hash, $chunk);
        }
        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }
}
