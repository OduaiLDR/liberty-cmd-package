<?php

namespace Cmd\Reports\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Retained private SMS files; downloads bypass the report proxy. */
class SmsExportArtifacts
{
    public function configured(): bool
    {
        if ($this->driver() === 'local') {
            $ancestor = $this->localRoot();
            while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) $ancestor = dirname($ancestor);
            return is_dir($ancestor) && is_writable($ancestor);
        }

        return $this->s3Configured();
    }

    public function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('SMS export storage is unavailable. CMD runner needs writable private storage.');
        }
    }

    public function key(string $requestId, string $format): string
    {
        $normalizedId = $this->requestId($requestId);
        if ($this->driver() === 'local') return 'local:'.$normalizedId.'.'.($format === 'zip' ? 'zip' : 'csv');

        return $this->s3Key($requestId, $format);
    }

    protected function s3Key(string $requestId, string $format): string
    {
        $prefix = trim((string) config('sms-exports.prefix', 'sms-exports'), '/');

        return ($prefix === '' ? '' : $prefix.'/').$requestId.'.'.($format === 'zip' ? 'zip' : 'csv');
    }

    /** @return array{key:string,bytes:int} */
    public function publish(string $requestId, array $export): array
    {
        $this->assertConfigured();
        if ($this->driver() === 'local') return $this->publishLocal($requestId, $export);
        $path = $export['path'];
        $size = filesize($path);
        if ($size === false || $size < 1) {
            throw new RuntimeException('The SMS export file is empty or unreadable. Tracking was not recorded.');
        }
        $format = ($export['format'] ?? 'csv') === 'zip' ? 'zip' : 'csv';
        $key = $this->key($requestId, $format);
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to read the SMS export file. Tracking was not recorded.');
        }
        try {
            $this->client()->putObject([
                'Bucket' => $this->bucket(), 'Key' => $key, 'Body' => $stream,
                'ContentLength' => $size,
                'ContentType' => $format === 'zip' ? 'application/zip' : 'text/csv; charset=UTF-8',
                'ContentDisposition' => 'attachment; filename="sms_export_'.$requestId.'.'.$format.'"',
                'ServerSideEncryption' => 'AES256',
                'Metadata' => ['request-id' => $requestId, 'sms-count' => (string) $export['count'],
                    'part-count' => (string) ($export['part_count'] ?? 1)],
            ]);
        } finally {
            fclose($stream);
        }
        $this->assertSize($key, $size);

        return ['key' => $key, 'bytes' => $size];
    }

    public function temporaryUrl(string $key, int $expectedBytes): string
    {
        if (str_starts_with($key, 'local:')) {
            // Polling reads only the small manifest and file size. Publication,
            // recovery and the direct download verify the complete SHA-256 hash.
            $manifest = $this->verifyLocal($key, $expectedBytes, false);
            $origin = rtrim((string) config('app.url'), '/');
            $url = parse_url($origin);
            if (($url['scheme'] ?? '') !== 'https' || empty($url['host']) || ! in_array($url['path'] ?? '', ['', '/'], true) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment'])) {
                throw new RuntimeException('CMD runner APP_URL must be its direct HTTPS origin for private SMS downloads.');
            }
            // Sign the relative URL so forwarded API Gateway host headers cannot
            // change the origin or invalidate direct CMD downloads.
            return $origin.URL::temporarySignedRoute('cmd.sms_export.download', now()->addMinutes(15),
                ['request_id' => $manifest['request_id']], false);
        }
        $this->assertSize($key, $expectedBytes);
        $client = $this->client();
        $command = $client->getCommand('GetObject', ['Bucket' => $this->bucket(), 'Key' => $key]);

        return (string) $client->createPresignedRequest($command, '+15 minutes')->getUri();
    }

    /** @return array{key:string,bytes:int,count:int,part_count:int,format:string}|null */
    public function recover(string $requestId, int $trackedCount): ?array
    {
        $normalizedId = $this->requestId($requestId);
        foreach (['csv', 'zip'] as $format) {
            $key = 'local:'.$normalizedId.'.'.$format;
            if (! is_file($this->localPath($key))) continue;
            $manifest = $this->verifyLocal($key);
            if ((int) $manifest['count'] !== $trackedCount) throw new RuntimeException('SMS artifact metadata differs from recorded tracking.');

            return ['key' => $key, 'bytes' => $manifest['bytes'], 'count' => $trackedCount,
                'part_count' => $manifest['part_count'], 'format' => $format];
        }
        if (! $this->s3Configured()) return null;
        foreach (['csv', 'zip'] as $format) {
            $key = $this->s3Key($requestId, $format);
            try {
                $object = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $key]);
            } catch (\Aws\S3\Exception\S3Exception $error) {
                if ((int) $error->getStatusCode() === 404) continue;
                throw $error;
            }
            $metadata = $object['Metadata'] ?? [];
            if (($metadata['request-id'] ?? '') !== $requestId || (int) ($metadata['sms-count'] ?? -1) !== $trackedCount
                || (int) $object['ContentLength'] < 1) {
                throw new RuntimeException('SMS artifact metadata differs from recorded tracking.');
            }

            return ['key' => $key, 'bytes' => (int) $object['ContentLength'], 'count' => $trackedCount,
                'part_count' => (int) ($metadata['part-count'] ?? 1), 'format' => $format];
        }

        return null;
    }

    protected function assertSize(string $key, int $expectedBytes): void
    {
        $object = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => $key]);
        if ((int) $object['ContentLength'] !== $expectedBytes) {
            throw new RuntimeException('SMS export artifact size does not match the completed file. Tracking needs reconciliation.');
        }
    }

    protected function client(): S3Client
    {
        if (! $this->s3Configured()) throw new RuntimeException('Legacy S3 SMS storage is unavailable.');

        return new S3Client(['version' => 'latest', 'region' => $this->region()]);
    }

    protected function driver(): string
    {
        $driver = (string) config('sms-exports.driver', 'local');
        if (! in_array($driver, ['local', 's3'], true)) throw new RuntimeException('Unsupported SMS export storage driver.');
        return $driver;
    }

    protected function s3Configured(): bool
    {
        return class_exists(S3Client::class) && $this->bucket() !== '' && $this->region() !== '';
    }

    protected function localRoot(): string
    {
        return rtrim((string) (config('sms-exports.local_root') ?: storage_path('app/private/sms-exports')), '/\\');
    }

    protected function requestId(string $id): string
    {
        $id = strtolower($id);
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)) throw new RuntimeException('Invalid SMS artifact request ID.');
        return $id;
    }

    /** Only deterministic UUID keys can resolve to private SMS paths. */
    public function localPath(string $key): string
    {
        if (! preg_match('/^local:([0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12})\.(csv|zip)$/D', $key, $match)) {
            throw new RuntimeException('Invalid private SMS artifact key.');
        }
        $path = $this->localRoot().'/'.$match[1].'/export.'.$match[2];
        if (is_link(dirname($path)) || is_link($path)) throw new RuntimeException('Invalid private SMS artifact path.');
        return $path;
    }

    /** @return array{request_id:string,bytes:int,count:int,part_count:int,format:string,sha256:string} */
    public function verifyLocal(string $key, ?int $expectedBytes = null, bool $verifyHash = true): array
    {
        $path = $this->localPath($key);
        $manifestPath = dirname($path).'/manifest.json';
        if (! is_file($path) || ! is_file($manifestPath) || is_link($manifestPath)) throw new RuntimeException('Recorded SMS export file is unavailable. Reconciliation is required.');
        $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $id = substr($key, 6, 36);
        $format = pathinfo($path, PATHINFO_EXTENSION);
        clearstatcache(true, $path);
        $bytes = filesize($path);
        if (! is_array($manifest) || ($manifest['request_id'] ?? '') !== $id || ($manifest['format'] ?? '') !== $format
            || ! is_int($manifest['bytes'] ?? null) || $manifest['bytes'] < 1 || $bytes !== $manifest['bytes']
            || ($expectedBytes !== null && $bytes !== $expectedBytes)
            || ! is_int($manifest['count'] ?? null) || $manifest['count'] < 1
            || ! is_int($manifest['part_count'] ?? null) || $manifest['part_count'] < 1
            || ! is_string($manifest['sha256'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $manifest['sha256'])
            || ($verifyHash && ! hash_equals($manifest['sha256'], (string) hash_file('sha256', $path)))) {
            throw new RuntimeException('SMS export artifact differs from its retained manifest. Reconciliation is required.');
        }
        return $manifest;
    }

    protected function publishLocal(string $requestId, array $export): array
    {
        $requestId = $this->requestId($requestId);
        $format = ($export['format'] ?? 'csv') === 'zip' ? 'zip' : 'csv';
        $key = 'local:'.$requestId.'.'.$format;
        $path = $this->localPath($key);
        $dir = dirname($path);
        $group = $this->createPrivateDirectory($dir);
        $source = $export['path'] ?? '';
        $size = is_file($source) ? filesize($source) : false;
        $count = (int) ($export['count'] ?? 0);
        $parts = (int) ($export['part_count'] ?? 1);
        if ($size === false || $size < 1 || $count < 1 || $parts < 1) throw new RuntimeException('The SMS export file is empty or unreadable. Tracking was not recorded.');
        $hash = hash_file('sha256', $source);
        if ($hash === false) throw new RuntimeException('Unable to verify the completed SMS file.');
        if (is_link($dir.'/publish.lock')) throw new RuntimeException('Invalid private SMS publication lock.');
        $lock = fopen($dir.'/publish.lock', 'c');
        if ($lock === false) throw new RuntimeException('Unable to lock private SMS artifact publication.');
        if (! flock($lock, LOCK_EX)) { fclose($lock); throw new RuntimeException('Unable to lock private SMS artifact publication.'); }
        $tmpArtifact = null;
        $tmpManifest = null;
        try {
            if (is_file($path) || is_file($dir.'/manifest.json')) {
                $previous = $this->verifyLocal($key, $size);
                if ($previous['count'] !== $count || $previous['part_count'] !== $parts || ! hash_equals($previous['sha256'], $hash)) {
                    throw new RuntimeException('This request already has a different retained SMS file. Reconcile before retrying.');
                }
                return ['key' => $key, 'bytes' => $size];
            }
            $tmpArtifact = tempnam($dir, '.artifact-');
            $tmpManifest = tempnam($dir, '.manifest-');
            if ($tmpArtifact === false || $tmpManifest === false || ! copy($source, $tmpArtifact)
                || filesize($tmpArtifact) !== $size || ! hash_equals($hash, (string) hash_file('sha256', $tmpArtifact))) {
                throw new RuntimeException('Unable to retain and verify the SMS file. Tracking was not recorded.');
            }
            $manifest = ['request_id' => $requestId, 'bytes' => $size, 'count' => $count,
                'part_count' => $parts, 'format' => $format, 'sha256' => $hash];
            if (file_put_contents($tmpManifest, json_encode($manifest, JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Unable to retain the SMS file manifest.');
            foreach ([$tmpArtifact, $tmpManifest] as $tmp) {
                $this->privatePermissions($tmp, false, $group);
                $handle = fopen($tmp, 'ab');
                if ($handle === false) throw new RuntimeException('Unable to flush the retained SMS file.');
                try {
                    if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) throw new RuntimeException('Unable to flush the retained SMS file.');
                } finally { fclose($handle); }
            }
            if (! rename($tmpArtifact, $path)) throw new RuntimeException('Unable to publish the retained SMS file.');
            $tmpArtifact = null;
            if (! rename($tmpManifest, $dir.'/manifest.json')) throw new RuntimeException('Unable to publish the retained SMS manifest. Reconciliation is required.');
            $tmpManifest = null;
            $this->verifyLocal($key, $size);
            return ['key' => $key, 'bytes' => $size];
        } finally {
            // Only unfinished scratch files are removed; retained exports survive.
            foreach ([$tmpArtifact, $tmpManifest] as $tmp) if (is_string($tmp) && is_file($tmp)) unlink($tmp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Preserve the existing storage group's access for worker/web identities. */
    protected function createPrivateDirectory(string $directory): ?int
    {
        $ancestor = $directory;
        $missing = [];
        while (! is_dir($ancestor)) {
            if (file_exists($ancestor) || is_link($ancestor) || dirname($ancestor) === $ancestor) {
                throw new RuntimeException('Invalid private SMS storage directory.');
            }
            $missing[] = $ancestor;
            $ancestor = dirname($ancestor);
        }
        $group = null;
        if (PHP_OS_FAMILY !== 'Windows') {
            $parentGroup = filegroup($ancestor);
            if ($parentGroup === false) throw new RuntimeException('Unable to determine the shared private storage group.');
            $group = $parentGroup;
        }
        foreach (array_reverse($missing) as $path) {
            if (! mkdir($path, $group === null ? 0700 : 02750) && ! is_dir($path)) {
                throw new RuntimeException('Unable to create private SMS export storage.');
            }
            $this->privatePermissions($path, true, $group);
        }

        return $group;
    }

    protected function privatePermissions(string $path, bool $directory, ?int $group): void
    {
        if ($group === null) {
            if (! chmod($path, $directory ? 0700 : 0600)) throw new RuntimeException('Unable to protect private SMS storage permissions.');
            return;
        }
        if (! chgrp($path, $group)) {
            throw new RuntimeException('The SMS worker cannot preserve the existing private storage group. Tracking was not recorded.');
        }
        $mode = $directory ? 02750 : 0640;
        if (! chmod($path, $mode) && (! $directory || ! chmod($path, 0750))) {
            throw new RuntimeException('Unable to preserve shared private SMS storage permissions.');
        }
        clearstatcache(true, $path);
        if (filegroup($path) !== $group || (fileperms($path) & 0777) !== ($directory ? 0750 : 0640)) {
            throw new RuntimeException('Private SMS storage permissions could not be verified. Tracking was not recorded.');
        }
    }

    protected function bucket(): string
    {
        // Laravel skips mergeConfigFrom when configuration is cached. The host
        // S3 disk remains in that cache, so exports still work without new env.
        return trim((string) (config('sms-exports.bucket') ?: config('filesystems.disks.s3.bucket')));
    }

    protected function region(): string
    {
        $smsBucket = trim((string) config('sms-exports.bucket'));
        $hostBucket = trim((string) config('filesystems.disks.s3.bucket'));

        if ($smsBucket === '' || $smsBucket === $hostBucket) {
            return trim((string) config('filesystems.disks.s3.region'));
        }

        return trim((string) config('sms-exports.region'));
    }
}
