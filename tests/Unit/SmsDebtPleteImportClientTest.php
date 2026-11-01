<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDebtPleteImportClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\EasyHandle;
use GuzzleHttp\Psr7\FnStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

beforeEach(function (): void {
    $this->previousContainer = Container::getInstance();
    $container = new Container;
    Container::setInstance($container);
    $this->secret = str_repeat('synthetic_fixture_', 3);
    $this->config = new Repository(['sms-exports' => [
        'debtplete_import_enabled' => true,
        'debtplete_import_origin' => 'https://receiver.example.test',
        'debtplete_import_allowed_hosts' => ['receiver.example.test'],
        'debtplete_import_tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'debtplete_import_client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
        'debtplete_import_api_key' => $this->secret,
    ]]);
    $container->instance('config', $this->config);
    $this->history = [];
    $this->mock = new MockHandler;
    $mock = $this->mock;
    $stack = HandlerStack::create(static function (RequestInterface $request, array $options) use ($mock) {
        // MockHandler's built-in sink path stringifies the complete response.
        // This closed adapter exercises incremental header/write callbacks instead.
        $sink = $options['sink'] ?? null;
        $onHeaders = $options['on_headers'] ?? null;
        unset($options['sink'], $options['on_headers']);
        return $mock($request, $options)->then(static function (ResponseInterface $response) use ($sink, $onHeaders): ResponseInterface {
            $body = $response->getBody();
            try {
                if ($onHeaders !== null) $onHeaders($response);
                if ($sink === null) return $response;
                $copied = 0;
                while (!$body->eof()) {
                    $fragment = $body->read(min(65536, 1048577 - $copied));
                    if ($fragment === '' && !$body->eof()) throw new RuntimeException('Synthetic response made no progress.');
                    $copied += strlen($fragment);
                    $sink->write($fragment);
                }
                $sink->rewind();
                return $response->withBody($sink);
            } finally { if ($sink !== null) $body->close(); }
        });
    });
    $stack->push(Middleware::history($this->history));
    $this->statsCalls = 0;
    $this->http = new Client(['handler' => $stack,
        'headers' => ['Cookie' => 'injected-cookie', 'Authorization' => 'Basic injected', 'X-Telemetry' => 'injected'],
        'auth' => ['injected', 'password'], 'verify' => false, 'allow_redirects' => true, 'http_errors' => true,
        'query' => ['injected' => 'private-default'], 'json' => ['injected' => 'wrong-body'],
        'curl' => [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPAUTH => CURLAUTH_ANY],
        'on_stats' => function (): void { ++$this->statsCalls; },
    ]);
    $this->client = new SmsDebtPleteImportClient($this->http);
    $this->id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $this->receipt = ['request_id' => $this->id, 'state' => 'queued', 'processed' => 0, 'total' => 1, 'parts' => []];
    $this->payload = ['request_id' => $this->id, 'options' => ['send_date' => '2000-01-13']];
    $this->response = fn (int $status = 202): Response => new Response($status, ['Content-Type' => 'application/json'], json_encode($this->receipt, JSON_THROW_ON_ERROR));
});

afterEach(function (): void {
    Container::setInstance($this->previousContainer);
});

test('construction and identity are pure and never expose runtime credentials', function (): void {
    expect($this->history)->toBe([])->and($this->client->identity())->toBe([
        'protocol' => 'debtplete-sms-import-v1', 'origin' => 'https://receiver.example.test',
        'tenant_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'client_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
    ])->and($this->history)->toBe([]);
    $identity = json_encode($this->client->identity(), JSON_THROW_ON_ERROR);
    expect($identity)->not->toContain($this->secret)->not->toContain('api_key')->not->toContain('Authorization');
});

test('registers once at the exact pinned URL with TLS and closed request options and retains original dates', function (): void {
    $this->mock->append(($this->response)());
    expect($this->client->register($this->payload))->toBe($this->receipt)->and($this->history)->toHaveCount(1);
    $call = $this->history[0];
    expect($call['request']->getMethod())->toBe('POST')
        ->and((string) $call['request']->getUri())->toBe('https://receiver.example.test/api/v1/sms-imports/runs/'.$this->id)
        ->and($call['request']->getHeaderLine('Authorization'))->toBe('Bearer cccccccc-cccc-4ccc-8ccc-cccccccccccc.'.$this->secret)
        ->and($call['request']->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and($call['request']->hasHeader('Cookie'))->toBeFalse()->and($call['request']->hasHeader('X-Telemetry'))->toBeFalse()
        ->and(json_decode((string) $call['request']->getBody(), true))->toBe($this->payload)
        ->and($call['options']['verify'])->toBeTrue()->and($call['options']['allow_redirects'])->toBeFalse()
        ->and($call['options']['http_errors'])->toBeFalse()->and($call['options']['cookies'])->toBeFalse()
        ->and($call['options']['debug'])->toBeFalse()->and($call['options']['stream'])->toBeFalse()
        ->and($call['options']['timeout'])->toBe(60)->and($call['options']['connect_timeout'])->toBe(15)
        ->and($call['options']['_curl_retries'])->toBe(2)
        ->and($call['options']['curl'])->toBe([CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true])
        ->and(isset($call['options']['auth']))->toBeFalse()->and(isset($call['options']['on_stats']))->toBeFalse()
        ->and($this->statsCalls)->toBe(0);
});

test('uploads exact caller-owned bytes with the sealed plan header and never closes the source', function (): void {
    $bytes = "\"First name\",\"debt load\",phone1,\"send date\"\r\nÉlodie,60000,0555123456,2000-01-13\r\n";
    $stream = fopen('php://memory', 'w+b'); fwrite($stream, $bytes); rewind($stream);
    $observed = null;
    $this->mock->append(function ($request) use (&$observed): Response {
        $observed = (string) $request->getBody();
        $request->getBody()->close();
        return ($this->response)();
    });
    try {
        expect($this->client->upload($this->id, 1, str_repeat('a', 64), $stream))->toBe($this->receipt)
            ->and(is_resource($stream))->toBeTrue()->and($observed)->toBe($bytes);
        rewind($stream);
        expect(stream_get_contents($stream))->toBe($bytes);
        $call = $this->history[0];
        expect($call['request']->getMethod())->toBe('PUT')
            ->and($call['request']->getUri()->getPath())->toBe('/api/v1/sms-imports/runs/'.$this->id.'/parts/1')
            ->and($call['request']->getHeaderLine('X-SMS-Import-Plan-SHA256'))->toBe(str_repeat('a', 64))
            ->and($call['request']->getHeaderLine('Content-Type'))->toBe('text/csv')
            ->and($call['options']['timeout'])->toBe(600);
    } finally { if (is_resource($stream)) fclose($stream); }
});

test('performs a single exact GET status request and permits key rotation without changing destination identity', function (): void {
    $identity = $this->client->identity();
    $this->mock->append(($this->response)(200), ($this->response)(200));
    $this->client->status($this->id);
    $rotated = str_repeat('rotated_fixture_', 3);
    $this->config->set('sms-exports.debtplete_import_api_key', $rotated);
    expect($this->client->status($this->id))->toBe($this->receipt)->and($this->client->identity())->toBe($identity)
        ->and($this->history)->toHaveCount(2)
        ->and($this->history[1]['request']->getMethod())->toBe('GET')
        ->and((string) $this->history[1]['request']->getBody())->toBe('')
        ->and($this->history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer cccccccc-cccc-4ccc-8ccc-cccccccccccc.'.$rotated);
});

test('refuses invalid destination and credential configuration before any HTTP request', function (string $key, mixed $value): void {
    $this->config->set('sms-exports.'.$key, $value);
    expect(fn () => $this->client->identity())->toThrow(RuntimeException::class)
        ->and(fn () => $this->client->register($this->payload))->toThrow(RuntimeException::class)
        ->and($this->history)->toBe([]);
})->with([
    'disabled' => ['debtplete_import_enabled', false], 'integer enabled' => ['debtplete_import_enabled', 1],
    'string enabled' => ['debtplete_import_enabled', 'true'], 'missing origin' => ['debtplete_import_origin', null],
    'plain HTTP' => ['debtplete_import_origin', 'http://receiver.example.test'],
    'uppercase host' => ['debtplete_import_origin', 'https://Receiver.example.test'],
    'userinfo' => ['debtplete_import_origin', 'https://user:pass@receiver.example.test'],
    'port' => ['debtplete_import_origin', 'https://receiver.example.test:443'],
    'path' => ['debtplete_import_origin', 'https://receiver.example.test/'],
    'query' => ['debtplete_import_origin', 'https://receiver.example.test?q=x'],
    'fragment' => ['debtplete_import_origin', 'https://receiver.example.test#x'],
    'IP' => ['debtplete_import_origin', 'https://127.0.0.1'],
    'IPv6' => ['debtplete_import_origin', 'https://[::1]'],
    'empty label' => ['debtplete_import_origin', 'https://receiver..test'],
    'trailing dot' => ['debtplete_import_origin', 'https://receiver.example.test.'],
    'unsafe label' => ['debtplete_import_origin', 'https://-receiver.example.test'],
    'wildcard' => ['debtplete_import_allowed_hosts', ['*']],
    'allow-all-like wildcard' => ['debtplete_import_allowed_hosts', ['*.example.test']],
    'empty allowlist' => ['debtplete_import_allowed_hosts', []],
    'foreign host' => ['debtplete_import_allowed_hosts', ['other.example.test']],
    'uppercase allowlist' => ['debtplete_import_allowed_hosts', ['Receiver.example.test']],
    'associative allowlist' => ['debtplete_import_allowed_hosts', ['allowed' => 'receiver.example.test']],
    'object allowlist entry' => ['debtplete_import_allowed_hosts', [new stdClass]],
    'duplicate allowlist' => ['debtplete_import_allowed_hosts', ['receiver.example.test', 'receiver.example.test']],
    'wrong tenant' => ['debtplete_import_tenant_id', 'tenant-alias'],
    'uppercase client' => ['debtplete_import_client_id', 'CCCCCCCC-CCCC-4CCC-8CCC-CCCCCCCCCCCC'],
    'missing key' => ['debtplete_import_api_key', null],
    'short key' => ['debtplete_import_api_key', 'short'],
    'long key' => ['debtplete_import_api_key', str_repeat('a', 129)],
    'dot in key' => ['debtplete_import_api_key', str_repeat('a', 32).'.suffix'],
    'header injection' => ['debtplete_import_api_key', str_repeat('a', 32)."\r\nInjected: secret"],
]);

test('pins origin and owner between operations and rejects configuration changes during serialization', function (): void {
    $this->client->identity();
    $this->config->set('sms-exports.debtplete_import_origin', 'https://other.example.test');
    $this->config->set('sms-exports.debtplete_import_allowed_hosts', ['other.example.test']);
    expect(fn () => $this->client->status($this->id))->toThrow(RuntimeException::class, 'identity changed');
    expect($this->history)->toBe([]);
});

test('binds credentials and destination across JSON serialization without issuing a request', function (): void {
    $payload = $this->payload;
    $payload['serialization'] = new class($this->config) implements JsonSerializable {
        public function __construct(private Repository $configuration) {}
        public function jsonSerialize(): mixed { $this->configuration->set('sms-exports.debtplete_import_api_key', str_repeat('changed_', 6)); return []; }
    };
    expect(fn () => $this->client->register($payload))->toThrow(RuntimeException::class, 'unconfirmed');
    expect($this->history)->toBe([]);
});

test('rejects a destination change during the HTTP call and never sends a second request', function (): void {
    $this->mock->append(function (): Response {
        $this->config->set('sms-exports.debtplete_import_client_id', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd');
        return ($this->response)();
    });
    expect(fn () => $this->client->register($this->payload))->toThrow(RuntimeException::class, 'unconfirmed');
    expect($this->history)->toHaveCount(1);
});

test('rejects invalid upload parameters without consuming or closing caller bytes', function (): void {
    $stream = fopen('php://memory', 'w+b'); fwrite($stream, 'unchanged'); rewind($stream);
    try {
        foreach ([['invalid', 1, str_repeat('a', 64)], [$this->id, 0, str_repeat('a', 64)],
            [$this->id, 1001, str_repeat('a', 64)], [$this->id, 1, str_repeat('A', 64)]] as [$id, $part, $sha]) {
            expect(fn () => $this->client->upload($id, $part, $sha, $stream))->toThrow(RuntimeException::class, 'input is invalid');
        }
        fseek($stream, 1);
        expect(fn () => $this->client->upload($this->id, 1, str_repeat('a', 64), $stream))->toThrow(RuntimeException::class, 'input is invalid');
        expect(ftell($stream))->toBe(1)->and(is_resource($stream))->toBeTrue()->and($this->history)->toBe([]);
    } finally { fclose($stream); }
});

test('refuses unexpected HTTP outcomes without retrying or exposing raw error bodies', function (int $status): void {
    $this->mock->append(new Response($status, ['Location' => 'https://unapproved.example.test'], 'private '.$this->secret), ($this->response)());
    try { $this->client->register($this->payload); test()->fail('Unexpected HTTP status was accepted.'); }
    catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain($this->secret)->not->toContain('private')
            ->and($exception->getCode())->toBe($status)
            ->and($exception->getPrevious())->toBeNull();
    }
    expect($this->history)->toHaveCount(1)->and($this->mock->count())->toBe(1);
})->with([200, 201, 204, 301, 302, 400, 401, 403, 404, 409, 422, 429, 500]);

test('requires 200 for status rather than accepting an asynchronous acknowledgement', function (): void {
    $this->mock->append(($this->response)(202));
    expect(fn () => $this->client->status($this->id))->toThrow(RuntimeException::class, 'unconfirmed');
});

test('rejects non-object or malformed JSON responses', function (string $json): void {
    $this->mock->append(new Response(202, [], $json));
    expect(fn () => $this->client->register($this->payload))->toThrow(RuntimeException::class, 'unconfirmed');
    expect($this->history)->toHaveCount(1);
})->with(['[]', 'null', 'true', '1', '"string"', '{broken', str_repeat('{"deep":', 33).'0'.str_repeat('}', 33)]);

test('bounds an unknown-length response to one MiB plus one detection byte and closes it', function (): void {
    $read = 0;
    $body = new PumpStream(function (int $length) use (&$read): string { $read += $length; return str_repeat('x', $length); });
    $this->mock->append(new Response(202, [], $body));
    expect(fn () => $this->client->register($this->payload))->toThrow(RuntimeException::class, 'unconfirmed');
    expect($read)->toBeLessThanOrEqual(1048577)->and($body->eof())->toBeTrue();
});

test('rejects oversized announced responses before reading any body bytes', function (): void {
    $read = 0;
    $body = new PumpStream(function (int $length) use (&$read): string { ++$read; return str_repeat('x', $length); });
    $this->mock->append(new Response(202, ['Content-Length' => '1048577'], $body));
    expect(fn () => $this->client->register($this->payload))->toThrow(RuntimeException::class, 'unconfirmed');
    expect($read)->toBe(0)->and($body->eof())->toBeTrue();
});

test('sanitizes transport and stream failures without previous exceptions or automatic retries', function (bool $readFailure): void {
    $leak = 'private payload '.$this->secret;
    $failure = $readFailure
        ? new Response(202, [], new PumpStream(static function () use ($leak): never { throw new RuntimeException($leak); }))
        : new ConnectException($leak, new Request('POST', 'https://receiver.example.test/private', ['Authorization' => $this->secret]));
    $this->mock->append($failure, ($this->response)());
    try { $this->client->register($this->payload); test()->fail('Failure was not rejected.'); }
    catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain($this->secret)->not->toContain('private payload')
            ->and($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBeNull();
    }
    expect($this->history)->toHaveCount(1)->and($this->mock->count())->toBe(1);
})->with([false, true]);

test('exposes only a confirmed safe GET error status for same-request recovery', function (int $status): void {
    $this->mock->append(new Response($status, [], 'private '.$this->secret));
    try { $this->client->status($this->id); test()->fail('Status error was accepted.'); }
    catch (RuntimeException $exception) {
        expect($exception->getCode())->toBe($status)->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain($this->secret)->not->toContain('private');
    }
    expect($this->history)->toHaveCount(1);
})->with([404, 409]);

test('redacts payload and runtime credential arguments from its transport trace frames', function (): void {
    $previous = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');
    $this->mock->append(new ConnectException('raw private '.$this->secret, new Request('GET', 'https://receiver.example.test')));
    $payload = $this->payload + ['synthetic_private_marker' => 'do-not-log-the-manifest'];
    try {
        $this->client->register($payload);
        test()->fail('Transport failure was accepted.');
    } catch (RuntimeException $exception) {
        $trace = var_export(array_filter($exception->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? null) === SmsDebtPleteImportClient::class), true);
        expect($trace)->not->toContain($this->secret)->not->toContain('do-not-log-the-manifest')
            ->and($exception->getPrevious())->toBeNull();
    } finally { ini_set('zend.exception_ignore_args', $previous); }
});

test('uses the real default cURL factory for a large resource without stringifying or transferring it', function (): void {
    if (!extension_loaded('curl')) test()->markTestSkipped('Native cURL is required by the production transport.');
    $method = new ReflectionMethod(SmsDebtPleteImportClient::class, 'defaultClient');
    $transport = $method->invoke(new SmsDebtPleteImportClient());
    $handler = (new ReflectionProperty(HandlerStack::class, 'handler'))->getValue($transport->getConfig('handler'));
    expect($handler)->toBeInstanceOf(CurlHandler::class);
    $factory = new class implements CurlFactoryInterface {
        public bool $configured = false;
        public int $bytes = 0;
        public int $growth = 0;
        private CurlFactory $native;
        public function __construct() { $this->native = new CurlFactory(0); }
        public function create(RequestInterface $request, array $options): EasyHandle
        {
            $body = FnStream::decorate($request->getBody(), ['__toString' => static function (): never { throw new RuntimeException('The large source must not become a string.'); }]);
            $before = memory_get_usage(true);
            $easy = $this->native->create($request->withBody($body), $options);
            $this->configured = true;
            $this->growth = memory_get_usage(true) - $before;
            $this->bytes = $body->getSize();
            expect($options['stream'])->toBeFalse()->and($options['_curl_retries'])->toBe(2)
                ->and($request->getProtocolVersion())->toBe('1.1')
                ->and($easy->sink)->toBe($options['sink'])
                ->and($options['curl'])->toBe([CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true]);
            $this->release($easy);
            // CurlHandler cannot reach curl_exec: configuration stops here.
            throw new RuntimeException('Synthetic closed boundary before curl_exec.');
        }
        public function release(EasyHandle $easy): void { $this->native->release($easy); }
    };
    (new ReflectionProperty(CurlHandler::class, 'factory'))->setValue($handler, $factory);
    $client = new SmsDebtPleteImportClient($transport);
    $stream = tmpfile();
    try {
        expect(fseek($stream, 128 * 1024 * 1024 - 1))->toBe(0);
        fwrite($stream, "\0"); rewind($stream);
        expect(fn () => $client->upload($this->id, 1, str_repeat('a', 64), $stream))->toThrow(RuntimeException::class, 'unconfirmed');
        expect($factory->configured)->toBeTrue()->and($factory->bytes)->toBe(128 * 1024 * 1024)
            ->and($factory->growth)->toBeLessThan(8 * 1024 * 1024)
            ->and(is_resource($stream))->toBeTrue()->and(fstat($stream)['size'])->toBe(128 * 1024 * 1024);
    } finally { if (is_resource($stream)) fclose($stream); }
});

test('disables the installed cURL factory automatic failed-rewind resend branch', function (int $errno): void {
    if (!extension_loaded('curl')) test()->markTestSkipped('Native cURL is required by the production transport.');
    $factory = new CurlFactory(0);
    $easy = $factory->create(new Request('POST', 'https://receiver.example.test', ['Content-Length' => '4'], 'body'),
        ['_curl_retries' => 2, 'verify' => true]);
    $easy->errno = $errno;
    $retries = 0;
    try {
        CurlFactory::finish(static function () use (&$retries) { ++$retries; throw new RuntimeException('A second transfer is forbidden.'); }, $easy, $factory)->wait();
        test()->fail('An absent response was incorrectly accepted.');
    } catch (GuzzleHttp\Exception\RequestException) {
        expect($retries)->toBe(0);
    }
})->with(['no response' => 0, 'failed rewind' => 65]);
