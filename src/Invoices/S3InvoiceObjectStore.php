<?php

namespace Cmd\Reports\Invoices;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/** Real S3. Create-only writes use If-None-Match: * so an existing key is never overwritten. */
final class S3InvoiceObjectStore implements InvoiceObjectStore
{
    public function __construct(private readonly S3Client $client, private readonly string $bucket)
    {
    }

    public static function fromConfig(InvoiceRegistryConfig $config): self
    {
        // Credentials come from the Runner's normal AWS environment; this class never reads or stores a key.
        return new self(new S3Client(['version' => 'latest', 'region' => $config->region]), $config->bucket);
    }

    public function get(string $key): ?string
    {
        try {
            return (string) $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key])['Body'];
        } catch (AwsException $e) {
            if ((int) $e->getStatusCode() === 404 || $e->getAwsErrorCode() === 'NoSuchKey') {
                return null;
            }
            throw new InvoiceRegistryException("S3 could not read {$key}: {$e->getAwsErrorCode()} {$e->getAwsErrorMessage()}", 0, $e);
        }
    }

    public function putIfAbsent(string $key, string $body, string $contentType): bool
    {
        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $body,
                'ContentType' => $contentType,
                'ServerSideEncryption' => 'AES256',
                'IfNoneMatch' => '*',
            ]);

            return true;
        } catch (AwsException $e) {
            $status = (int) $e->getStatusCode();
            if ($status === 412) {
                return false;
            }
            if ($status === 409 || $status >= 500 || in_array($e->getAwsErrorCode(), ['SlowDown', 'RequestTimeout', 'ConditionalRequestConflict'], true)) {
                throw new InvoiceStoreTransientException("S3 {$status} {$e->getAwsErrorCode()}", 0, $e);
            }
            throw new InvoiceRegistryException("S3 refused {$key}: {$e->getAwsErrorCode()} {$e->getAwsErrorMessage()}", 0, $e);
        }
    }
}
