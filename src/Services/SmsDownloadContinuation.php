<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** One-use delegated download. Upstream session credentials never leave encrypted server-side cache. */
final class SmsDownloadContinuation
{
    public const TTL = 60;
    public const ATTRIBUTE = 'cmd_sms_download_continuation';
    private const PURPOSE = 'sms-run-download-v1';

    public function __construct(private SmsRunService $runs) {}

    /** Called only after the host's fresh session and current download-permission middleware. */
    public function issue(Request $request, string $requestId): array
    {
        if (config('sms-exports.download_continuation_enabled', false) !== true) {
            throw new HttpException(503, 'Secure download continuation is not enabled.');
        }
        $this->authenticatedRuntime();
        $actor = $request->attributes->get('cmd_user');
        if ($request->attributes->get('cmd_session_fresh') !== true || ! SmsAccess::authenticated($actor)) {
            throw new HttpException(401, 'Sign in again before requesting a download.');
        }
        if (! SmsAccess::canDownload($actor)) throw new HttpException(403, 'Missing SMS download permission.');
        $cookie = $request->headers->get('Cookie', '');
        if (! is_string($cookie) || $cookie === '' || strlen($cookie) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $cookie)) {
            throw new HttpException(401, 'A fresh browser session is required for the download.');
        }
        $id = $this->requestId($requestId);
        // Preserve the existing status and artifact gates; no alternate file lookup or generation.
        $existing = $this->runs->downloadUrl($id);
        $origin = $this->origin($existing);
        $artifact = $this->artifact($this->runs->filePath($id));
        $issuedAt = now()->timestamp;
        $expires = now()->setTimestamp($issuedAt + self::TTL);
        $relative = URL::temporarySignedRoute('cmd.sms_run.download_continue', $expires, ['requestId' => $id], false);
        $this->relative($relative, $id);
        $grant = bin2hex(random_bytes(32));
        $payload = ['version' => 1, 'purpose' => self::PURPOSE, 'method' => 'POST', 'request_id' => $id,
            'relative_sha256' => hash('sha256', $relative), 'issued_at' => $issuedAt, 'expires_at' => $expires->timestamp,
            'actor_sha256' => $this->actorHash($actor), 'session_sha256' => hash('sha256', $cookie),
            'cookie' => $cookie, 'artifact' => $artifact];
        try {
            $cache = $this->cache();
            $encrypted = $this->crypto()->encrypt(json_encode($payload, JSON_THROW_ON_ERROR), false);
            $lock = $cache->getStore()->lock($this->key($grant).':consume', 10);
            if (! $lock instanceof Lock || ! $lock->get()) throw new HttpException(503, 'Secure download coordination is unavailable.');
            try {
                if (! is_string($encrypted) || ! $cache->add($this->key($grant), $encrypted, self::TTL)) {
                    throw new HttpException(503, 'Secure download preparation is unavailable.');
                }
            } finally { $lock->release(); }
        } catch (HttpException $error) { throw $error; }
        catch (Throwable) { throw new HttpException(503, 'Secure download preparation is unavailable.'); }

        return ['method' => 'POST', 'url' => $origin.$relative, 'fields' => ['grant' => $grant]];
    }

    /** Consume before any identity call or file read. A failed redemption always needs a newly issued grant. */
    public function consume(Request $request, string $requestId): array
    {
        $this->authenticatedRuntime();
        $id = $this->requestId($requestId);
        if (! $request->isMethod('POST')) throw new HttpException(405, 'Use the download continuation POST.');
        $fields = $request->request->all();
        if (array_keys($fields) !== ['grant'] || ! is_string($fields['grant']) || ! preg_match('/\A[a-f0-9]{64}\z/', $fields['grant'])) {
            throw new HttpException(400, 'Invalid download continuation.');
        }
        $relative = $request->getRequestUri();
        $this->relative($relative, $id);
        // Defense in depth for direct service calls as well as the route's signed:relative guard.
        if (! URL::hasValidSignature($request, false)) throw new HttpException(403, 'Invalid or expired download signature.');
        $grant = $fields['grant'];
        try {
            $cache = $this->cache();
            $lock = $cache->getStore()->lock($this->key($grant).':consume', 10);
            if (! $lock instanceof Lock) throw new HttpException(503, 'Secure download coordination is unavailable.');
            if (! $lock->get()) throw new HttpException(409, 'This download continuation is already being redeemed.');
            try {
                $encrypted = $cache->get($this->key($grant));
                if (! is_string($encrypted) || $encrypted === '') throw new HttpException(410, 'Download continuation expired or already used. Request a new download.');
                // Independent atomic fence: even a delayed cache operation that outlives
                // the mutex lease may never authorize a second response for this grant.
                if (! $cache->add($this->key($grant).':used', true, self::TTL + 10)) throw new HttpException(410, 'Download continuation already used. Request a new download.');
                if (! $cache->forget($this->key($grant))) throw new HttpException(503, 'Secure download consumption could not be confirmed.');
            } finally { $lock->release(); }
            $text = $this->crypto()->decrypt($encrypted, false);
            if (! is_string($text)) throw new HttpException(410, 'Invalid download continuation.');
            $payload = json_decode($text, true, 24, JSON_THROW_ON_ERROR);
        } catch (HttpException $error) { throw $error; }
        catch (Throwable) { throw new HttpException(503, 'Secure download redemption is unavailable.'); }
        if (! is_array($payload) || ($payload['version'] ?? null) !== 1 || ($payload['purpose'] ?? null) !== self::PURPOSE
            || ($payload['method'] ?? null) !== 'POST' || ($payload['request_id'] ?? null) !== $id
            || ($payload['relative_sha256'] ?? null) !== hash('sha256', $relative)
            || ! is_int($payload['issued_at'] ?? null) || ! is_int($payload['expires_at'] ?? null)
            || $payload['issued_at'] > now()->timestamp || $payload['expires_at'] <= now()->timestamp
            || $payload['expires_at'] - $payload['issued_at'] !== self::TTL
            || ! is_string($payload['cookie'] ?? null) || $payload['cookie'] === '' || strlen($payload['cookie']) > 16384
            || preg_match('/[\x00-\x1f\x7f]/', $payload['cookie'])
            || ($payload['session_sha256'] ?? null) !== hash('sha256', $payload['cookie'])
            || ! is_string($payload['actor_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $payload['actor_sha256'])
            || ! is_array($payload['artifact'] ?? null)) {
            throw new HttpException(410, 'Download continuation does not match this request.');
        }
        return $payload;
    }

    /** This actor must come from fresh VerifyCmdSession, never the grant's issue-time permission list. */
    public function assertActor(Request $request, string $requestId): void
    {
        $payload = $this->receipt($request, $requestId);
        $actor = $request->attributes->get('cmd_user');
        if ($request->attributes->get('cmd_session_fresh') !== true || ! SmsAccess::authenticated($actor)) throw new HttpException(401, 'Sign in again to download.');
        if (! SmsAccess::canDownload($actor)) throw new HttpException(403, 'Missing SMS download permission.');
        if (! hash_equals($payload['actor_sha256'], $this->actorHash($actor))) throw new HttpException(403, 'The download session actor changed. Request a new download.');
    }

    /** Pin the file selected by the existing download handler, without accepting a client file path. */
    public function assertArtifact(Request $request, string $requestId, string $path): void
    {
        $payload = $this->receipt($request, $requestId);
        if ($this->artifact($path) !== $payload['artifact']) throw new HttpException(409, 'The file changed after the download was requested. Request a new download.');
    }

    private function receipt(Request $request, string $requestId): array
    {
        $payload = $request->attributes->get(self::ATTRIBUTE);
        if (! is_array($payload) || ($payload['request_id'] ?? null) !== $this->requestId($requestId)
            || ($payload['method'] ?? null) !== 'POST' || ($payload['purpose'] ?? null) !== self::PURPOSE
            || ($payload['relative_sha256'] ?? null) !== hash('sha256', $request->getRequestUri())) throw new HttpException(403, 'No verified download continuation.');
        return $payload;
    }

    private function artifact(string $path): array
    {
        clearstatcache(true, $path);
        $real = realpath($path);
        if (! is_string($real) || ! is_file($path) || is_link($path) || ! is_readable($path)) throw new HttpException(409, 'The verified download file is unavailable.');
        $bytes = filesize($path); $sha = hash_file('sha256', $path);
        if (! is_int($bytes) || $bytes < 1 || ! is_string($sha)) throw new HttpException(409, 'The verified download file is unavailable.');
        return ['path' => $real, 'bytes' => $bytes, 'sha256' => $sha];
    }

    private function actorHash(array $actor): string
    {
        $email = $actor['email'] ?? null; $username = $actor['username'] ?? null;
        if (! is_string($email) || $email === '' || trim($email) !== $email || strlen($email) > 320 || preg_match('/[\x00-\x1f\x7f]/', $email)
            || ($username !== null && (! is_string($username) || strlen($username) > 320 || preg_match('/[\x00-\x1f\x7f]/', $username)))) {
            throw new HttpException(401, 'A stable authenticated download actor is required.');
        }
        return hash('sha256', json_encode(['email' => strtolower($email), 'username' => $username], JSON_THROW_ON_ERROR));
    }

    private function requestId(string $id): string
    {
        $id = strtolower($id);
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $id)) throw new HttpException(404, 'SMS run not found.');
        return $id;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new HttpException(503, 'Secure download origin is unavailable.');
        }
        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if ($origin !== rtrim((string) config('app.url'), '/')) throw new HttpException(503, 'Secure download origin differs from the runner.');
        return $origin;
    }

    private function relative(string $relative, string $id): void
    {
        $parts = parse_url($relative);
        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['fragment'])
            || ($parts['path'] ?? null) !== '/api/cmd/mail-drop-export-report/runs/'.$id.'/download' || empty($parts['query'])) {
            throw new HttpException(403, 'Download continuation path differs.');
        }
    }

    private function cache(): Repository
    {
        $cache = Cache::store();
        if (! $cache instanceof Repository || ! $cache->getStore() instanceof LockProvider || $cache->getStore() instanceof NullStore
            || ($cache->getStore() instanceof ArrayStore && ! app()->environment('testing'))) throw new HttpException(503, 'A shared lock-capable download cache is required.');
        return $cache;
    }

    private function crypto(): Encrypter
    {
        $crypto = app('encrypter');
        if (! $crypto instanceof Encrypter) throw new HttpException(503, 'Secure download encryption is unavailable.');
        return $crypto;
    }

    private function authenticatedRuntime(): void
    {
        if (config('services.cmd.auth_enabled', true) !== true || app()->environment('local')) throw new HttpException(503, 'Authenticated download continuation runtime is required.');
    }

    private function key(string $grant): string { return 'sms:download-continuation:'.hash('sha256', $grant); }
}
