<?php

namespace Cmd\Reports\Services;

use Aws\S3\S3Client;
use RuntimeException;

/** Private, durable SMS files; the browser downloads directly from S3. */
class SmsExportArtifacts
{
    public function configured(): bool
    {
        return class_exists(S3Client::class) && $this->bucket() !== '' && $this->region() !== '';
    }

    public function assertConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('SMS export storage is unavailable. Configure the existing S3 disk, or set both CMD_SMS_EXPORT_BUCKET and CMD_SMS_EXPORT_REGION.');
        }
    }

    public function key(string $requestId, string $format): string
    {
        $prefix = trim((string) config('sms-exports.prefix', 'sms-exports'), '/');

        return ($prefix === '' ? '' : $prefix.'/').$requestId.'.'.($format === 'zip' ? 'zip' : 'csv');
    }

    /** @return array{key:string,bytes:int} */
    public function publish(string $requestId, array $export): array
    {
        $this->assertConfigured();
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
        $this->assertSize($key, $expectedBytes);
        $client = $this->client();
        $command = $client->getCommand('GetObject', ['Bucket' => $this->bucket(), 'Key' => $key]);

        return (string) $client->createPresignedRequest($command, '+15 minutes')->getUri();
    }

    /** @return array{key:string,bytes:int,count:int,part_count:int,format:string}|null */
    public function recover(string $requestId, int $trackedCount): ?array
    {
        foreach (['csv', 'zip'] as $format) {
            $key = $this->key($requestId, $format);
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
        $this->assertConfigured();

        return new S3Client(['version' => 'latest', 'region' => $this->region()]);
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
