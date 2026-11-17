<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\Carbon;
use Cmd\Reports\Services\SmsRunEmailMessage;
use Cmd\Reports\Services\SmsRunPublicationWorkflow;
use Cmd\Reports\Services\SmsS3RunLinkPublisher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Psr\Http\Message\RequestInterface;

/** Closed S3 and HTTP stand-in: one bucket, one object at most, every call recorded. Nothing leaves the process. */
final class SmsS3PublisherFixtureStore
{
    public array $calls = [];
    public array $puts = [];
    public array $probes = [];
    public ?array $object = null;
    public bool $failPut = false;
    public bool $failPutAfterStore = false;
    public ?string $versionId = null;
    public ?Closure $mutateHead = null;
    public int $probeStatus = 206;
    public ?string $probeRange = null;
    public bool $probeDown = false;

    public function s3(CommandInterface $command, RequestInterface $request)
    {
        $this->calls[] = $command->getName();
        if ($command->getName() === 'HeadObject') {
            if ($this->object === null) return Create::rejectionFor(new S3Exception('Not Found', $command, ['response' => new Response(404), 'code' => 'NotFound']));
            $head = ['ContentLength' => $this->object['bytes'], 'ChecksumSHA256' => $this->object['checksum'], 'ServerSideEncryption' => $this->object['sse'], 'ETag' => $this->object['etag']];
            if ($this->versionId !== null) $head['VersionId'] = $this->versionId;
            if ($this->mutateHead !== null) ($this->mutateHead)($head);

            return Create::promiseFor(new Result($head));
        }
        if ($command->getName() === 'PutObject') {
            $body = (string) $command['Body'];
            $this->puts[] = ['Bucket' => $command['Bucket'], 'Key' => $command['Key'], 'IfNoneMatch' => $command['IfNoneMatch'], 'ChecksumSHA256' => $command['ChecksumSHA256'],
                'ServerSideEncryption' => $command['ServerSideEncryption'], 'ContentLength' => $command['ContentLength'], 'ContentType' => $command['ContentType'],
                'ExpectedBucketOwner' => $command['ExpectedBucketOwner'], 'Metadata' => $command['Metadata'], 'sha256' => hash('sha256', $body)];
            if ($this->failPut) return Create::rejectionFor(new S3Exception('Down', $command, ['response' => new Response(500), 'code' => 'InternalError']));
            if ($this->object !== null) return Create::rejectionFor(new S3Exception('Exists', $command, ['response' => new Response(412), 'code' => 'PreconditionFailed']));
            $this->object = ['bytes' => strlen($body), 'checksum' => base64_encode(hash('sha256', $body, true)), 'sse' => 'AES256', 'etag' => '"'.md5($body).'"'];
            if ($this->failPutAfterStore) return Create::rejectionFor(new S3Exception('Lost response', $command, ['response' => new Response(500), 'code' => 'InternalError']));

            return Create::promiseFor(new Result(['ETag' => $this->object['etag']]));
        }
        throw new RuntimeException('Unexpected S3 call in closed fixture: '.$command->getName());
    }

    public function http(RequestInterface $request, array $options)
    {
        $this->probes[] = ['method' => $request->getMethod(), 'url' => (string) $request->getUri(), 'range' => $request->getHeaderLine('Range')];
        if ($this->probeDown) return Create::rejectionFor(new ConnectException('down', $request));

        return Create::promiseFor(new Response($this->probeStatus, ['Content-Range' => $this->probeRange ?? 'bytes 0-0/'.($this->object['bytes'] ?? 0)], 'F'));
    }
}

beforeEach(function (): void {
    $this->s3PreviousContainer = Container::getInstance(); $this->s3PreviousClock = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::parse('2026-10-23 18:00:00', 'UTC'));
    $container = new Container; Container::setInstance($container);
    $this->s3Config = new Repository(['sms-exports' => ['file_publication_enabled' => true, 'file_publication_provider' => 's3',
        's3_publication_bucket' => 'synthetic-sms-published', 's3_publication_region' => 'us-west-1', 's3_publication_prefix' => 'sms-published',
        's3_publication_expected_owner' => null, 's3_link_expiry_days' => 7]]);
    $container->instance('config', $this->s3Config);
    $this->s3Store = new SmsS3PublisherFixtureStore;
    $this->s3Client = fn (array $credentials = ['key' => 'AKIAFIXTUREFIXTURE00', 'secret' => 'fixture-secret-not-real']) => new S3Client(['version' => 'latest',
        'region' => 'us-west-1', 'credentials' => $credentials, 'handler' => fn (CommandInterface $c, RequestInterface $r) => $this->s3Store->s3($c, $r)]);
    $this->s3Http = new Client(['handler' => HandlerStack::create(fn (RequestInterface $r, array $o) => $this->s3Store->http($r, $o))]);
    $this->s3Publisher = new SmsS3RunLinkPublisher(($this->s3Client)(), $this->s3Http);
    $this->s3Id = '11111111-2222-4333-8444-555555555555';
    $this->s3Stream = tmpfile();
    fwrite($this->s3Stream, "First name,debt load,phone1,send date\r\nSynthetic,14000,02025550100,2026-10-26\r\n"); rewind($this->s3Stream);
    $csv = stream_get_contents($this->s3Stream); rewind($this->s3Stream);
    $this->s3Part = ['part' => 1, 'name' => 'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv', 'rows' => 1, 'bytes' => strlen($csv), 'sha256' => hash('sha256', $csv)];
    $this->s3Key = 'sms-published/'.$this->s3Id.'/v2/'.$this->s3Part['name'];
    $this->s3Policy = $this->s3Publisher->policy('2026-10-26');
    $this->s3Publish = fn () => $this->s3Publisher->publish($this->s3Id, $this->s3Part, $this->s3Stream, $this->s3Policy);
});

afterEach(function (): void {
    if (is_resource($this->s3Stream)) fclose($this->s3Stream);
    Carbon::setTestNow($this->s3PreviousClock); Container::setInstance($this->s3PreviousContainer);
});

test('S3 identity and the California send-date expiry policy are pure, stable and make no call', function (): void {
    $identity = $this->s3Publisher->identity();
    expect($this->s3Policy)->toBe(['version' => 1, 'identity' => $identity, 'provider' => 's3', 'send_date' => '2026-10-26',
        'access' => 'presigned-get', 'max_link_seconds' => 604800, 'expires' => '2026-11-03T07:59:59Z']);
    Carbon::setTestNow(Carbon::parse('2026-11-10', 'UTC'));
    $lazy = new SmsS3RunLinkPublisher; // no client is built for identity or policy
    expect($lazy->identity())->toBe($identity)->and($lazy->policy('2026-10-26'))->toBe($this->s3Policy)
        ->and($this->s3Store->calls)->toBe([])->and($this->s3Store->probes)->toBe([]);
    $this->s3Config->set('sms-exports.s3_publication_prefix', 'other-prefix');
    expect($lazy->identity())->not->toBe($identity);
});

test('S3 default off and an incomplete destination fail before any call', function (array $change): void {
    foreach ($change as $key => $value) $this->s3Config->set('sms-exports.'.$key, $value);
    expect(fn () => (new SmsS3RunLinkPublisher)->identity())->toThrow(RuntimeException::class)
        ->and(fn () => ($this->s3Publish)())->toThrow(RuntimeException::class)
        ->and($this->s3Store->calls)->toBe([])->and($this->s3Store->probes)->toBe([]);
})->with([
    [['file_publication_enabled' => false]], [['file_publication_enabled' => 'true']], [['s3_publication_bucket' => null]],
    [['s3_publication_bucket' => 'Has.Dots.bucket']], [['s3_publication_bucket' => 'UPPER']], [['s3_publication_region' => null]],
    [['s3_publication_region' => 'us_west_1']], [['s3_publication_prefix' => '']], [['s3_publication_prefix' => '/lead']],
    [['s3_publication_prefix' => 'a/../b']], [['s3_publication_expected_owner' => '123']], [['s3_link_expiry_days' => null]],
    [['s3_link_expiry_days' => '7']], [['s3_link_expiry_days' => 0]], [['s3_link_expiry_days' => 8]],
]);

test('S3 publishes the exact CSV once, privately, and returns a 7-day link that passes the generic receipt and email rules', function (): void {
    $this->s3Config->set('sms-exports.s3_publication_expected_owner', '123456789012');
    $policy = $this->s3Publisher->policy('2026-10-26');
    $receipt = $this->s3Publisher->publish($this->s3Id, $this->s3Part, $this->s3Stream, $policy);

    expect($this->s3Store->calls)->toBe(['HeadObject', 'PutObject', 'HeadObject', 'HeadObject']);
    expect($this->s3Store->puts)->toBe([['Bucket' => 'synthetic-sms-published', 'Key' => $this->s3Key, 'IfNoneMatch' => '*',
        'ChecksumSHA256' => base64_encode(hex2bin($this->s3Part['sha256'])), 'ServerSideEncryption' => 'AES256', 'ContentLength' => $this->s3Part['bytes'],
        'ContentType' => 'text/csv', 'ExpectedBucketOwner' => '123456789012',
        'Metadata' => ['request-id' => $this->s3Id, 'sha256' => $this->s3Part['sha256']], 'sha256' => $this->s3Part['sha256']]]);
    expect(is_resource($this->s3Stream))->toBeTrue('the caller still owns its stream')->and(ftell($this->s3Stream))->toBe(0);

    expect(array_keys($receipt))->toBe(['version', 'request_id', 'part', 'name', 'url', 'sha256', 'bytes', 'rows', 'remote', 'policy']);
    expect($receipt['remote'])->toBe(['provider' => 's3', 'bucket' => 'synthetic-sms-published', 'region' => 'us-west-1', 'key' => $this->s3Key,
        'etag' => $this->s3Store->object['etag'], 'version_id' => null, 'checksum_sha256' => base64_encode(hex2bin($this->s3Part['sha256'])),
        'link_expires' => '2026-10-30T18:00:00Z']);
    $url = parse_url($receipt['url']); parse_str($url['query'], $query);
    expect($url['scheme'])->toBe('https')->and($url['host'])->toBe('synthetic-sms-published.s3.us-west-1.amazonaws.com')
        ->and(rawurldecode($url['path']))->toBe('/'.$this->s3Key)
        ->and($query['X-Amz-Date'])->toBe('20261023T180000Z')->and($query['X-Amz-Expires'])->toBe('604800')
        ->and($query['X-Amz-SignedHeaders'])->toBe('host')->and($query)->not->toHaveKey('X-Amz-Security-Token');
    expect($receipt['url'])->not->toContain('fixture-secret-not-real');
    expect($this->s3Store->probes)->toBe([['method' => 'GET', 'url' => $receipt['url'], 'range' => 'bytes=0-0']]);

    // The same link is accepted by the publication ledger, the email and the automatic handoff plan.
    $workflow = (new ReflectionClass(SmsRunPublicationWorkflow::class))->newInstanceWithoutConstructor();
    (new ReflectionMethod(SmsRunPublicationWorkflow::class, 'assertReceipt'))->invoke($workflow, $receipt, $this->s3Part, $this->s3Id, $policy);
    (new ReflectionMethod(SmsRunEmailMessage::class, 'url'))->invoke(null, $receipt['url'], ['synthetic-sms-published.s3.us-west-1.amazonaws.com' => true]);
    expect(preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z][a-z0-9-]*\z/D', $url['host']))->toBe(1);
    expect(strlen(json_encode($policy)))->toBeLessThan(65536)->and(strlen($this->s3Publisher->identity()))->toBe(64);
});

test('S3 retries adopt the object that is already there and never upload twice', function (): void {
    $first = ($this->s3Publish)();
    $this->s3Store->calls = []; $this->s3Store->puts = [];
    rewind($this->s3Stream);
    $again = ($this->s3Publish)();
    expect($again)->toBe($first)->and($this->s3Store->calls)->toBe(['HeadObject', 'HeadObject'])->and($this->s3Store->puts)->toBe([]);
});

test('S3 adopts an upload whose answer was lost, and one that another attempt created first', function (): void {
    $this->s3Store->failPutAfterStore = true;
    $receipt = ($this->s3Publish)();
    // The AWS client retries a 500 by itself; the create-only upload makes that retry harmless (it is refused with 412).
    expect($receipt['remote']['etag'])->toBe($this->s3Store->object['etag'])->and(count($this->s3Store->puts))->toBeGreaterThanOrEqual(1)
        ->and(array_unique(array_column($this->s3Store->puts, 'IfNoneMatch')))->toBe(['*']);

    // A second publisher whose first look saw nothing: its create-only upload is refused (412) and it adopts the same object.
    $this->s3Store->failPutAfterStore = false; $this->s3Store->calls = []; $this->s3Store->puts = [];
    $object = $this->s3Store->object; $this->s3Store->object = null; $looks = 0;
    $racing = new SmsS3RunLinkPublisher(new S3Client(['version' => 'latest', 'region' => 'us-west-1',
        'credentials' => ['key' => 'AKIAFIXTUREFIXTURE00', 'secret' => 'fixture-secret-not-real'],
        'handler' => function (CommandInterface $c, RequestInterface $r) use (&$looks, $object) {
            if ($c->getName() === 'HeadObject' && ++$looks === 2) $this->s3Store->object = $object;
            if ($c->getName() === 'PutObject') $this->s3Store->object = $object;

            return $this->s3Store->s3($c, $r);
        }]), $this->s3Http);
    rewind($this->s3Stream);
    expect($racing->publish($this->s3Id, $this->s3Part, $this->s3Stream, $this->s3Policy)['remote'])->toBe($receipt['remote']);
    expect($this->s3Store->calls)->toBe(['HeadObject', 'PutObject', 'HeadObject', 'HeadObject']);
});

test('S3 never guesses an upload that did not arrive', function (): void {
    $this->s3Store->failPut = true;
    expect(fn () => ($this->s3Publish)())->toThrow(RuntimeException::class, 'incomplete or uncertain');
    expect($this->s3Store->object)->toBeNull()->and($this->s3Store->probes)->toBe([]);
});

test('S3 refuses a key that already holds something else, without writing', function (Closure $other): void {
    $this->s3Store->object = ['bytes' => $this->s3Part['bytes'], 'checksum' => base64_encode(hex2bin($this->s3Part['sha256'])), 'sse' => 'AES256', 'etag' => '"abc"'];
    $other($this->s3Store);
    expect(fn () => ($this->s3Publish)())->toThrow(RuntimeException::class, 'different bytes');
    expect($this->s3Store->puts)->toBe([])->and($this->s3Store->probes)->toBe([]);
})->with([
    'other bytes' => [fn ($s) => $s->object['checksum'] = base64_encode(hash('sha256', 'other', true))],
    'other size' => [fn ($s) => $s->object['bytes']++],
    'no checksum stored' => [fn ($s) => $s->mutateHead = function (array &$h): void { unset($h['ChecksumSHA256']); }],
    'not encrypted' => [fn ($s) => $s->object['sse'] = null],
    'delete marker' => [fn ($s) => $s->mutateHead = function (array &$h): void { $h['DeleteMarker'] = true; }],
]);

test('S3 fails before any call when the local CSV is not the approved one or the stream is not at its start', function (): void {
    $changed = ['bytes' => $this->s3Part['bytes'] + 1] + $this->s3Part;
    $changed = ['part' => 1, 'name' => $this->s3Part['name'], 'rows' => 1, 'bytes' => $changed['bytes'], 'sha256' => $this->s3Part['sha256']];
    expect(fn () => $this->s3Publisher->publish($this->s3Id, $changed, $this->s3Stream, $this->s3Policy))->toThrow(RuntimeException::class, 'differ from their manifest');
    fseek($this->s3Stream, 5);
    expect(fn () => ($this->s3Publish)())->toThrow(RuntimeException::class, 'offset zero');
    expect(fn () => $this->s3Publisher->publish('not-a-uuid', $this->s3Part, $this->s3Stream, $this->s3Policy))->toThrow(RuntimeException::class, 'exact approved final CSV');
    expect($this->s3Store->calls)->toBe([])->and($this->s3Store->probes)->toBe([]);
});

test('S3 refuses temporary credentials, because they would end the link early', function (): void {
    $temporary = new SmsS3RunLinkPublisher(($this->s3Client)(['key' => 'ASIAFIXTUREFIXTURE00', 'secret' => 'fixture-secret-not-real', 'token' => 'fixture-session-token']), $this->s3Http);
    expect(fn () => $temporary->publish($this->s3Id, $this->s3Part, $this->s3Stream, $this->s3Policy))->toThrow(RuntimeException::class, 'long-lived access key');
    expect($this->s3Store->calls)->toBe([])->and($this->s3Store->object)->toBeNull();
});

test('S3 link ends at the policy expiry when that is sooner than 7 days, and is never extended by a retry', function (): void {
    $this->s3Config->set('sms-exports.s3_link_expiry_days', 1);
    Carbon::setTestNow(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
    $policy = $this->s3Publisher->policy('2026-10-26');
    expect($policy['expires'])->toBe('2026-10-28T06:59:59Z');
    $receipt = $this->s3Publisher->publish($this->s3Id, $this->s3Part, $this->s3Stream, $policy);
    parse_str(parse_url($receipt['url'], PHP_URL_QUERY), $query);
    expect($receipt['remote']['link_expires'])->toBe('2026-10-28T06:59:59Z')->and((int) $query['X-Amz-Expires'])->toBe(42 * 3600 + 59 * 60 + 59);

    Carbon::setTestNow(Carbon::parse('2026-10-28 06:30:00', 'UTC')); rewind($this->s3Stream);
    $this->s3Store->calls = [];
    expect(fn () => $this->s3Publisher->publish($this->s3Id, $this->s3Part, $this->s3Stream, $policy))->toThrow(InvalidArgumentException::class, 'send date');
    expect($this->s3Store->calls)->toBe([], 'no new link is signed for a past send date');
    Carbon::setTestNow(Carbon::parse('2026-10-28 07:00:00', 'UTC'));
    expect(fn () => $this->s3Publisher->verify($this->s3Id, $this->s3Part, $receipt, $policy))->toThrow(RuntimeException::class, 'expired');
});

test('S3 verification holds when the object, the link or the settings no longer agree with the receipt', function (Closure $break, string $message): void {
    $receipt = ($this->s3Publish)();
    $this->s3Store->calls = []; $this->s3Store->probes = [];
    $this->s3Publisher->verify($this->s3Id, $this->s3Part, $receipt, $this->s3Policy);
    expect($this->s3Store->calls)->toBe(['HeadObject'])->and(count($this->s3Store->probes))->toBe(1);

    $policy = $this->s3Policy;
    $break($this, $receipt, $policy);
    expect(fn () => $this->s3Publisher->verify($this->s3Id, $this->s3Part, $receipt, $policy))->toThrow(RuntimeException::class, $message);
    expect($this->s3Store->puts)->toHaveCount(1, 'verification never writes');
})->with([
    'object replaced' => [fn ($t) => $t->s3Store->object['etag'] = '"replaced"', 'object changed'],
    'object gone' => [fn ($t) => $t->s3Store->object = null, 'missing'],
    'object bytes changed' => [fn ($t) => $t->s3Store->object['checksum'] = base64_encode(hash('sha256', 'x', true)), 'different bytes'],
    'link refused' => [fn ($t) => $t->s3Store->probeStatus = 403, 'does not return the approved file'],
    'link returns another size' => [fn ($t) => $t->s3Store->probeRange = 'bytes 0-0/999', 'does not return the approved file'],
    'link unreachable' => [fn ($t) => $t->s3Store->probeDown = true, 'could not be checked'],
    'link to another host' => [function ($t, array &$r) { $r['url'] = str_replace('synthetic-sms-published.s3', 'other-bucket.s3', $r['url']); }, 'not a link to the approved object'],
    'link to another key' => [function ($t, array &$r) { $r['url'] = str_replace('Part%201', 'Part%202', $r['url']); }, 'not a link to the approved object'],
    'link without a signature' => [function ($t, array &$r) { $r['url'] = preg_replace('/&X-Amz-Signature=[a-f0-9]+/', '', $r['url']); }, 'not a link to the approved object'],
    'link with a longer life' => [function ($t, array &$r) { $r['url'] = str_replace('X-Amz-Expires=604800', 'X-Amz-Expires=604801', $r['url']); }, 'not a link to the approved object'],
    'recorded expiry differs' => [function ($t, array &$r) { $r['remote']['link_expires'] = '2026-10-31T18:00:00Z'; }, 'recorded expiry'],
    'receipt for another part' => [function ($t, array &$r) { $r['part'] = 2; }, 'incomplete or inconsistent'],
    'link expired' => [fn ($t) => Carbon::setTestNow(Carbon::parse('2026-10-30 18:00:01', 'UTC')), 'expired'],
    'bucket setting changed' => [fn ($t) => $t->s3Config->set('sms-exports.s3_publication_bucket', 'another-bucket'), 'policy differs'],
    'policy expired' => [fn ($t) => Carbon::setTestNow(Carbon::parse('2026-11-03 08:00:00', 'UTC')), 'policy differs or has expired'],
]);

test('S3 cannot start a publication after the send date, but a finished receipt still verifies', function (): void {
    $receipt = ($this->s3Publish)();
    Carbon::setTestNow(Carbon::parse('2026-10-27 20:00:00', 'UTC')); rewind($this->s3Stream);
    $this->s3Store->calls = []; $this->s3Store->puts = [];
    expect(fn () => ($this->s3Publish)())->toThrow(InvalidArgumentException::class, 'send date');
    expect($this->s3Store->calls)->toBe([]);
    $this->s3Publisher->verify($this->s3Id, $this->s3Part, $receipt, $this->s3Policy);
    expect($this->s3Store->calls)->toBe(['HeadObject']);
});

test('S3 pins the object version in the link when the bucket keeps versions', function (): void {
    $this->s3Store->versionId = 'fixtureVersion.123';
    $receipt = ($this->s3Publish)();
    parse_str(parse_url($receipt['url'], PHP_URL_QUERY), $query);
    expect($receipt['remote']['version_id'])->toBe('fixtureVersion.123')->and($query['versionId'])->toBe('fixtureVersion.123');
    $this->s3Store->versionId = 'anotherVersion';
    expect(fn () => $this->s3Publisher->verify($this->s3Id, $this->s3Part, $receipt, $this->s3Policy))->toThrow(RuntimeException::class, 'object changed');
});

test('the publisher is chosen by the provider setting, and an unknown provider fails', function (): void {
    $container = Container::getInstance();
    $provider = new Cmd\Reports\ReportsServiceProvider($container);
    $provider->register();
    expect($container->make(Cmd\Reports\Services\SmsRunLinkPublisher::class))->toBeInstanceOf(SmsS3RunLinkPublisher::class);
    $this->s3Config->set('sms-exports.file_publication_provider', 'dropbox');
    expect($container->make(Cmd\Reports\Services\SmsRunLinkPublisher::class))->toBeInstanceOf(Cmd\Reports\Services\SmsDropboxRunLinkPublisher::class);
    $this->s3Config->set('sms-exports.file_publication_provider', null);
    expect(fn () => $container->make(Cmd\Reports\Services\SmsRunLinkPublisher::class))->toThrow(RuntimeException::class, 'Unknown SMS file publication provider');
});
