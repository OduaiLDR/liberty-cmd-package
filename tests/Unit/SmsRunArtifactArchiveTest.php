<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsRunArchiveStore;
use Cmd\Reports\Services\SmsRunArtifactArchive;

final class InMemorySmsRunArchiveStore implements SmsRunArchiveStore
{
    /** @var array<string,array{version:string,bytes:string,metadata:array<string,string>}> */
    public array $objects = [];
    public int $puts = 0;
    public int $reads = 0;
    /** @var null|Closure(string,string,int):void */
    public ?Closure $onRead = null;

    public function identity(): string { return 'fixture-archive-v1'; }

    public function putIfAbsent(string $key, string $sourcePath, array $metadata): string
    {
        $bytes = file_get_contents($sourcePath);
        if ($bytes === false) throw new RuntimeException('fixture source missing');
        if (isset($this->objects[$key])) {
            $old = $this->objects[$key];
            if ($old['bytes'] !== $bytes || $old['metadata'] !== $metadata) throw new RuntimeException('create-only conflict');
            return $old['version'];
        }
        $this->puts++;
        $version = 'version-'.$this->puts;
        $this->objects[$key] = compact('version', 'bytes', 'metadata');
        return $version;
    }

    public function metadata(string $key, string $version): ?array
    {
        $object = $this->objects[$key] ?? null;
        if ($object === null || $object['version'] !== $version) return null;
        return ['bytes' => strlen($object['bytes']), 'metadata' => $object['metadata']];
    }

    public function read(string $key, string $version): mixed
    {
        $this->reads++;
        if ($this->onRead !== null) ($this->onRead)($key, $version, $this->reads);
        $object = $this->objects[$key] ?? null;
        if ($object === null || $object['version'] !== $version) return null;
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $object['bytes']); rewind($stream);
        return $stream;
    }
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/sms-archive-test-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0700, true);
    $this->requestId = '11111111-2222-4333-8444-555555555555';
    $this->name = 'sms_20261021_'.$this->requestId.'.zip';
    $this->source = $this->root.'/'.$this->name;
    file_put_contents($this->source, 'exact frozen sms bytes');
    $this->manifest = ['version' => 1, 'request_id' => $this->requestId,
        'download' => ['name' => $this->name, 'format' => 'zip',
            'bytes' => filesize($this->source), 'sha256' => hash_file('sha256', $this->source)],
        'parts' => [['name' => 'part.csv', 'rows' => 1]]];
    $this->store = new InMemorySmsRunArchiveStore;
    $this->archive = new SmsRunArtifactArchive($this->store);
});

afterEach(function (): void {
    if (! is_dir($this->root)) return;
    foreach (array_diff(scandir($this->root), ['.', '..']) as $name) unlink($this->root.'/'.$name);
    rmdir($this->root);
});

test('archive verifies exact bytes metadata and immutable replay', function (): void {
    $key = 'private/sms/runs/'.$this->requestId.'/'.$this->name;
    $receipt = $this->archive->archive($this->requestId, $this->source, $this->manifest, $key);
    $again = $this->archive->archive($this->requestId, $this->source, $this->manifest, $key);

    expect($again)->toBe($receipt)->and($this->store->puts)->toBe(1)
        ->and($receipt)->toMatchArray(['version' => 1, 'request_id' => $this->requestId,
            'key' => $key, 'object_version' => 'version-1', 'bytes' => filesize($this->source),
            'sha256' => hash_file('sha256', $this->source)]);
    $this->archive->verify($receipt, $this->manifest);
});

test('archive refuses altered source manifest keys and create-only conflicts', function (): void {
    $key = 'private/sms/runs/'.$this->requestId.'/'.$this->name;
    $badManifest = $this->manifest; $badManifest['download']['bytes']++;
    expect(fn () => $this->archive->archive($this->requestId, $this->source, $badManifest, $key))
        ->toThrow(RuntimeException::class, 'differs from its manifest');
    foreach (['../escape', 'private//sms/'.$this->name] as $unsafeKey) {
        expect(fn () => $this->archive->archive($this->requestId, $this->source, $this->manifest, $unsafeKey))
            ->toThrow(RuntimeException::class, 'safe explicit provider key');
    }

    $this->archive->archive($this->requestId, $this->source, $this->manifest, $key);
    file_put_contents($this->source, 'different bytes');
    $changed = $this->manifest;
    $changed['download']['bytes'] = filesize($this->source);
    $changed['download']['sha256'] = hash_file('sha256', $this->source);
    expect(fn () => $this->archive->archive($this->requestId, $this->source, $changed, $key))
        ->toThrow(RuntimeException::class, 'create-only conflict');
});

test('verification detects provider metadata and byte corruption', function (): void {
    $key = 'private/sms/runs/'.$this->requestId.'/'.$this->name;
    $receipt = $this->archive->archive($this->requestId, $this->source, $this->manifest, $key);
    $this->store->objects[$key]['metadata']['content-bytes'] = '999';
    expect(fn () => $this->archive->verify($receipt, $this->manifest))
        ->toThrow(RuntimeException::class, 'metadata differs');
    $this->store->objects[$key]['metadata']['content-bytes'] = (string) $receipt['bytes'];
    $this->store->objects[$key]['metadata'] = array_reverse($this->store->objects[$key]['metadata'], true);
    $this->archive->verify($receipt, $this->manifest);
    $this->store->objects[$key]['bytes'] = 'corrupt archive bytes';
    expect(fn () => $this->archive->verify($receipt, $this->manifest))
        ->toThrow(RuntimeException::class);
});

test('restore is atomic idempotent and never overwrites different bytes', function (): void {
    $key = 'private/sms/runs/'.$this->requestId.'/'.$this->name;
    $receipt = $this->archive->archive($this->requestId, $this->source, $this->manifest, $key);
    unlink($this->source);
    $normalized = str_replace('\\', '/', $this->source);
    expect($this->archive->restore($receipt, $this->manifest, $this->source))->toBe($normalized)
        ->and(file_get_contents($this->source))->toBe('exact frozen sms bytes');
    expect($this->archive->restore($receipt, $this->manifest, $this->source))->toBe($normalized);

    file_put_contents($this->source, 'different local bytes');
    expect(fn () => $this->archive->restore($receipt, $this->manifest, $this->source))
        ->toThrow(RuntimeException::class, 'already contains different bytes');
});

test('restore never clobbers a destination that appears during publication', function (): void {
    $key = 'private/sms/runs/'.$this->requestId.'/'.$this->name;
    $receipt = $this->archive->archive($this->requestId, $this->source, $this->manifest, $key);
    unlink($this->source);
    $destination = $this->source;
    $this->store->onRead = static function (string $readKey, string $version, int $reads) use ($destination): void {
        if ($reads === 3) file_put_contents($destination, 'concurrent different bytes');
    };

    expect(fn () => $this->archive->restore($receipt, $this->manifest, $destination))
        ->toThrow(RuntimeException::class, 'nothing was overwritten')
        ->and(file_get_contents($destination))->toBe('concurrent different bytes');
});

test('receipt cannot be reused with a different frozen manifest or version', function (): void {
    $key = 'private/sms/runs/'.$this->requestId.'/'.$this->name;
    $receipt = $this->archive->archive($this->requestId, $this->source, $this->manifest, $key);
    $changed = $this->manifest; $changed['parts'][0]['rows'] = 2;
    expect(fn () => $this->archive->verify($receipt, $changed))
        ->toThrow(RuntimeException::class, 'receipt differs');
    $receipt['object_version'] = "bad version";
    expect(fn () => $this->archive->verify($receipt, $this->manifest))
        ->toThrow(RuntimeException::class, 'invalid immutable version');
});
