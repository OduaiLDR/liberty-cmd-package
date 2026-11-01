<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use RuntimeException;
use SensitiveParameter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use stdClass;
use Throwable;

/** Single-attempt transport. The workflow owns approved bytes and reconciliation. */
final class SmsDebtPleteImportClient implements SmsDebtPleteImportGateway
{
    private const MAX_RESPONSE_BYTES = 1048576;
    private const MAX_PARTS = 1000;
    private ?array $pinnedIdentity = null;

    public function __construct(private ?ClientInterface $client = null) {}

    /** Pure destination identity; credentials never enter the frozen workflow plan. */
    public function identity(): array
    {
        return $this->publicIdentity($this->settings());
    }

    public function register(#[SensitiveParameter] array $payload): array
    {
        $settings = $this->settings();
        $id = $payload['request_id'] ?? null;
        if (! $this->uuid($id)) throw $this->invalidInput();
        try { $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
        catch (Throwable) { throw $this->invalidInput(); }
        if (strlen($body) > self::MAX_RESPONSE_BYTES) throw $this->invalidInput();

        return $this->request($settings, 'POST', '/api/v1/sms-imports/runs/'.$id, 202, 60, 'application/json', $body);
    }

    /** Caller retains the readable source resource. Its bytes are never rewritten. */
    public function upload(string $requestId, int $part, string $planSha256, #[SensitiveParameter] mixed $stream): array
    {
        $settings = $this->settings();
        if (! $this->uuid($requestId) || $part < 1 || $part > self::MAX_PARTS
            || preg_match('/\A[a-f0-9]{64}\z/D', $planSha256) !== 1
            || ! is_resource($stream) || get_resource_type($stream) !== 'stream') throw $this->invalidInput();
        $metadata = stream_get_meta_data($stream);
        if (! $metadata['seekable'] || (! str_contains($metadata['mode'], 'r') && ! str_contains($metadata['mode'], '+'))
            || ftell($stream) !== 0) throw $this->invalidInput();
        $borrowed = Utils::streamFor($stream);
        $body = FnStream::decorate($borrowed, ['close' => static function (): void {}]);
        try {
            return $this->request($settings, 'PUT', '/api/v1/sms-imports/runs/'.$requestId.'/parts/'.$part,
                202, 600, 'text/csv', $body, ['X-SMS-Import-Plan-SHA256' => $planSha256]);
        } finally {
            $borrowed->detach();
        }
    }

    public function status(string $requestId): array
    {
        $settings = $this->settings();
        if (! $this->uuid($requestId)) throw $this->invalidInput();

        return $this->request($settings, 'GET', '/api/v1/sms-imports/runs/'.$requestId, 200, 60, null, '');
    }

    private function request(#[SensitiveParameter] array $settings, string $method, string $path, int $accepted, int $timeout, ?string $type, #[SensitiveParameter] mixed $body, array $extra = []): array
    {
        $responseBody = null;
        $sink = null;
        $httpStatus = 0;
        try {
            if ($this->settings() !== $settings) throw new RuntimeException;
            $headers = ['Accept' => 'application/json', 'Accept-Encoding' => 'identity',
                'Authorization' => 'Bearer '.$settings['client_id'].'.'.$settings['api_key']] + $extra;
            if ($type !== null) $headers['Content-Type'] = $type;
            $request = new Request($method, $settings['origin'].$path, $headers, $body);
            $this->client ??= $this->defaultClient();
            if ($this->settings() !== $settings) throw new RuntimeException;
            $sink = $this->responseSink();
            $response = $this->client->send($request, [
                'headers' => null, 'auth' => null, 'cookies' => false, 'debug' => false,
                'query' => null, 'base_uri' => null, 'body' => null, 'json' => null, 'form_params' => null, 'multipart' => null,
                'on_stats' => null, 'progress' => null, 'sink' => $sink, 'proxy' => '', 'version' => '1.1',
                '_curl_retries' => 2, 'curl' => [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true],
                'on_headers' => function (ResponseInterface $response) use ($settings, $accepted, &$httpStatus): void {
                    if ($this->settings() !== $settings) throw new RuntimeException;
                    $status = $response->getStatusCode();
                    if ($status < 200) return;
                    if ($status !== $accepted) { $httpStatus = $status; throw new RuntimeException; }
                    $this->responseLength($response);
                },
                'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                'stream' => false, 'decode_content' => false, 'connect_timeout' => 15, 'timeout' => $timeout,
            ]);
            $responseBody = $response->getBody();
            if ($this->settings() !== $settings) throw new RuntimeException;
            if ($response->getStatusCode() !== $accepted) {
                $httpStatus = $response->getStatusCode();
                throw new RuntimeException;
            }
            $this->responseLength($response);
            $sink->rewind();
            $json = '';
            while (! $sink->eof() && strlen($json) <= self::MAX_RESPONSE_BYTES) {
                $fragment = $sink->read(min(65536, self::MAX_RESPONSE_BYTES + 1 - strlen($json)));
                if ($fragment === '' && ! $sink->eof()) throw new RuntimeException;
                $json .= $fragment;
            }
            if (strlen($json) > self::MAX_RESPONSE_BYTES) throw new RuntimeException;
            $object = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
            if (! $object instanceof stdClass) throw new RuntimeException;
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || $this->settings() !== $settings) throw new RuntimeException;

            return $data;
        } catch (Throwable) {
            throw new RuntimeException('SMS DebtPlete import outcome is unconfirmed; reconcile before another request.', $httpStatus);
        } finally {
            try { $responseBody?->close(); } catch (Throwable) {}
            try { $sink?->close(); } catch (Throwable) {}
        }
    }

    private function defaultClient(): ClientInterface
    {
        if (! extension_loaded('curl')) throw new RuntimeException;
        return new Client(['handler' => HandlerStack::create(new CurlHandler())]);
    }

    private function responseLength(ResponseInterface $response): void
    {
        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && (preg_match('/\A[0-9]+\z/D', $length) !== 1 || strlen($length) > 7 || (int) $length > self::MAX_RESPONSE_BYTES)) {
            throw new RuntimeException;
        }
    }

    private function responseSink(): StreamInterface
    {
        $buffer = Utils::streamFor(fopen('php://memory', 'w+b'));
        $received = 0;
        return FnStream::decorate($buffer, ['write' => static function (#[SensitiveParameter] string $bytes) use ($buffer, &$received): int {
            $remaining = self::MAX_RESPONSE_BYTES + 1 - $received;
            $accepted = substr($bytes, 0, $remaining);
            $written = $buffer->write($accepted);
            $received += $written;
            if ($written !== strlen($accepted) || strlen($bytes) > $remaining || $received > self::MAX_RESPONSE_BYTES) throw new RuntimeException;
            return $written;
        }]);
    }

    private function settings(): array
    {
        $origin = config('sms-exports.debtplete_import_origin');
        $allowed = config('sms-exports.debtplete_import_allowed_hosts');
        $tenant = config('sms-exports.debtplete_import_tenant_id');
        $client = config('sms-exports.debtplete_import_client_id');
        $key = config('sms-exports.debtplete_import_api_key');
        $host = is_string($origin) && str_starts_with($origin, 'https://') ? substr($origin, 8) : null;
        if (config('sms-exports.debtplete_import_enabled', false) !== true
            || ! $this->host($host) || $origin !== 'https://'.$host
            || ! is_array($allowed) || ! array_is_list($allowed) || $allowed === []
            || array_filter($allowed, fn ($value): bool => ! $this->host($value)) !== []
            || count(array_unique($allowed)) !== count($allowed)
            || ! in_array($host, $allowed, true) || ! $this->uuid($tenant) || ! $this->uuid($client)
            || ! is_string($key) || preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $key) !== 1) {
            throw new RuntimeException('SMS DebtPlete import is disabled or destination configuration is invalid.');
        }

        $settings = ['origin' => $origin, 'allowed_hosts' => $allowed, 'tenant_id' => $tenant, 'client_id' => $client, 'api_key' => $key];
        $identity = $this->publicIdentity($settings);
        if ($this->pinnedIdentity !== null && $identity !== $this->pinnedIdentity) {
            throw new RuntimeException('SMS DebtPlete import destination identity changed; reconcile before another request.');
        }
        $this->pinnedIdentity ??= $identity;

        return $settings;
    }

    private function publicIdentity(array $settings): array
    {
        return ['protocol' => 'debtplete-sms-import-v1', 'origin' => $settings['origin'],
            'tenant_id' => $settings['tenant_id'], 'client_id' => $settings['client_id']];
    }

    private function host(mixed $host): bool
    {
        if (! is_string($host) || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A[a-z0-9.-]+\z/D', $host) !== 1) return false;
        $labels = explode('.', $host);
        if (count($labels) < 2 || preg_match('/\A[a-z][a-z0-9-]*\z/D', end($labels)) !== 1) return false;
        foreach ($labels as $label) {
            if (strlen($label) < 1 || strlen($label) > 63 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) return false;
        }

        return true;
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    private function invalidInput(): RuntimeException
    {
        return new RuntimeException('SMS DebtPlete import input is invalid; no request was made.');
    }
}
