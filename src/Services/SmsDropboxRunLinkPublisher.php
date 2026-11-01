<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Carbon\Carbon;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

/**
 * Explicit, create-only Dropbox publisher. Construction/status inspection performs no I/O.
 * Mutating timeouts are reconciled by deterministic path/link lookup, never blindly retried.
 * Uploads are relative to the pinned folder ID (Dropbox API support documents this
 * syntax and automatic child-folder creation). A concurrent external rename can
 * still invalidate the expected path; post-upload verification holds such a run.
 */
final class SmsDropboxRunLinkPublisher implements SmsRunLinkPublisher
{
    private const SINGLE_LIMIT = 157286400;
    private const CHUNK_BYTES = 8388608;
    private const MAX_FILE_BYTES = 2199019061248;
    private ?string $cachedToken = null;
    private int $tokenExpiresAt = 0;
    private ?string $operationIdentity = null;
    private ?string $operationToken = null;
    private ?string $operationSendDate = null;
    private ?string $operationExpiry = null;

    public function __construct(private ?ClientInterface $client = null, private readonly ?Closure $tokenProvider = null) {}

    public function identity(): string
    {
        return hash('sha256', $this->json($this->settings()));
    }

    /** Stable calendar-derived expiry; never extend it to compensate for a delayed retry. */
    public function policy(string $sendDate): array
    {
        SmsDeliveryPolicy::plannedDate($sendDate);
        $settings = $this->settings();
        $expires = (new DateTimeImmutable($sendDate.' 23:59:59', new DateTimeZone('America/Los_Angeles')))
            ->modify('+'.$settings['expiry_days'].' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        return ['version' => 1, 'identity' => hash('sha256', $this->json($settings)), 'approval_sha256' => $settings['approval_sha256'],
            'send_date' => $sendDate, 'audience' => $settings['audience'], 'access' => 'viewer',
            'allow_download' => true, 'require_password' => false, 'expires' => $expires];
    }

    public function publish(string $requestId, array $part, mixed $stream, array $policy): array
    {
        [$settings, $path] = $this->context($requestId, $part, $policy);
        SmsDeliveryPolicy::assertNotPast($policy['send_date']);
        if (! is_resource($stream) || ftell($stream) !== 0) throw new RuntimeException('Dropbox publication requires the exact CSV stream at offset zero.');
        $hash = SmsDropboxContentHash::stream($stream);
        if ($hash['bytes'] !== $part['bytes'] || ! hash_equals($part['sha256'], $hash['sha256'])) {
            throw new RuntimeException('The approved CSV bytes differ from their manifest; no Dropbox request was made.');
        }
        $this->destination($settings);
        $metadata = $this->metadata($path);
        if ($metadata === null) {
            try {
                $this->upload($path, $part, $stream, $hash['content_hash']);
            } catch (Throwable) {
                // A lost finish/upload response may already have committed exactly this path.
                $metadata = $this->metadata($path);
                if ($metadata === null) throw new RuntimeException('Dropbox upload is incomplete or uncertain; retry only through publication reconciliation.');
            }
            $metadata ??= $this->metadata($path);
        }
        $remote = $this->file($metadata, $settings, $path, $part, $hash['content_hash']);
        // Detect destination movement/replacement before exposing any new shared link.
        $this->folder($settings);
        $link = $this->link($remote, $part, $policy);
        $receipt = ['version' => 1, 'request_id' => $requestId, 'part' => $part['part'], 'name' => $part['name'], 'url' => $link['url'], 'sha256' => $part['sha256'], 'bytes' => $part['bytes'],
            'rows' => $part['rows'], 'remote' => $remote, 'policy' => $policy];
        $this->verify($requestId, $part, $receipt, $policy);
        return $receipt;
    }

    public function verify(string $requestId, array $part, array $receipt, array $policy): void
    {
        [$settings, $path] = $this->context($requestId, $part, $policy);
        if (array_keys($receipt) !== ['version', 'request_id', 'part', 'name', 'url', 'sha256', 'bytes', 'rows', 'remote', 'policy']
            || $receipt['version'] !== 1 || $receipt['request_id'] !== $requestId || $receipt['part'] !== $part['part']
            || $receipt['name'] !== $part['name'] || $receipt['sha256'] !== $part['sha256']
            || $receipt['bytes'] !== $part['bytes'] || $receipt['rows'] !== $part['rows'] || $receipt['policy'] !== $policy
            || ! is_array($receipt['remote']) || ! $this->sha($receipt['remote']['content_hash'] ?? null)) {
            throw new RuntimeException('The saved Dropbox publication receipt is incomplete or inconsistent.');
        }
        $this->safeUrl($receipt['url']);
        $this->destination($settings);
        $remote = $this->file($this->metadata($path), $settings, $path, $part, $receipt['remote']['content_hash']);
        if ($remote !== $receipt['remote']) throw new RuntimeException('The saved Dropbox file identity/revision changed; publication is held.');
        $byId = $this->file($this->metadata($remote['id']), $settings, $path, $part, $remote['content_hash']);
        if ($byId !== $remote) throw new RuntimeException('The pinned Dropbox file and path no longer resolve to the same approved content.');
        [$status, $link] = $this->rpc('sharing/get_shared_link_metadata', ['url' => $receipt['url']]);
        $this->success($status);
        $this->linkMetadata($link, $remote, $part, $policy);
        if ($link['url'] !== $receipt['url']) throw new RuntimeException('The saved Dropbox shared link changed; publication is held.');
    }

    private function settings(): array
    {
        if (config('sms-exports.file_publication_enabled', false) !== true) throw new RuntimeException('SMS external file publication is not enabled.');
        $settings = ['provider' => 'dropbox', 'account_id' => config('sms-exports.dropbox_account_id'),
            'namespace_id' => config('sms-exports.dropbox_namespace_id'), 'folder_id' => config('sms-exports.dropbox_folder_id'),
            'folder_path' => config('sms-exports.dropbox_folder_path'), 'approval_sha256' => config('sms-exports.dropbox_policy_sha256'),
            'audience' => config('sms-exports.dropbox_link_audience'), 'expiry_days' => config('sms-exports.dropbox_link_expiry_days')];
        if (! is_string($settings['account_id']) || ! preg_match('/^dbid:[a-zA-Z0-9_-]{1,150}$/D', $settings['account_id'])
            || ! is_string($settings['namespace_id']) || ! preg_match('/^[1-9][0-9]{0,39}$/D', $settings['namespace_id'])
            || ! $this->fileId($settings['folder_id']) || ! $this->folderPath($settings['folder_path'])
            || ! $this->sha($settings['approval_sha256']) || ! in_array($settings['audience'], ['public', 'no_one'], true)
            || ! is_int($settings['expiry_days']) || $settings['expiry_days'] < 1 || $settings['expiry_days'] > 90) {
            throw new RuntimeException('Configure the explicit Dropbox account, namespace, existing folder and accepted link/expiry policy before publication.');
        }
        return $settings;
    }

    private function context(string $id, array $part, array $policy): array
    {
        $settings = $this->settings();
        $this->operationIdentity = hash('sha256', $this->json($settings));
        $this->operationToken = null;
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)
            || array_keys($part) !== ['part', 'name', 'rows', 'bytes', 'sha256'] || ! is_int($part['part'])
            || $part['part'] < 1 || $part['part'] > SmsRunArtifactBundle::MAX_PARTS || ! is_int($part['rows']) || $part['rows'] < 1
            || $part['rows'] > SmsRunOptions::MAX_TARGET || ! is_int($part['bytes']) || $part['bytes'] < 1
            || $part['bytes'] > self::MAX_FILE_BYTES || ! $this->sha($part['sha256']) || ! is_string($part['name'])
            || ! preg_match('/^(SMS Export - .+) - Part ([1-9][0-9]*) of ([1-9][0-9]*)\.csv$/D', $part['name'], $m)
            || (int) $m[2] !== $part['part'] || (int) $m[3] < $part['part'] || (int) $m[3] > SmsRunArtifactBundle::MAX_PARTS
            || ($parsed = SmsRunArtifactFilename::parseStem($m[1])) === null || ! SmsRunArtifactFilename::isDropRange($parsed['drop_name_range'])) {
            throw new RuntimeException('Dropbox publication requires the exact approved final CSV part receipt.');
        }
        if ($policy !== $this->policy($parsed['send_date']) || strcmp($policy['expires'], Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z')) <= 0) {
            throw new RuntimeException('The frozen Dropbox policy differs or has expired; it cannot be extended by retry.');
        }
        $this->operationSendDate = $policy['send_date'];
        $this->operationExpiry = $policy['expires'];
        return [$settings, $settings['folder_path'].'/'.$id.'/v2/'.$part['name']];
    }

    private function destination(array $settings): void
    {
        [$status, $account] = $this->rpc('users/get_current_account', null, false);
        $this->success($status);
        if (($account['account_id'] ?? null) !== $settings['account_id'] || ($account['disabled'] ?? null) !== false
            || ($account['email_verified'] ?? null) !== true) throw new RuntimeException('Dropbox account identity/verification differs from the approved destination.');
        $this->folder($settings);
    }

    private function folder(array $settings): void
    {
        $folder = $this->metadata($settings['folder_id']);
        if ($folder === null || ($folder['.tag'] ?? null) !== 'folder' || ($folder['id'] ?? null) !== $settings['folder_id']
            || ($folder['path_lower'] ?? null) !== strtolower($settings['folder_path'])
            || ($folder['sharing_info']['read_only'] ?? false) === true || ($folder['sharing_info']['no_access'] ?? false) === true
            || ($folder['sharing_info']['traverse_only'] ?? false) === true) {
            throw new RuntimeException('The approved Dropbox base folder changed or is not writable; publication is held.');
        }
    }

    private function metadata(string $path): ?array
    {
        [$status, $body] = $this->rpc('files/get_metadata', ['path' => $path, 'include_deleted' => false]);
        if ($status === 409 && ($body['error']['.tag'] ?? null) === 'path' && ($body['error']['path']['.tag'] ?? null) === 'not_found') return null;
        $this->success($status);
        return $body;
    }

    private function file(?array $metadata, array $settings, string $path, array $part, string $hash): array
    {
        if ($metadata === null || ($metadata['.tag'] ?? null) !== 'file' || ! $this->fileId($metadata['id'] ?? null)
            || ! is_string($metadata['rev'] ?? null) || ! preg_match('/^[a-f0-9]{9,100}$/D', $metadata['rev'])
            || ($metadata['name'] ?? null) !== $part['name'] || ($metadata['path_lower'] ?? null) !== strtolower($path)
            || ($metadata['size'] ?? null) !== $part['bytes'] || ($metadata['content_hash'] ?? null) !== $hash
            || ($metadata['is_downloadable'] ?? null) !== true) {
            throw new RuntimeException('Dropbox file content or identity differs from the approved CSV; nothing was overwritten.');
        }
        return ['provider' => 'dropbox', 'account_id' => $settings['account_id'], 'namespace_id' => $settings['namespace_id'],
            'folder_id' => $settings['folder_id'], 'folder_path' => $settings['folder_path'], 'id' => $metadata['id'],
            'rev' => $metadata['rev'], 'path' => $metadata['path_lower'], 'content_hash' => $hash];
    }

    private function upload(string $path, array $part, mixed $stream, string $hash): void
    {
        $settings = $this->settings();
        $suffix = substr($path, strlen($settings['folder_path']));
        // Official Dropbox API support: ID-relative uploads follow the existing
        // parent even if another actor replaces the old human-readable path.
        // https://community.dropbox.com/en/discussion/256136/sending-folder-ids-instead-of-folder-path-in-apis/p1
        $commit = ['path' => $settings['folder_id'].$suffix, 'mode' => 'add', 'autorename' => false, 'mute' => true, 'strict_conflict' => true];
        if ($part['bytes'] <= self::SINGLE_LIMIT) {
            [$status] = $this->content('files/upload', $commit + ['content_hash' => $hash], $stream);
            $this->success($status);
            return;
        }
        // Sequential sessions: no parallel/batch semantics or implicit HTTP retries.
        [$status, $start] = $this->content('files/upload_session/start', ['close' => false, 'session_type' => 'sequential',
            'content_hash' => SmsDropboxContentHash::bytes('')], '');
        $this->success($status);
        $session = $start['session_id'] ?? null;
        if (! is_string($session) || strlen($session) > 512 || ! preg_match('/^[a-zA-Z0-9:_-]+$/D', $session)) throw new RuntimeException('Dropbox returned an invalid upload session receipt.');
        for ($offset = 0; $offset < $part['bytes'];) {
            $length = min(self::CHUNK_BYTES, $part['bytes'] - $offset);
            $chunk = '';
            while (strlen($chunk) < $length) {
                $bytes = fread($stream, $length - strlen($chunk));
                if ($bytes === false || $bytes === '') throw new RuntimeException('The approved CSV became unreadable during upload.');
                $chunk .= $bytes;
            }
            [$status] = $this->content('files/upload_session/append_v2', ['cursor' => ['session_id' => $session, 'offset' => $offset],
                'close' => false, 'content_hash' => SmsDropboxContentHash::bytes($chunk)], $chunk);
            $this->success($status); $offset += $length;
        }
        // content_hash here covers this empty final request, not the whole session.
        // Whole-file integrity is checked independently against get_metadata below.
        [$status] = $this->content('files/upload_session/finish', ['cursor' => ['session_id' => $session, 'offset' => $offset],
            'commit' => $commit, 'content_hash' => SmsDropboxContentHash::bytes('')], '');
        $this->success($status);
    }

    private function link(array $remote, array $part, array $policy): array
    {
        $existing = $this->links($remote, $part, $policy);
        if ($existing !== null) return $existing;
        try {
            [$status, $link] = $this->rpc('sharing/create_shared_link_with_settings', ['path' => $remote['id'], 'settings' => [
                'audience' => $policy['audience'], 'access' => 'viewer', 'allow_download' => true,
                'require_password' => false, 'expires' => $policy['expires']]]);
            if ($status === 200) {
                $this->linkMetadata($link, $remote, $part, $policy);
                return $link;
            }
            if ($status !== 409 || ($link['error']['.tag'] ?? null) !== 'shared_link_already_exists') $this->success($status);
        } catch (Throwable) {
            // Reconcile once; never issue a second create or modify settings automatically.
            $existing = $this->links($remote, $part, $policy);
            if ($existing !== null) return $existing;
            throw new RuntimeException('Dropbox link creation is incomplete or uncertain; reconcile before retry.');
        }
        return $this->links($remote, $part, $policy)
            ?? throw new RuntimeException('Dropbox reported an existing link but no matching direct approved link could be verified.');
    }

    private function links(array $remote, array $part, array $policy): ?array
    {
        [$status, $result] = $this->rpc('sharing/list_shared_links', ['path' => $remote['id'], 'direct_only' => true]);
        $this->success($status);
        if (($result['has_more'] ?? null) !== false || ! is_array($result['links'] ?? null) || ! array_is_list($result['links']) || count($result['links']) > 1) {
            throw new RuntimeException('Dropbox direct-link results are ambiguous; publication is held.');
        }
        if ($result['links'] === []) return null;
        $this->linkMetadata($result['links'][0], $remote, $part, $policy);
        return $result['links'][0];
    }

    private function linkMetadata(array $link, array $remote, array $part, array $policy): void
    {
        $permissions = $link['link_permissions'] ?? null;
        $audience = $permissions['effective_audience']['.tag'] ?? null;
        // Older owner metadata may expose resolved_visibility instead. Public is
        // unambiguous; never infer no_one from team/shared-folder-only visibility.
        if ($audience === null && ($permissions['resolved_visibility']['.tag'] ?? null) === 'public') $audience = 'public';
        if (($link['.tag'] ?? null) !== 'file' || ($link['id'] ?? null) !== $remote['id'] || ($link['rev'] ?? null) !== $remote['rev']
            || ($link['name'] ?? null) !== $part['name'] || ($link['path_lower'] ?? null) !== $remote['path']
            || ($link['size'] ?? null) !== $part['bytes'] || ($link['expires'] ?? null) !== $policy['expires']
            || ! is_array($permissions) || $audience !== $policy['audience']
            || ($permissions['link_access_level']['.tag'] ?? null) !== 'viewer' || ($permissions['allow_download'] ?? null) !== true
            || ($permissions['require_password'] ?? null) !== false) {
            throw new RuntimeException('Dropbox shared-link identity or effective permissions differ from the approved policy.');
        }
        $this->safeUrl($link['url'] ?? null);
    }

    private function safeUrl(mixed $url): void
    {
        if (! is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url)) throw new RuntimeException('Dropbox returned an unsafe shared link.');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'www.dropbox.com'
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ! preg_match('~^/(?:s/|scl/fi/)[a-zA-Z0-9_-]+/~D', $parts['path'] ?? '')) throw new RuntimeException('Dropbox returned an unexpected shared-link origin or path.');
    }

    private function rpc(string $route, ?array $arguments, bool $rooted = true): array
    {
        return $this->request('https://api.dropboxapi.com/2/'.$route, ['headers' => ['Content-Type' => 'application/json'],
            'body' => $arguments === null ? 'null' : $this->json($arguments)], $rooted);
    }

    private function content(string $route, array $arguments, mixed $body): array
    {
        // Guzzle must not close the caller-owned CSV resource when its request is destroyed.
        $wrapper = is_resource($body) ? \GuzzleHttp\Psr7\Utils::streamFor($body) : null;
        try {
            return $this->request('https://content.dropboxapi.com/2/'.$route, ['headers' => [
                'Content-Type' => 'application/octet-stream', 'Dropbox-API-Arg' => json_encode($arguments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)], 'body' => $wrapper ?? $body], true);
        } finally { $wrapper?->detach(); }
    }

    private function request(string $url, array $options, bool $rooted): array
    {
        $settings = $this->settings();
        if (hash('sha256', $this->json($settings)) !== $this->operationIdentity) throw new RuntimeException('Dropbox destination policy changed during publication; retry only by reconciliation.');
        $this->operationToken ??= $this->token();
        // A token callback/refresh must not change the rooted destination while obtaining credentials.
        if (hash('sha256', $this->json($this->settings())) !== $this->operationIdentity) throw new RuntimeException('Dropbox destination policy changed while obtaining credentials.');
        if (strcmp((string) $this->operationExpiry, Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z')) <= 0) throw new RuntimeException('The frozen Dropbox link policy expired during publication.');
        if (str_contains($url, '/files/upload') || str_ends_with($url, '/sharing/create_shared_link_with_settings')) {
            // Hashing/token refresh may cross California midnight. No new remote
            // bytes or sharing grants start for an already-past planned day.
            SmsDeliveryPolicy::assertNotPast((string) $this->operationSendDate);
        }
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer '.$this->operationToken];
        if ($rooted) $options['headers']['Dropbox-API-Path-Root'] = $this->json(['.tag' => 'namespace_id', 'namespace_id' => $settings['namespace_id']]);
        $options += ['allow_redirects' => false, 'http_errors' => false, 'timeout' => 120, 'connect_timeout' => 15];
        $this->client ??= new Client;
        try { $response = $this->client->request('POST', $url, $options); }
        catch (Throwable) { throw new RuntimeException('Dropbox transport outcome is uncertain; reconcile publication before another write.'); }
        $status = $response->getStatusCode();
        $stream = $response->getBody(); $json = '';
        while (! $stream->eof() && strlen($json) <= 1048576) {
            $chunk = $stream->read(min(65536, 1048577 - strlen($json)));
            if ($chunk === '' && ! $stream->eof()) throw new RuntimeException('Dropbox response could not be read completely; publication is held.');
            $json .= $chunk;
        }
        if (strlen($json) > 1048576) throw new RuntimeException('Dropbox returned an oversized response; publication is held.');
        if ($json === '' && $status === 200) return [$status, []];
        try { $body = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('Dropbox returned an invalid response; publication is held.'); }
        if (! is_array($body)) throw new RuntimeException('Dropbox returned an invalid response; publication is held.');
        return [$status, $body];
    }

    private function token(): string
    {
        if ($this->tokenProvider !== null) {
            try { return $this->validToken(($this->tokenProvider)()); }
            catch (Throwable) { throw new RuntimeException('Dropbox credential provider failed; no publication request was made.'); }
        }
        $static = config('sms-exports.dropbox_access_token');
        if (is_string($static) && $static !== '') return $this->validToken($static);
        if ($this->cachedToken !== null && $this->tokenExpiresAt > time() + 60) return $this->cachedToken;
        $refresh = config('sms-exports.dropbox_refresh_token'); $key = config('sms-exports.dropbox_app_key'); $secret = config('sms-exports.dropbox_app_secret');
        foreach ([$refresh, $key, $secret] as $value) if (! is_string($value) || trim($value) === '') throw new RuntimeException('Configure Dropbox offline credentials before publication.');
        $this->client ??= new Client;
        try {
            $response = $this->client->request('POST', 'https://api.dropboxapi.com/oauth2/token', ['form_params' => [
                'grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $key, 'client_secret' => $secret],
                'allow_redirects' => false, 'http_errors' => false, 'timeout' => 30, 'connect_timeout' => 15]);
            if ($response->getStatusCode() !== 200) throw new RuntimeException;
            $json = $response->getBody()->read(65537);
            if (strlen($json) > 65536) throw new RuntimeException;
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (! is_int($data['expires_in'] ?? null) || $data['expires_in'] < 120 || $data['expires_in'] > 86400) throw new RuntimeException;
            $this->cachedToken = $this->validToken($data['access_token'] ?? null);
            $this->tokenExpiresAt = time() + $data['expires_in'];
            return $this->cachedToken;
        } catch (Throwable) { throw new RuntimeException('Dropbox token refresh did not complete; no publication request was made.'); }
    }

    private function validToken(mixed $token): string
    {
        if (! is_string($token) || $token === '' || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) throw new RuntimeException('Dropbox access-token configuration is invalid.');
        return $token;
    }
    private function success(int $status): void
    {
        if ($status !== 200) throw new RuntimeException('Dropbox request did not succeed (HTTP '.$status.'); reconcile before retry.');
    }
    private function folderPath(mixed $path): bool
    {
        if (! is_string($path) || strlen($path) > 1000 || ! str_starts_with($path, '/') || $path === '/'
            || str_ends_with($path, '/') || preg_match('/[^\x20-\x7e]|[\\\\:]/', $path)) return false;
        foreach (explode('/', substr($path, 1)) as $segment) if ($segment === '' || $segment === '.' || $segment === '..' || trim($segment) !== $segment) return false;
        return true;
    }
    private function fileId(mixed $id): bool { return is_string($id) && preg_match('/^id:[a-zA-Z0-9_-]{1,150}$/D', $id) === 1; }
    private function sha(mixed $hash): bool { return is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1; }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
}
