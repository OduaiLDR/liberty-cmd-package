<?php

declare(strict_types=1);

use Aws\Command;
use Aws\Api\Service;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3ClientInterface;
use Aws\S3\S3Client;
use Cmd\Reports\Services\SmsS3RunArchiveStore;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

final class SmsS3RunArchiveStoreTest extends TestCase
{
    private const OWNER = '123456789012';
    private const KEY = 'private/sms/frozen-run.zip';
    private const KMS = 'arn:aws:kms:us-east-2:123456789012:key/11111111-2222-4333-8444-555555555555';
    private string $source;
    private S3ClientInterface $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = tempnam(sys_get_temp_dir(), 'sms-s3-test-');
        file_put_contents($this->source, 'exact archive bytes');
        $this->client = Mockery::mock(S3ClientInterface::class);
        $modelClient = new S3Client(['version' => 'latest', 'region' => 'us-east-2', 'credentials' => false, 'handler' => new MockHandler]);
        $this->client->shouldReceive('getApi')->andReturn($modelClient->getApi())->byDefault();
    }

    protected function tearDown(): void
    {
        if (is_file($this->source)) unlink($this->source);
        Mockery::close();
        parent::tearDown();
    }

    public function test_publishes_conditionally_with_owner_checksum_encryption_and_concrete_version(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->with(Mockery::on(function (array $args): bool {
            self::assertSame(['Bucket' => 'approved-private-bucket', 'ExpectedBucketOwner' => self::OWNER, 'Key' => self::KEY], array_intersect_key($args, array_flip(['Bucket', 'ExpectedBucketOwner', 'Key'])));
            self::assertSame('*', $args['IfNoneMatch']);
            self::assertSame('AES256', $args['ServerSideEncryption']);
            self::assertSame(19, $args['ContentLength']);
            self::assertSame(base64_encode(hash('sha256', 'exact archive bytes', true)), $args['ChecksumSHA256']);
            self::assertSame('exact archive bytes', stream_get_contents($args['Body']));
            self::assertSame(['content-bytes' => '19', 'request-id' => 'frozen-run'], $args['Metadata']);
            self::assertArrayNotHasKey('ACL', $args);
            self::assertArrayNotHasKey('SSEKMSKeyId', $args);
            return true;
        }))->andReturn(new Result(['VersionId' => 'version-1', 'ServerSideEncryption' => 'AES256']));
        self::assertSame('version-1', $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta()));
    }

    public function test_kms_uses_explicit_key_arn(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->with(Mockery::on(static fn (array $args): bool => $args['SSEKMSKeyId'] === self::KMS && $args['ServerSideEncryption'] === 'aws:kms'))
            ->andReturn(new Result(['VersionId' => 'version-kms', 'ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::KMS]));
        self::assertSame('version-kms', $this->store('aws:kms', self::KMS)->putIfAbsent(self::KEY, $this->source, $this->meta()));
    }

    public function test_exact_planned_date_names_reach_the_mock_sdk_without_broadening_key_spaces(): void
    {
        $this->versioning();
        $key = 'private/sms/frozen-run/SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 1.csv';
        $this->client->shouldReceive('putObject')->once()->with(Mockery::on(static fn (array $args): bool => $args['Key'] === $key))
            ->andReturn(new Result(['VersionId' => 'planned-version', 'ServerSideEncryption' => 'AES256']));
        self::assertSame('planned-version', $this->store()->putIfAbsent($key, $this->source, $this->meta()));
        foreach (['private space/SMS Export - 2026-10-26.zip', 'private/SMS Export - 2026-02-29.zip',
            'private/SMS Export - 2026-10-26.csv', 'private/SMS Export - 2026-10-26 - Part 2 of 2.csv'] as $bad) {
            try { $this->store()->putIfAbsent($bad, $this->source, $this->meta()); self::fail('Arbitrary spaced keys must fail.'); }
            catch (RuntimeException $error) { self::assertStringContainsString('safe explicit object key', $error->getMessage()); }
        }
    }

    public function test_replay_of_precondition_failure_checks_current_version_metadata_and_full_bytes(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->andThrow($this->error(412));
        $this->client->shouldReceive('headObject')->once()->with($this->objectArgs())->andReturn(new Result($this->head()));
        $this->client->shouldReceive('getObject')->once()->with($this->objectArgs() + ['VersionId' => 'version-1'])
            ->andReturn(new Result($this->head() + ['Body' => Utils::streamFor('exact archive bytes')]));
        self::assertSame('version-1', $this->store()->putIfAbsent(self::KEY, $this->source, array_reverse($this->meta(), true)));
    }

    public function test_replay_never_accepts_same_length_foreign_content(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->andThrow($this->error(412));
        $this->client->shouldReceive('headObject')->once()->andReturn(new Result($this->head()));
        $body = Utils::streamFor('alien archive bytes');
        $this->client->shouldReceive('getObject')->once()->andReturn(new Result($this->head() + ['Body' => $body]));
        try {
            $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
            self::fail('Foreign content must not be reused.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('different bytes or metadata', $error->getMessage());
            self::assertFalse($body->isReadable());
        }
    }

    public function test_replay_refuses_foreign_metadata_without_reading_body(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->andThrow($this->error(412));
        $head = $this->head(); $head['Metadata']['request-id'] = 'another-run';
        $this->client->shouldReceive('headObject')->once()->andReturn(new Result($head));
        $this->expectExceptionMessage('different bytes or metadata');
        $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
    }

    public function test_suspended_or_unversioned_buckets_are_rejected_before_put(): void
    {
        $this->client->shouldReceive('getBucketVersioning')->once()->andReturn(new Result(['Status' => 'Suspended']));
        $this->expectExceptionMessage('versioning could not be verified as enabled');
        $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
    }

    public function test_null_version_in_success_response_is_not_accepted(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->andReturn(new Result(['VersionId' => 'null', 'ServerSideEncryption' => 'AES256']));
        $this->expectExceptionMessage('concrete immutable object version');
        $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
    }

    public function test_null_version_in_replay_is_never_read(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->andThrow($this->error(412));
        $head = $this->head(); $head['VersionId'] = 'null';
        $this->client->shouldReceive('headObject')->once()->andReturn(new Result($head));
        $this->expectExceptionMessage('concrete immutable object version');
        $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
    }

    public function test_conflict_returns_a_safe_retry_error_without_replacing_object(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->andThrow($this->error(409));
        try {
            $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
            self::fail('Conflict must fail safely.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('retry the same immutable artifact', $error->getMessage());
            self::assertStringNotContainsString('sensitive-provider-detail', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_metadata_and_read_pin_the_requested_version_and_owner(): void
    {
        $this->client->shouldReceive('headObject')->once()->with($this->objectArgs() + ['VersionId' => 'version-1'])->andReturn(new Result($this->head()));
        self::assertSame(['bytes' => 19, 'metadata' => ['content-bytes' => '19', 'request-id' => 'frozen-run']], $this->store()->metadata(self::KEY, 'version-1'));
        $this->client->shouldReceive('getObject')->once()->with($this->objectArgs() + ['VersionId' => 'version-1'])
            ->andReturn(new Result($this->head() + ['Body' => Utils::streamFor('exact archive bytes')]));
        $stream = $this->store()->read(self::KEY, 'version-1');
        self::assertIsResource($stream);
        self::assertSame('exact archive bytes', stream_get_contents($stream));
        fclose($stream);
    }

    public function test_missing_metadata_is_null_but_forbidden_is_an_error(): void
    {
        $this->client->shouldReceive('headObject')->once()->andThrow($this->error(404));
        self::assertNull($this->store()->metadata(self::KEY, 'version-1'));
        $this->client->shouldReceive('headObject')->once()->andThrow($this->error(403));
        try {
            $this->store()->metadata(self::KEY, 'version-1');
            self::fail('Forbidden must not be mistaken for missing.');
        } catch (RuntimeException $error) {
            self::assertSame('The SMS archive metadata could not be read.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_wrong_version_or_encryption_cannot_supply_archive_bytes(): void
    {
        $head = $this->head(); $head['VersionId'] = 'foreign-version';
        $this->client->shouldReceive('headObject')->once()->andReturn(new Result($head));
        try {
            $this->store()->metadata(self::KEY, 'version-1');
            self::fail('Wrong version must fail.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('different object version', $error->getMessage());
        }
        $head = $this->head(); $head['ServerSideEncryption'] = 'aws:kms';
        $this->client->shouldReceive('getObject')->once()->andReturn(new Result($head + ['Body' => Utils::streamFor('exact archive bytes')]));
        $this->expectExceptionMessage('encryption differs');
        $this->store()->read(self::KEY, 'version-1');
    }

    public function test_identity_changes_with_location_owner_encryption_and_key_without_cloud_calls(): void
    {
        $this->client->shouldReceive('getRegion')->andReturn('us-east-2');
        $this->client->shouldReceive('getEndpoint')->andReturn(Utils::uriFor('https://s3.us-east-2.amazonaws.com'));
        $identity = $this->store()->identity();
        self::assertMatchesRegularExpression('/^sms-s3-v1:[a-f0-9]{64}$/', $identity);
        self::assertSame($identity, $this->store()->identity());
        self::assertNotSame($identity, (new SmsS3RunArchiveStore($this->client, 'other-private-bucket', self::OWNER, 'AES256'))->identity());
        self::assertNotSame($identity, (new SmsS3RunArchiveStore($this->client, 'approved-private-bucket', '999999999999', 'AES256'))->identity());
        self::assertNotSame($identity, $this->store('aws:kms', self::KMS)->identity());
        self::assertNotSame($this->store('aws:kms', self::KMS)->identity(), $this->store('aws:kms', str_replace('11111111', '22222222', self::KMS))->identity());
        $otherClient = Mockery::mock(S3ClientInterface::class);
        $otherClient->shouldReceive('getRegion')->andReturn('us-west-2');
        $otherClient->shouldReceive('getEndpoint')->andReturn(Utils::uriFor('https://s3.us-west-2.amazonaws.com'));
        self::assertNotSame($identity, (new SmsS3RunArchiveStore($otherClient, 'approved-private-bucket', self::OWNER, 'AES256'))->identity());
    }

    public function test_invalid_configuration_or_request_fails_before_cloud_calls(): void
    {
        foreach ([['', self::OWNER, 'AES256', null], ['approved-private-bucket', '', 'AES256', null],
            ['approved-private-bucket', self::OWNER, '', null], ['approved-private-bucket', self::OWNER, 'aws:kms', null],
            ['approved-private-bucket', self::OWNER, 'aws:kms', 'alias/unstable'], ['approved-private-bucket', self::OWNER, 'AES256', self::KMS]] as $args) {
            try {
                new SmsS3RunArchiveStore($this->client, ...$args);
                self::fail('Invalid configuration must fail.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
        foreach (['../escape', 'private//name', 'private/name/'] as $key) {
            try {
                $this->store()->putIfAbsent($key, $this->source, $this->meta());
                self::fail('Invalid key must fail.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('safe explicit object key', $error->getMessage());
            }
        }
    }

    public function test_old_sdk_model_cannot_silently_omit_conditional_upload_header(): void
    {
        $oldModel = new Service(['metadata' => ['protocol' => 'rest-xml'], 'operations' => ['PutObject' => []]], static fn (): array => []);
        $this->client->shouldReceive('getApi')->once()->andReturn($oldModel);
        $this->expectExceptionMessage('cannot guarantee conditional SMS archive publication');
        $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
    }

    public function test_provider_failures_do_not_leak_exception_messages_or_previous_exceptions(): void
    {
        $this->versioning();
        $this->client->shouldReceive('putObject')->once()->andThrow($this->error(403));
        try {
            $this->store()->putIfAbsent(self::KEY, $this->source, $this->meta());
            self::fail('Forbidden upload must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('The SMS archive object could not be published.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        $this->client->shouldReceive('getObject')->once()->andThrow($this->error(403));
        try {
            $this->store()->read(self::KEY, 'version-1');
            self::fail('Forbidden read must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('The SMS archive version could not be read.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_missing_bucket_is_not_reported_as_a_missing_object(): void
    {
        $this->client->shouldReceive('headObject')->once()->andThrow(new S3Exception('sensitive-provider-detail', new Command('HeadObject'), [
            'response' => new Response(404), 'code' => 'NoSuchBucket',
        ]));
        $this->expectExceptionMessage('metadata could not be read');
        $this->store()->metadata(self::KEY, 'version-1');
    }

    private function store(string $encryption = 'AES256', ?string $key = null): SmsS3RunArchiveStore
    {
        return new SmsS3RunArchiveStore($this->client, 'approved-private-bucket', self::OWNER, $encryption, $key);
    }

    private function versioning(): void
    {
        $this->client->shouldReceive('getBucketVersioning')->once()->with(['Bucket' => 'approved-private-bucket', 'ExpectedBucketOwner' => self::OWNER])->andReturn(new Result(['Status' => 'Enabled']));
    }

    private function objectArgs(): array
    {
        return ['Bucket' => 'approved-private-bucket', 'ExpectedBucketOwner' => self::OWNER, 'Key' => self::KEY];
    }

    private function meta(): array
    {
        return ['request-id' => 'frozen-run', 'content-bytes' => '19'];
    }

    private function head(): array
    {
        return ['VersionId' => 'version-1', 'ContentLength' => 19, 'Metadata' => $this->meta(), 'ServerSideEncryption' => 'AES256'];
    }

    private function error(int $status): S3Exception
    {
        return new S3Exception('sensitive-provider-detail', new Command('Test'), ['response' => new Response($status)]);
    }
}
