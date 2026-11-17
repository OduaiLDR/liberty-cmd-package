<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;
use Throwable;

/**
 * Explicit, create-only S3 publisher: each approved CSV part is stored once in a private bucket and handed out as a
 * presigned GET link. Construction, identity() and policy() perform no I/O.
 *
 * A presigned link cannot live longer than 7 days after it is signed (an AWS limit), and it dies early when it was
 * signed with temporary credentials, so those are refused. The link ends at the earlier of that limit and the frozen
 * policy's expiry; it is never extended by a retry. An uncertain upload is reconciled by reading the object back,
 * never by overwriting it.
 */
final class SmsS3RunLinkPublisher implements SmsRunLinkPublisher
{
    public const MAX_LINK_SECONDS = 604800;
    private const MAX_FILE_BYTES = 5368709120;
    private const RECEIPT_KEYS = ['version', 'request_id', 'part', 'name', 'url', 'sha256', 'bytes', 'rows', 'remote', 'policy'];
    private const REMOTE_KEYS = ['provider', 'bucket', 'region', 'key', 'etag', 'version_id', 'checksum_sha256', 'link_expires'];
    private ?string $operationIdentity = null;

    public function __construct(private ?S3ClientInterface $client = null, private ?ClientInterface $http = null) {}

    public function identity(): string
    {
        return hash('sha256', $this->json($this->settings()));
    }

    /** Stable calendar-derived expiry: the end of the send date in California plus the configured days. */
    public function policy(string $sendDate): array
    {
        SmsDeliveryPolicy::plannedDate($sendDate);
        $settings = $this->settings();
        $expires = (new DateTimeImmutable($sendDate.' 23:59:59', new DateTimeZone('America/Los_Angeles')))
            ->modify('+'.$settings['expiry_days'].' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        return ['version' => 1, 'identity' => hash('sha256', $this->json($settings)), 'provider' => 's3', 'send_date' => $sendDate,
            'access' => 'presigned-get', 'max_link_seconds' => self::MAX_LINK_SECONDS, 'expires' => $expires];
    }

    public function publish(string $requestId, array $part, mixed $stream, array $policy): array
    {
        [$settings, $key] = $this->context($requestId, $part, $policy);
        SmsDeliveryPolicy::assertNotPast($policy['send_date']);
        if (! is_resource($stream) || ftell($stream) !== 0) throw new RuntimeException('S3 publication requires the exact CSV stream at offset zero.');
        $hash = hash_init('sha256');
        $bytes = hash_update_stream($hash, $stream);
        $digest = hash_final($hash);
        if ($bytes !== $part['bytes'] || ! hash_equals($part['sha256'], $digest) || ! rewind($stream)) {
            throw new RuntimeException('The approved CSV bytes differ from their manifest; no S3 request was made.');
        }
        $this->assertLongLivedCredentials();
        $expiresAt = $this->linkExpiry($policy);
        $checksum = base64_encode(hex2bin($digest));

        $head = $this->head($settings, $key);
        if ($head === null) {
            $body = Utils::streamFor($stream);
            try {
                $this->assertUnchanged();
                $this->client()->putObject($this->base($settings, $key) + ['Body' => $body, 'ContentLength' => $bytes,
                    'ContentType' => 'text/csv', 'ChecksumSHA256' => $checksum, 'IfNoneMatch' => '*', 'ServerSideEncryption' => 'AES256',
                    'Metadata' => ['request-id' => $requestId, 'sha256' => $digest]]);
            } catch (Throwable) {
                // Another attempt may have created it (412), or a lost response may already have stored exactly this object.
                $head = $this->head($settings, $key);
                if ($head === null) throw new RuntimeException('S3 upload is incomplete or uncertain; retry only through publication reconciliation.');
            } finally {
                $body->detach(); // the caller owns the stream
                if (is_resource($stream)) rewind($stream);
            }
            $head ??= $this->head($settings, $key);
        }
        $object = $this->object($head, $part, $checksum);

        $this->assertUnchanged();
        $get = ['Bucket' => $settings['bucket'], 'Key' => $key];
        if ($object['version_id'] !== null) $get['VersionId'] = $object['version_id'];
        try {
            $url = (string) $this->client()->createPresignedRequest($this->client()->getCommand('GetObject', $get), $expiresAt,
                ['start_time' => Carbon::now('UTC')->getTimestamp()])->getUri();
        } catch (Throwable) {
            throw new RuntimeException('The S3 download link could not be signed.');
        }
        $remote = ['provider' => 's3', 'bucket' => $settings['bucket'], 'region' => $settings['region'], 'key' => $key,
            'etag' => $object['etag'], 'version_id' => $object['version_id'], 'checksum_sha256' => $checksum,
            'link_expires' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt)];
        $receipt = ['version' => 1, 'request_id' => $requestId, 'part' => $part['part'], 'name' => $part['name'], 'url' => $url,
            'sha256' => $part['sha256'], 'bytes' => $part['bytes'], 'rows' => $part['rows'], 'remote' => $remote, 'policy' => $policy];
        $this->verify($requestId, $part, $receipt, $policy);

        return $receipt;
    }

    /** No provider writes: the stored object, the saved link and a one-byte read through that link must all still agree. */
    public function verify(string $requestId, array $part, array $receipt, array $policy): void
    {
        [$settings, $key] = $this->context($requestId, $part, $policy);
        if (array_keys($receipt) !== self::RECEIPT_KEYS
            || $receipt['version'] !== 1 || $receipt['request_id'] !== $requestId || $receipt['part'] !== $part['part']
            || $receipt['name'] !== $part['name'] || $receipt['sha256'] !== $part['sha256']
            || $receipt['bytes'] !== $part['bytes'] || $receipt['rows'] !== $part['rows'] || $receipt['policy'] !== $policy
            || ! is_array($receipt['remote']) || array_keys($receipt['remote']) !== self::REMOTE_KEYS
            || $receipt['remote']['provider'] !== 's3' || $receipt['remote']['bucket'] !== $settings['bucket']
            || $receipt['remote']['region'] !== $settings['region'] || $receipt['remote']['key'] !== $key
            || $receipt['remote']['checksum_sha256'] !== base64_encode(hex2bin($part['sha256']))) {
            throw new RuntimeException('The saved S3 publication receipt is incomplete or inconsistent.');
        }
        $this->safeUrl($receipt['url'], $settings, $key, $receipt['remote'], $policy);
        $object = $this->object($this->head($settings, $key, $receipt['remote']['version_id']), $part, $receipt['remote']['checksum_sha256']);
        if ($object['etag'] !== $receipt['remote']['etag'] || $object['version_id'] !== $receipt['remote']['version_id']) {
            throw new RuntimeException('The saved S3 object changed; publication is held.');
        }
        $this->assertUnchanged();
        try {
            $response = $this->http()->request('GET', $receipt['url'], ['headers' => ['Range' => 'bytes=0-0'], 'allow_redirects' => false,
                'http_errors' => false, 'timeout' => 60, 'connect_timeout' => 15, 'stream' => true]);
            $status = $response->getStatusCode();
            $range = $response->getHeaderLine('Content-Range');
            $response->getBody()->close();
        } catch (Throwable) {
            throw new RuntimeException('The S3 download link could not be checked.');
        }
        if ($status !== 206 || $range !== 'bytes 0-0/'.$part['bytes']) {
            throw new RuntimeException('The S3 download link does not return the approved file; publication is held.');
        }
    }

    private function settings(): array
    {
        if (config('sms-exports.file_publication_enabled', false) !== true) throw new RuntimeException('SMS external file publication is not enabled.');
        $settings = ['provider' => 's3', 'bucket' => config('sms-exports.s3_publication_bucket'), 'region' => config('sms-exports.s3_publication_region'),
            'prefix' => config('sms-exports.s3_publication_prefix'), 'expected_owner' => config('sms-exports.s3_publication_expected_owner'),
            'expiry_days' => config('sms-exports.s3_link_expiry_days')];
        // No dots in the bucket name: the link uses the bucket's own host name over TLS.
        if (! is_string($settings['bucket']) || ! preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $settings['bucket'])
            || str_ends_with($settings['bucket'], '--x-s3')
            || ! is_string($settings['region']) || ! preg_match('/^[a-z]{2}(?:-[a-z]+)+-[1-9]$/D', $settings['region'])
            || ! is_string($settings['prefix']) || strlen($settings['prefix']) > 200
            || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*$#D', $settings['prefix'])
            || str_contains($settings['prefix'], '..')
            || ($settings['expected_owner'] !== null && (! is_string($settings['expected_owner']) || ! preg_match('/^[0-9]{12}$/D', $settings['expected_owner'])))
            || ! is_int($settings['expiry_days']) || $settings['expiry_days'] < 1 || $settings['expiry_days'] > 7) {
            throw new RuntimeException('Configure the explicit S3 bucket, region, prefix and link expiry (1 to 7 days) before publication.');
        }

        return $settings;
    }

    private function context(string $id, array $part, array $policy): array
    {
        $settings = $this->settings();
        $this->operationIdentity = hash('sha256', $this->json($settings));
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)
            || array_keys($part) !== ['part', 'name', 'rows', 'bytes', 'sha256'] || ! is_int($part['part'])
            || $part['part'] < 1 || $part['part'] > SmsRunArtifactBundle::MAX_PARTS || ! is_int($part['rows']) || $part['rows'] < 1
            || $part['rows'] > SmsRunOptions::MAX_TARGET || ! is_int($part['bytes']) || $part['bytes'] < 1
            || $part['bytes'] > self::MAX_FILE_BYTES || ! is_string($part['sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $part['sha256'])
            || ! is_string($part['name'])
            || ! preg_match('/^(SMS Export - .+) - Part ([1-9][0-9]*) of ([1-9][0-9]*)\.csv$/D', $part['name'], $m)
            || (int) $m[2] !== $part['part'] || (int) $m[3] < $part['part'] || (int) $m[3] > SmsRunArtifactBundle::MAX_PARTS
            || ($parsed = SmsRunArtifactFilename::parseStem($m[1])) === null || ! SmsRunArtifactFilename::isDropRange($parsed['drop_name_range'])) {
            throw new RuntimeException('S3 publication requires the exact approved final CSV part receipt.');
        }
        if ($policy !== $this->policy($parsed['send_date']) || strcmp($policy['expires'], Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z')) <= 0) {
            throw new RuntimeException('The frozen S3 policy differs or has expired; it cannot be extended by retry.');
        }

        return [$settings, $settings['prefix'].'/'.$id.'/v2/'.$part['name']];
    }

    /** The earlier of the policy's expiry and the 7-day signing limit; a link shorter than an hour is not worth sending. */
    private function linkExpiry(array $policy): int
    {
        $now = Carbon::now('UTC')->getTimestamp();
        $expiresAt = min($now + self::MAX_LINK_SECONDS, Carbon::parse($policy['expires'], 'UTC')->getTimestamp());
        if ($expiresAt - $now < 3600) throw new RuntimeException('The frozen S3 policy expires too soon to publish a useful link.');

        return $expiresAt;
    }

    private function assertLongLivedCredentials(): void
    {
        try {
            $credentials = $this->client()->getCredentials()->wait();
            $temporary = (string) $credentials->getSecurityToken() !== '' || $credentials->getExpiration() !== null;
        } catch (Throwable) {
            throw new RuntimeException('The S3 credentials could not be read.');
        }
        if ($temporary) throw new RuntimeException('S3 links need a long-lived access key; temporary credentials would end the link early.');
    }

    private function head(array $settings, string $key, ?string $version = null): ?array
    {
        $this->assertUnchanged();
        $args = $this->base($settings, $key) + ['ChecksumMode' => 'ENABLED'];
        if ($version !== null) $args['VersionId'] = $version;
        try {
            return $this->client()->headObject($args)->toArray();
        } catch (AwsException $error) {
            if ($error->getStatusCode() === 404
                && in_array($error->getAwsErrorCode(), [null, '', 'NotFound', 'NoSuchKey', 'NoSuchVersion'], true)) return null;
            throw new RuntimeException('The S3 object could not be read.');
        } catch (Throwable) {
            throw new RuntimeException('The S3 object could not be read.');
        }
    }

    /** The stored object must be exactly the approved part: same size, same SHA-256, encrypted at rest. */
    private function object(?array $head, array $part, string $checksum): array
    {
        if ($head === null) throw new RuntimeException('The published S3 object is missing; publication is held.');
        $etag = $head['ETag'] ?? null;
        $version = $head['VersionId'] ?? null;
        if (($head['DeleteMarker'] ?? false) !== false || ($head['ContentLength'] ?? null) !== $part['bytes']
            || ($head['ChecksumSHA256'] ?? null) !== $checksum || ($head['ServerSideEncryption'] ?? null) !== 'AES256'
            || ! is_string($etag) || $etag === '' || strlen($etag) > 255
            || ($version !== null && (! is_string($version) || $version === '' || strlen($version) > 1024))) {
            throw new RuntimeException('The S3 key already holds different bytes or is not stored as required; publication is held.');
        }

        return ['etag' => $etag, 'version_id' => $version === 'null' ? null : $version];
    }

    private function safeUrl(mixed $url, array $settings, string $key, array $remote, array $policy): void
    {
        $parts = is_string($url) && strlen($url) <= 8192 && ! preg_match('/[\x00-\x20\x7f\\\\]|%(?:[01][0-9a-f]|7f|5c)/i', $url) ? parse_url($url) : false;
        parse_str(is_array($parts) ? (string) ($parts['query'] ?? '') : '', $query);
        $signed = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', (string) ($query['X-Amz-Date'] ?? ''), new DateTimeZone('UTC'));
        $seconds = (string) ($query['X-Amz-Expires'] ?? '');
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== $settings['bucket'].'.s3.'.$settings['region'].'.amazonaws.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || rawurldecode((string) ($parts['path'] ?? '')) !== '/'.$key
            || ($query['X-Amz-Algorithm'] ?? null) !== 'AWS4-HMAC-SHA256' || isset($query['X-Amz-Security-Token'])
            || ! is_string($query['X-Amz-Signature'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $query['X-Amz-Signature'])
            || ($query['versionId'] ?? null) !== $remote['version_id']
            || $signed === false || ! preg_match('/^[1-9][0-9]{0,5}$/D', $seconds) || (int) $seconds > self::MAX_LINK_SECONDS) {
            throw new RuntimeException('The saved S3 link is not a link to the approved object; publication is held.');
        }
        $expires = $signed->getTimestamp() + (int) $seconds;
        if (gmdate('Y-m-d\TH:i:s\Z', $expires) !== $remote['link_expires'] || strcmp($remote['link_expires'], $policy['expires']) > 0) {
            throw new RuntimeException('The saved S3 link does not match its recorded expiry; publication is held.');
        }
        if ($expires <= Carbon::now('UTC')->getTimestamp()) {
            throw new RuntimeException('The S3 download link has expired; it cannot be extended.');
        }
    }

    private function base(array $settings, string $key): array
    {
        $base = ['Bucket' => $settings['bucket'], 'Key' => $key];
        if ($settings['expected_owner'] !== null) $base['ExpectedBucketOwner'] = $settings['expected_owner'];

        return $base;
    }

    /** Configuration drift during an operation stops it before the next provider call. */
    private function assertUnchanged(): void
    {
        if ($this->operationIdentity === null || ! hash_equals($this->operationIdentity, hash('sha256', $this->json($this->settings())))) {
            throw new RuntimeException('The S3 publication settings changed during the operation; publication is held.');
        }
    }

    private function client(): S3ClientInterface
    {
        return $this->client ??= new S3Client(['version' => 'latest', 'region' => $this->settings()['region']]);
    }

    private function http(): ClientInterface
    {
        return $this->http ??= new Client;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
