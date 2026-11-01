<?php

declare(strict_types=1);

use Carbon\Carbon;
use Cmd\Reports\Services\SmsDropboxContentHash;
use Cmd\Reports\Services\SmsDropboxRunLinkPublisher;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Psr\Http\Message\RequestInterface;

/** Closed HTTP transport: unknown origins, routes or request shapes always fail. */
final class SmsDropboxPublisherFixtureHttp
{
    public array $calls = [];
    public ?array $file = null;
    public array $links = [];
    public ?Closure $mutate = null;
    public ?Closure $afterAccount = null;
    public ?Closure $override = null;
    public bool $uploadTimeout = false;
    public bool $linkTimeout = false;
    public bool $failUpload = false;
    public bool $failFinishAfterCommit = false;
    public bool $failAppend = false;
    public bool $hasMore = false;
    public int $uploads = 0;
    public int $creates = 0;
    public int $sessionBytes = 0;
    public mixed $sessionSha = null;
    public array $chunkSizes = [];
    public string $account = 'dbid:fixtureAccount';
    public string $folderId = 'id:fixtureFolder';
    public string $folderPath = '/Synthetic SMS';
    public string $contentHash;
    public string $requestId = '11111111-2222-4333-8444-555555555555';
    public array $part;
    public array $policy;

    public function reply(RequestInterface $request, array $options): Response
    {
        $host = $request->getUri()->getHost(); $route = $request->getUri()->getPath();
        if ($request->getMethod() !== 'POST' || ! in_array($host, ['api.dropboxapi.com', 'content.dropboxapi.com'], true)
            || $request->getUri()->getScheme() !== 'https') throw new RuntimeException('Unexpected HTTP destination in fixture.');
        if ($route === '/oauth2/token') {
            parse_str((string) $request->getBody(), $params);
            expect($params)->toBe(['grant_type' => 'refresh_token', 'refresh_token' => 'fixture-refresh', 'client_id' => 'fixture-key', 'client_secret' => 'fixture-secret']);
            $this->calls[] = ['route' => $route];
            return $this->json(['access_token' => 'fixture-access', 'expires_in' => 14400]);
        }
        expect($request->getHeaderLine('Authorization'))->toBe('Bearer fixture-access');
        if ($route !== '/2/users/get_current_account') {
            expect(json_decode($request->getHeaderLine('Dropbox-API-Path-Root'), true))->toBe(['.tag' => 'namespace_id', 'namespace_id' => '12345']);
        }
        $arguments = $host === 'content.dropboxapi.com'
            ? json_decode($request->getHeaderLine('Dropbox-API-Arg'), true, 16, JSON_THROW_ON_ERROR)
            : json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $this->calls[] = ['route' => $route, 'arguments' => $arguments];
        if ($this->override !== null && ($response = ($this->override)($route, $arguments)) instanceof Response) return $response;
        if ($route === '/2/users/get_current_account') {
            if ($this->afterAccount !== null) ($this->afterAccount)();
            return $this->json(['account_id' => $this->account, 'email_verified' => true, 'disabled' => false]);
        }
        if ($route === '/2/files/get_metadata') {
            if ($arguments['path'] === 'id:fixtureFolder') return $this->json(['.tag' => 'folder', 'id' => $this->folderId, 'path_lower' => strtolower($this->folderPath)]);
            if (! in_array($arguments['path'], [$this->path(), 'id:fixtureFile'], true)) throw new RuntimeException('Unexpected metadata path.');
            return $this->file === null ? $this->json(['error' => ['.tag' => 'path', 'path' => ['.tag' => 'not_found']]], 409) : $this->json($this->file);
        }
        if ($route === '/2/files/upload') {
            $this->uploads++; $body = (string) $request->getBody();
            expect($arguments)->toBe(['path' => $this->uploadPath(), 'mode' => 'add', 'autorename' => false, 'mute' => true, 'strict_conflict' => true, 'content_hash' => $this->contentHash])
                ->and(strlen($body))->toBe($this->part['bytes'])->and(hash('sha256', $body))->toBe($this->part['sha256']);
            if ($this->failUpload) throw new ConnectException('fixture secret transport failure', $request);
            $this->file = $this->metadata();
            if ($this->uploadTimeout) throw new ConnectException('fixture secret response lost after commit', $request);
            return $this->json($this->file);
        }
        if ($route === '/2/files/upload_session/start') {
            $this->uploads++; $this->sessionBytes = 0; $this->sessionSha = hash_init('sha256');
            expect((string) $request->getBody())->toBe('')->and($arguments)->toBe(['close' => false, 'session_type' => 'sequential', 'content_hash' => hash('sha256', '')]);
            return $this->json(['session_id' => 'pid_upload_session:fixture']);
        }
        if ($route === '/2/files/upload_session/append_v2') {
            if ($this->failAppend) throw new ConnectException('fixture secret append timeout', $request);
            $body = (string) $request->getBody(); $this->chunkSizes[] = strlen($body);
            expect($arguments['cursor'])->toBe(['session_id' => 'pid_upload_session:fixture', 'offset' => $this->sessionBytes])
                ->and($arguments['close'])->toBeFalse()->and($arguments['content_hash'])->toBe(SmsDropboxContentHash::bytes($body))
                ->and(strlen($body))->toBeLessThanOrEqual(8388608);
            $this->sessionBytes += strlen($body); hash_update($this->sessionSha, $body);
            return new Response(200, [], '');
        }
        if ($route === '/2/files/upload_session/finish') {
            expect($arguments['cursor'])->toBe(['session_id' => 'pid_upload_session:fixture', 'offset' => $this->part['bytes']])
                ->and($arguments['commit'])->toBe(['path' => $this->uploadPath(), 'mode' => 'add', 'autorename' => false, 'mute' => true, 'strict_conflict' => true])
                ->and($arguments['content_hash'])->toBe(hash('sha256', ''))->and((string) $request->getBody())->toBe('')
                ->and($this->sessionBytes)->toBe($this->part['bytes'])->and(hash_final($this->sessionSha))->toBe($this->part['sha256']);
            $this->file = $this->metadata();
            if ($this->failFinishAfterCommit) throw new ConnectException('fixture secret finish response lost', $request);
            return $this->json($this->file);
        }
        if ($route === '/2/sharing/list_shared_links') {
            expect($arguments)->toBe(['path' => 'id:fixtureFile', 'direct_only' => true]);
            return $this->json(['links' => $this->links, 'has_more' => $this->hasMore]);
        }
        if ($route === '/2/sharing/create_shared_link_with_settings') {
            $this->creates++;
            expect($arguments)->toBe(['path' => 'id:fixtureFile', 'settings' => ['audience' => $this->policy['audience'],
                'access' => 'viewer', 'allow_download' => true, 'require_password' => false, 'expires' => $this->policy['expires']]]);
            $this->links = [$this->link()];
            if ($this->linkTimeout) throw new ConnectException('fixture secret link response lost', $request);
            return $this->json($this->links[0]);
        }
        if ($route === '/2/sharing/get_shared_link_metadata') {
            expect($arguments)->toBe(['url' => $this->link()['url']]);
            return $this->json($this->links[0]);
        }
        throw new RuntimeException('Unexpected Dropbox route in closed fixture.');
    }

    public function metadata(): array
    {
        return ['.tag' => 'file', 'id' => 'id:fixtureFile', 'rev' => 'abcdef012345', 'name' => $this->part['name'],
            'path_lower' => strtolower($this->path()), 'size' => $this->part['bytes'], 'content_hash' => $this->contentHash, 'is_downloadable' => true];
    }
    public function link(): array
    {
        $link = ['.tag' => 'file', 'id' => 'id:fixtureFile', 'rev' => 'abcdef012345', 'name' => $this->part['name'],
            'path_lower' => strtolower($this->path()), 'size' => $this->part['bytes'], 'expires' => $this->policy['expires'],
            'url' => 'https://www.dropbox.com/scl/fi/fixture-link/'.rawurlencode($this->part['name']).'?rlkey=fixture-only&dl=0',
            'link_permissions' => ['effective_audience' => ['.tag' => $this->policy['audience']], 'link_access_level' => ['.tag' => 'viewer'],
                'allow_download' => true, 'require_password' => false]];
        if ($this->mutate !== null) ($this->mutate)($link);
        return $link;
    }
    public function path(): string { return '/Synthetic SMS/'.$this->requestId.'/v2/'.$this->part['name']; }
    public function uploadPath(): string { return 'id:fixtureFolder/'.$this->requestId.'/v2/'.$this->part['name']; }
    private function json(array $body, int $status = 200): Response { return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)); }
}

beforeEach(function (): void {
    $this->dropboxPreviousContainer = Container::getInstance(); $this->dropboxPreviousClock = Carbon::getTestNow();
    Carbon::setTestNow(Carbon::parse('2026-10-23 18:00:00', 'UTC'));
    $container = new Container; Container::setInstance($container);
    $this->dropboxConfig = new Repository(['sms-exports' => ['file_publication_enabled' => true,
        'dropbox_account_id' => 'dbid:fixtureAccount', 'dropbox_namespace_id' => '12345', 'dropbox_folder_id' => 'id:fixtureFolder',
        'dropbox_folder_path' => '/Synthetic SMS', 'dropbox_policy_sha256' => str_repeat('a', 64),
        'dropbox_link_audience' => 'public', 'dropbox_link_expiry_days' => 7]]);
    $container->instance('config', $this->dropboxConfig);
    $this->dropboxHttp = new SmsDropboxPublisherFixtureHttp;
    $this->dropboxTokens = 0;
    $this->dropboxClient = new Client(['handler' => HandlerStack::create(function (RequestInterface $request, array $options) {
        return Create::promiseFor($this->dropboxHttp->reply($request, $options));
    })]);
    $this->dropboxPublisher = new SmsDropboxRunLinkPublisher($this->dropboxClient, function (): string { $this->dropboxTokens++; return 'fixture-access'; });
    $this->dropboxStream = tmpfile();
    fwrite($this->dropboxStream, "First name,debt load,phone1,send date\r\nSynthetic,14000,02025550100,2026-10-26\r\n"); rewind($this->dropboxStream);
    $hash = SmsDropboxContentHash::stream($this->dropboxStream);
    $this->dropboxHttp->contentHash = $hash['content_hash'];
    $this->dropboxHttp->part = ['part' => 1, 'name' => 'SMS Export - 2026-10-26 - SMS0001-SMS0009 - Part 1 of 2.csv',
        'rows' => 1, 'bytes' => $hash['bytes'], 'sha256' => $hash['sha256']];
    $this->dropboxHttp->policy = $this->dropboxPublisher->policy('2026-10-26');
    $this->dropboxPublish = fn () => $this->dropboxPublisher->publish($this->dropboxHttp->requestId, $this->dropboxHttp->part,
        $this->dropboxStream, $this->dropboxHttp->policy);
});

afterEach(function (): void {
    if (is_resource($this->dropboxStream)) fclose($this->dropboxStream);
    Carbon::setTestNow($this->dropboxPreviousClock); Container::setInstance($this->dropboxPreviousContainer);
});

test('Dropbox identity and stable California send-date expiry policy are pure and exclude credentials', function (): void {
    $identity = $this->dropboxPublisher->identity();
    $policy = $this->dropboxPublisher->policy('2026-10-26');
    expect($policy)->toBe(['version' => 1, 'identity' => $identity, 'approval_sha256' => str_repeat('a', 64),
        'send_date' => '2026-10-26', 'audience' => 'public', 'access' => 'viewer', 'allow_download' => true,
        'require_password' => false, 'expires' => '2026-11-03T07:59:59Z']);
    $this->dropboxConfig->set('sms-exports.dropbox_access_token', 'not-read-by-identity');
    Carbon::setTestNow(Carbon::parse('2026-11-10', 'UTC'));
    expect($this->dropboxPublisher->identity())->toBe($identity)->and($this->dropboxPublisher->policy('2026-10-26'))->toBe($policy)
        ->and($this->dropboxTokens)->toBe(0)->and($this->dropboxHttp->calls)->toBe([]);
});

test('Dropbox default off and required explicit destination/access policy fail before credentials or HTTP', function (array $change): void {
    foreach ($change as $key => $value) $this->dropboxConfig->set('sms-exports.'.$key, $value);
    $lazy = new SmsDropboxRunLinkPublisher;
    expect(fn () => $lazy->identity())->toThrow(RuntimeException::class)
        ->and(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class)
        ->and($this->dropboxTokens)->toBe(0)->and($this->dropboxHttp->calls)->toBe([]);
})->with([
    [['file_publication_enabled' => false]], [['file_publication_enabled' => 'true']], [['dropbox_account_id' => null]],
    [['dropbox_namespace_id' => null]], [['dropbox_folder_id' => null]], [['dropbox_folder_path' => '/']],
    [['dropbox_folder_path' => '/Synthetic SMS/../Other']], [['dropbox_policy_sha256' => null]],
    [['dropbox_link_audience' => null]], [['dropbox_link_audience' => 'team']], [['dropbox_link_expiry_days' => null]],
    [['dropbox_link_expiry_days' => '7']], [['dropbox_link_expiry_days' => 0]], [['dropbox_link_expiry_days' => 91]],
]);

test('Dropbox publishes exact CSV once and returns canonical pinned metadata and effective link policy', function (): void {
    $receipt = ($this->dropboxPublish)();
    expect(array_keys($receipt))->toBe(['version', 'request_id', 'part', 'name', 'url', 'sha256', 'bytes', 'rows', 'remote', 'policy'])
        ->and($receipt['request_id'])->toBe($this->dropboxHttp->requestId)->and($receipt['part'])->toBe(1)
        ->and($receipt['remote']['content_hash'])->toBe($this->dropboxHttp->contentHash)
        ->and($receipt['remote']['rev'])->toBe('abcdef012345')->and($receipt['policy'])->toBe($this->dropboxHttp->policy)
        ->and($this->dropboxHttp->uploads)->toBe(1)->and($this->dropboxHttp->creates)->toBe(1)
        ->and(is_resource($this->dropboxStream))->toBeTrue();
    rewind($this->dropboxStream);
    expect(($this->dropboxPublish)())->toBe($receipt)->and($this->dropboxHttp->uploads)->toBe(1)->and($this->dropboxHttp->creates)->toBe(1);
});

test('Dropbox retries adopt a matching committed upload or created link after lost responses without duplicate writes', function (string $kind): void {
    if ($kind === 'upload') $this->dropboxHttp->uploadTimeout = true; else $this->dropboxHttp->linkTimeout = true;
    $receipt = ($this->dropboxPublish)(); rewind($this->dropboxStream);
    expect(($this->dropboxPublish)())->toBe($receipt)->and($this->dropboxHttp->uploads)->toBe(1)->and($this->dropboxHttp->creates)->toBe(1);
})->with(['upload', 'link']);

test('Dropbox never guesses an absent upload outcome or creates a link when upload did not commit', function (): void {
    $this->dropboxHttp->failUpload = true;
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class, 'incomplete or uncertain')
        ->and($this->dropboxHttp->uploads)->toBe(1)->and($this->dropboxHttp->creates)->toBe(0);
});

test('Dropbox verifies existing file bytes and never overwrites mismatched objects', function (string $key, mixed $value): void {
    $this->dropboxHttp->file = array_replace($this->dropboxHttp->metadata(), [$key => $value]);
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class)
        ->and($this->dropboxHttp->uploads)->toBe(0)->and($this->dropboxHttp->creates)->toBe(0);
})->with([
    ['content_hash', str_repeat('b', 64)], ['size', 1], ['name', 'another.csv'], ['path_lower', '/another/file.csv'],
    ['.tag', 'folder'], ['is_downloadable', false], ['id', 'unknown'], ['rev', 'bad'],
]);

test('Dropbox fails before HTTP if local CSV ordinary manifest hash date policy or filename changed', function (string $kind): void {
    if ($kind === 'hash') $this->dropboxHttp->part['sha256'] = str_repeat('b', 64);
    elseif ($kind === 'pending') $this->dropboxHttp->part['name'] = str_replace('SMS0001-SMS0009', 'ID Pending', $this->dropboxHttp->part['name']);
    elseif ($kind === 'policy') $this->dropboxHttp->policy['audience'] = 'no_one';
    elseif ($kind === 'expired') Carbon::setTestNow(Carbon::parse('2026-11-03 08:00:00', 'UTC'));
    else fseek($this->dropboxStream, 1);
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class)
        ->and($this->dropboxTokens)->toBe(0)->and($this->dropboxHttp->calls)->toBe([]);
})->with(['hash', 'pending', 'policy', 'expired', 'offset']);

test('Dropbox rejects wrong account folder identity or moved destination before upload', function (string $kind): void {
    if ($kind === 'account') $this->dropboxHttp->account = 'dbid:anotherAccount';
    elseif ($kind === 'folder ID') $this->dropboxHttp->folderId = 'id:anotherFolder';
    else $this->dropboxHttp->folderPath = '/Another Folder';
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class)
        ->and($this->dropboxHttp->uploads)->toBe(0)->and($this->dropboxHttp->creates)->toBe(0);
})->with(['account', 'folder ID', 'folder path']);

test('Dropbox detects configuration drift during an operation before the next rooted request', function (): void {
    $this->dropboxHttp->afterAccount = function (): void { $this->dropboxConfig->set('sms-exports.dropbox_namespace_id', '99999'); };
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class, 'policy changed')
        ->and($this->dropboxHttp->uploads)->toBe(0)->and($this->dropboxHttp->calls)->toHaveCount(1);
});

test('Dropbox existing shared links must match exact file expiry and effective permissions without mutation', function (Closure $change): void {
    $this->dropboxHttp->file = $this->dropboxHttp->metadata();
    $link = $this->dropboxHttp->link(); $change($link); $this->dropboxHttp->links = [$link];
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class)
        ->and($this->dropboxHttp->uploads)->toBe(0)->and($this->dropboxHttp->creates)->toBe(0);
})->with([
    'folder link' => fn (&$l) => $l['.tag'] = 'folder', 'wrong file' => fn (&$l) => $l['id'] = 'id:otherFile',
    'wrong revision' => fn (&$l) => $l['rev'] = 'abcdef012346', 'wrong expiry' => fn (&$l) => $l['expires'] = '2099-01-01T00:00:00Z',
    'wrong audience' => fn (&$l) => $l['link_permissions']['effective_audience']['.tag'] = 'team',
    'editor' => fn (&$l) => $l['link_permissions']['link_access_level']['.tag'] = 'editor',
    'no download' => fn (&$l) => $l['link_permissions']['allow_download'] = false,
    'password' => fn (&$l) => $l['link_permissions']['require_password'] = true,
    'unknown audience' => function (&$l) { unset($l['link_permissions']['effective_audience']); },
    'missing expiry' => function (&$l) { unset($l['expires']); },
    'host injection' => fn (&$l) => $l['url'] = 'https://www.dropbox.com.evil.example/s/key/file.csv',
    'userinfo' => fn (&$l) => $l['url'] = 'https://secret@www.dropbox.com/s/key/file.csv',
    'fragment' => fn (&$l) => $l['url'] = 'https://www.dropbox.com/s/key/file.csv#secret',
    'backslash' => fn (&$l) => $l['url'] = 'https://www.dropbox.com/s/key/\\file.csv',
]);

test('Dropbox supports explicitly configured existing-member-only policy without adding members', function (): void {
    $this->dropboxConfig->set('sms-exports.dropbox_link_audience', 'no_one');
    $this->dropboxHttp->policy = $this->dropboxPublisher->policy('2026-10-26');
    expect(($this->dropboxPublish)()['policy']['audience'])->toBe('no_one');
});

test('Dropbox holds ambiguous multiple or paginated direct links instead of choosing or creating another', function (bool $pagination): void {
    $this->dropboxHttp->file = $this->dropboxHttp->metadata();
    if ($pagination) $this->dropboxHttp->hasMore = true;
    else $this->dropboxHttp->links = [$this->dropboxHttp->link(), $this->dropboxHttp->link()];
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class, 'ambiguous')
        ->and($this->dropboxHttp->creates)->toBe(0);
})->with([false, true]);

test('Dropbox verifies stored revision and cross-run receipt pins without any provider write', function (): void {
    $receipt = ($this->dropboxPublish)(); $writes = [$this->dropboxHttp->uploads, $this->dropboxHttp->creates];
    $this->dropboxPublisher->verify($this->dropboxHttp->requestId, $this->dropboxHttp->part, $receipt, $this->dropboxHttp->policy);
    $this->dropboxHttp->file['rev'] = 'abcdef012346';
    expect(fn () => $this->dropboxPublisher->verify($this->dropboxHttp->requestId, $this->dropboxHttp->part, $receipt, $this->dropboxHttp->policy))->toThrow(RuntimeException::class, 'changed')
        ->and([$this->dropboxHttp->uploads, $this->dropboxHttp->creates])->toBe($writes);
    $receipt['request_id'] = '99999999-2222-4333-8444-555555555555';
    expect(fn () => $this->dropboxPublisher->verify($this->dropboxHttp->requestId, $this->dropboxHttp->part, $receipt, $this->dropboxHttp->policy))->toThrow(RuntimeException::class);
});

test('Dropbox uploads larger than 150 MiB using bounded sequential chunks and adopts lost finish responses', function (): void {
    ftruncate($this->dropboxStream, 157286400 + 1); rewind($this->dropboxStream);
    $hash = SmsDropboxContentHash::stream($this->dropboxStream);
    $this->dropboxHttp->part['bytes'] = $hash['bytes']; $this->dropboxHttp->part['sha256'] = $hash['sha256'];
    $this->dropboxHttp->contentHash = $hash['content_hash']; $this->dropboxHttp->failFinishAfterCommit = true;
    $receipt = ($this->dropboxPublish)();
    expect($receipt['bytes'])->toBe(157286401)->and($this->dropboxHttp->chunkSizes)->toHaveCount(19)
        ->and(max($this->dropboxHttp->chunkSizes))->toBe(8388608)->and($this->dropboxHttp->uploads)->toBe(1)
        ->and($this->dropboxHttp->creates)->toBe(1)->and(is_resource($this->dropboxStream))->toBeTrue();
});

test('Dropbox refreshes offline tokens lazily without including credentials in publication identity or receipts', function (): void {
    $this->dropboxConfig->set('sms-exports.dropbox_refresh_token', 'fixture-refresh');
    $this->dropboxConfig->set('sms-exports.dropbox_app_key', 'fixture-key');
    $this->dropboxConfig->set('sms-exports.dropbox_app_secret', 'fixture-secret');
    $publisher = new SmsDropboxRunLinkPublisher($this->dropboxClient);
    expect($publisher->identity())->toBe($this->dropboxPublisher->identity())->and($this->dropboxHttp->calls)->toBe([]);
    $receipt = $publisher->publish($this->dropboxHttp->requestId, $this->dropboxHttp->part, $this->dropboxStream, $this->dropboxHttp->policy);
    expect(count(array_filter($this->dropboxHttp->calls, fn ($c) => $c['route'] === '/oauth2/token')))->toBe(1)
        ->and(json_encode($receipt))->not->toContain('fixture-secret', 'fixture-refresh', 'fixture-access');
});

test('Dropbox rejected HTTP outcomes are bounded sanitized and never trigger automatic request retries', function (int $status): void {
    $this->dropboxHttp->override = static fn ($route) => $route === '/2/users/get_current_account'
        ? new Response($status, ['Retry-After' => '30'], json_encode(['error_summary' => 'fixture-private-response-token', 'error' => ['.tag' => 'not_allowed']])) : null;
    try { ($this->dropboxPublish)(); $this->fail('Expected safe provider failure.'); }
    catch (RuntimeException $error) { expect($error->getMessage())->toContain('HTTP '.$status)->not->toContain('fixture-private-response-token'); }
    expect($this->dropboxHttp->calls)->toHaveCount(1)->and($this->dropboxHttp->uploads)->toBe(0)->and($this->dropboxHttp->creates)->toBe(0);
})->with([301, 400, 401, 403, 409, 429, 500, 503]);

test('Dropbox malformed or oversized responses fail without exposing remote text', function (string $kind): void {
    $this->dropboxHttp->override = static fn () => new Response(200, [], $kind === 'invalid' ? 'fixture-private-response-token' : str_repeat('x', 1048577));
    try { ($this->dropboxPublish)(); $this->fail('Expected safe response failure.'); }
    catch (RuntimeException $error) { expect($error->getMessage())->not->toContain('fixture-private-response-token'); }
    expect($this->dropboxHttp->calls)->toHaveCount(1)->and($this->dropboxHttp->uploads)->toBe(0);
})->with(['invalid', 'oversized']);

test('Dropbox publication cannot cross California midnight into a past send date before writing', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-27 06:59:59', 'UTC'));
    $this->dropboxHttp->afterAccount = static fn () => Carbon::setTestNow(Carbon::parse('2026-10-27 07:00:00', 'UTC'));
    expect(fn () => ($this->dropboxPublish)())->toThrow(RuntimeException::class)
        ->and($this->dropboxHttp->uploads)->toBe(0)->and($this->dropboxHttp->creates)->toBe(0);
});

test('Dropbox completed receipt verification allows a past planned date but cannot silently renew an expired link', function (): void {
    $receipt = ($this->dropboxPublish)();
    Carbon::setTestNow(Carbon::parse('2026-10-28 18:00:00', 'UTC'));
    $this->dropboxPublisher->verify($this->dropboxHttp->requestId, $this->dropboxHttp->part, $receipt, $this->dropboxHttp->policy);
    $count = count($this->dropboxHttp->calls);
    rewind($this->dropboxStream);
    expect(fn () => ($this->dropboxPublish)())->toThrow(InvalidArgumentException::class)
        ->and($this->dropboxHttp->calls)->toHaveCount($count);
    Carbon::setTestNow(Carbon::parse('2026-11-03 08:00:00', 'UTC'));
    expect(fn () => $this->dropboxPublisher->verify($this->dropboxHttp->requestId, $this->dropboxHttp->part, $receipt, $this->dropboxHttp->policy))->toThrow(RuntimeException::class)
        ->and($this->dropboxHttp->calls)->toHaveCount($count)->and($this->dropboxHttp->uploads)->toBe(1)->and($this->dropboxHttp->creates)->toBe(1);
});

test('Dropbox credential callback exceptions cannot leak token material into retained errors', function (): void {
    $publisher = new SmsDropboxRunLinkPublisher($this->dropboxClient, static fn () => throw new RuntimeException('fixture-private-token-material'));
    try { $publisher->publish($this->dropboxHttp->requestId, $this->dropboxHttp->part, $this->dropboxStream, $this->dropboxHttp->policy); $this->fail('Expected safe credential failure.'); }
    catch (RuntimeException $error) { expect($error->getMessage())->toContain('credential provider failed')->not->toContain('fixture-private-token-material'); }
    expect($this->dropboxHttp->calls)->toBe([]);
});

test('Dropbox accepts older resolved-public permission metadata without inferring existing-member access from team-only', function (): void {
    $this->dropboxHttp->mutate = static function (&$link): void {
        unset($link['link_permissions']['effective_audience']);
        $link['link_permissions']['resolved_visibility'] = ['.tag' => 'public'];
    };
    expect(($this->dropboxPublish)()['policy']['audience'])->toBe('public');
});
