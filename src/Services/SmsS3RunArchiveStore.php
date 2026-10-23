<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3ClientInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Create-only S3 adapter with reads pinned to a concrete object version.
 * Versioning is required for identity; it does not provide WORM retention.
 * The injected client owns credentials. This adapter never configures a disk,
 * changes bucket policy/versioning, deletes objects, or selects retention.
 */
final class SmsS3RunArchiveStore implements SmsRunArchiveStore
{
    public function __construct(
        private readonly S3ClientInterface $client,
        private readonly string $bucket,
        private readonly string $expectedBucketOwner,
        private readonly string $encryption,
        private readonly ?string $kmsKeyId = null,
    ) {
        if (! preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket)
            || str_contains($bucket, '..') || str_ends_with($bucket, '--x-s3')
            || filter_var($bucket, FILTER_VALIDATE_IP)
            || ! preg_match('/^[0-9]{12}$/D', $expectedBucketOwner)) {
            throw new RuntimeException('The SMS archive requires an explicit general-purpose bucket and expected owner.');
        }
        if (! in_array($encryption, ['AES256', 'aws:kms'], true)
            || ($encryption === 'AES256' && $kmsKeyId !== null)
            || ($encryption === 'aws:kms' && (! is_string($kmsKeyId)
                || ! preg_match('/^arn:aws(?:-us-gov|-cn)?:kms:[a-z0-9-]+:[0-9]{12}:key\/[A-Za-z0-9-]+$/D', $kmsKeyId)))) {
            throw new RuntimeException('The SMS archive requires explicit encryption and a key ARN for KMS.');
        }
    }

    public function identity(): string
    {
        return 'sms-s3-v1:'.hash('sha256', json_encode([
            'bucket' => $this->bucket,
            'owner' => $this->expectedBucketOwner,
            'region' => $this->client->getRegion(),
            'endpoint' => (string) $this->client->getEndpoint(),
            'encryption' => $this->encryption,
            'kms_key_arn' => $this->kmsKeyId,
        ], JSON_THROW_ON_ERROR));
    }

    public function putIfAbsent(string $key, string $sourcePath, array $metadata): string
    {
        $this->key($key);
        $metadata = $this->canonicalMetadata($metadata);
        $this->assertConditionalModel();
        if (is_link($sourcePath) || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('The SMS archive source is not a readable regular file.');
        }
        $source = @fopen($sourcePath, 'rb');
        if (! is_resource($source)) throw new RuntimeException('The SMS archive source could not be opened.');
        try {
            $stat = fstat($source);
            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $source);
            $digest = hash_final($hash);
            if (! is_array($stat) || ! is_int($stat['size'] ?? null) || $stat['size'] !== $bytes || ! rewind($source)) {
                throw new RuntimeException('The SMS archive source changed or could not be read.');
            }
            try {
                $versioning = $this->client->getBucketVersioning($this->base());
                if (($versioning['Status'] ?? null) !== 'Enabled') {
                    throw new RuntimeException('The SMS archive bucket must have versioning enabled.');
                }
            } catch (Throwable) {
                throw new RuntimeException('The SMS archive bucket versioning could not be verified as enabled.');
            }
            $args = $this->base($key) + [
                'Body' => $source, 'ContentLength' => $bytes,
                'ChecksumSHA256' => base64_encode(hex2bin($digest)),
                'Metadata' => $metadata, 'IfNoneMatch' => '*',
                'ServerSideEncryption' => $this->encryption,
            ];
            if ($this->kmsKeyId !== null) $args['SSEKMSKeyId'] = $this->kmsKeyId;
            try {
                $result = $this->client->putObject($args);
            } catch (AwsException $error) {
                if ($error->getStatusCode() === 412) {
                    return $this->replay($key, $metadata, $bytes, $digest);
                }
                if ($error->getStatusCode() === 409) {
                    throw new RuntimeException('The SMS archive publication conflicted; retry the same immutable artifact.');
                }
                throw new RuntimeException('The SMS archive object could not be published.');
            } catch (Throwable) {
                throw new RuntimeException('The SMS archive object could not be published.');
            }
            $this->assertEncryption($result->toArray());
            return $this->version($result['VersionId'] ?? null);
        } finally {
            fclose($source);
        }
    }

    public function metadata(string $key, string $version): ?array
    {
        $this->key($key);
        $this->version($version);
        $result = $this->head($key, $version);
        if ($result === null) return null;
        if ($this->version($result['VersionId'] ?? null) !== $version) {
            throw new RuntimeException('The SMS archive returned a different object version.');
        }
        return $this->evidence($result);
    }

    public function read(string $key, string $version): mixed
    {
        $this->key($key);
        $this->version($version);
        try {
            $result = $this->client->getObject($this->base($key) + ['VersionId' => $version]);
        } catch (Throwable) {
            throw new RuntimeException('The SMS archive version could not be read.');
        }
        $body = $result['Body'] ?? null;
        try {
            if ($this->version($result['VersionId'] ?? null) !== $version) {
                throw new RuntimeException('The SMS archive returned a different object version.');
            }
            $this->assertEncryption($result->toArray());
            if ($body instanceof StreamInterface) $body = $body->detach();
            if (! is_resource($body) || get_resource_type($body) !== 'stream') {
                throw new RuntimeException('The SMS archive version did not return a readable stream.');
            }
            return $body;
        } catch (Throwable $error) {
            if ($body instanceof StreamInterface) $body->close();
            elseif (is_resource($body)) fclose($body);
            throw $error;
        }
    }

    private function replay(string $key, array $metadata, int $bytes, string $digest): string
    {
        $head = $this->head($key);
        if ($head === null) throw new RuntimeException('The conflicting SMS archive object is unavailable; retry the same artifact.');
        $version = $this->version($head['VersionId'] ?? null);
        if ($this->evidence($head) !== ['bytes' => $bytes, 'metadata' => $metadata]) {
            throw new RuntimeException('The SMS archive key already contains different bytes or metadata.');
        }
        $stream = $this->read($key, $version);
        try {
            $hash = hash_init('sha256');
            $actualBytes = hash_update_stream($hash, $stream);
            if ($actualBytes !== $bytes || ! hash_equals($digest, hash_final($hash))) {
                throw new RuntimeException('The SMS archive key already contains different bytes or metadata.');
            }
        } finally {
            fclose($stream);
        }
        return $version;
    }

    private function head(string $key, ?string $version = null): ?array
    {
        $args = $this->base($key);
        if ($version !== null) $args['VersionId'] = $version;
        try {
            return $this->client->headObject($args)->toArray();
        } catch (AwsException $error) {
            if ($error->getStatusCode() === 404
                && in_array($error->getAwsErrorCode(), [null, '', 'NotFound', 'NoSuchKey', 'NoSuchVersion'], true)) return null;
            throw new RuntimeException('The SMS archive metadata could not be read.');
        } catch (Throwable) {
            throw new RuntimeException('The SMS archive metadata could not be read.');
        }
    }

    private function evidence(array $result): array
    {
        $this->assertEncryption($result);
        if (($result['DeleteMarker'] ?? false) !== false || ! is_int($result['ContentLength'] ?? null)
            || $result['ContentLength'] < 0 || ! is_array($result['Metadata'] ?? null)) {
            throw new RuntimeException('The SMS archive metadata is incomplete.');
        }
        return ['bytes' => $result['ContentLength'], 'metadata' => $this->canonicalMetadata($result['Metadata'])];
    }

    private function assertEncryption(array $result): void
    {
        if (($result['ServerSideEncryption'] ?? null) !== $this->encryption
            || ($this->kmsKeyId !== null && ($result['SSEKMSKeyId'] ?? null) !== $this->kmsKeyId)) {
            throw new RuntimeException('The SMS archive encryption differs from the configured policy.');
        }
    }

    private function assertConditionalModel(): void
    {
        try {
            $input = $this->client->getApi()->getOperation('PutObject')->getInput();
            foreach (['IfNoneMatch' => 'If-None-Match', 'ExpectedBucketOwner' => 'x-amz-expected-bucket-owner',
                'ChecksumSHA256' => 'x-amz-checksum-sha256'] as $name => $header) {
                if (! $input->hasMember($name) || $input->getMember($name)['location'] !== 'header'
                    || $input->getMember($name)['locationName'] !== $header) {
                    throw new RuntimeException('Unsupported SDK model.');
                }
            }
        } catch (Throwable) {
            throw new RuntimeException('The installed S3 SDK cannot guarantee conditional SMS archive publication.');
        }
    }

    private function base(?string $key = null): array
    {
        $args = ['Bucket' => $this->bucket, 'ExpectedBucketOwner' => $this->expectedBucketOwner];
        if ($key !== null) $args['Key'] = $key;
        return $args;
    }

    private function canonicalMetadata(array $metadata): array
    {
        foreach ($metadata as $name => $value) {
            if (! is_string($name) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,127}$/D', $name)
                || ! is_string($value) || preg_match('/[^\x20-\x7e]/', $value)) {
                throw new RuntimeException('The SMS archive metadata must contain canonical string values.');
            }
        }
        ksort($metadata, SORT_STRING);
        return $metadata;
    }

    private function version(mixed $version): string
    {
        if (! is_string($version) || $version === '' || strtolower($version) === 'null'
            || strlen($version) > 255 || preg_match('/[\x00-\x20\x7f]/', $version)) {
            throw new RuntimeException('The SMS archive requires a concrete immutable object version.');
        }
        return $version;
    }

    private function key(string $key): void
    {
        if ($key === '' || strlen($key) > 1024 || str_contains($key, '//')
            || ! SmsRunArtifactBundle::isSafeArchiveKey($key)
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $key) || str_ends_with($key, '/')) {
            throw new RuntimeException('The SMS archive requires a safe explicit object key.');
        }
    }
}
