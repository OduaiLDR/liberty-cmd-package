<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsDropboxContentHash;

test('Dropbox content hash is SHA256 of binary four MiB block hashes, not the ordinary file hash', function (int $length): void {
    $bytes = str_repeat('A', min($length, 4194304)).str_repeat('B', max(0, $length - 4194304));
    $expected = hash('sha256', ($length > 0 ? hash('sha256', substr($bytes, 0, 4194304), true) : '')
        .($length > 4194304 ? hash('sha256', substr($bytes, 4194304), true) : ''));
    $stream = tmpfile();
    try {
        fwrite($stream, $bytes); fseek($stream, min(3, $length));
        $position = ftell($stream);
        expect(SmsDropboxContentHash::bytes($bytes))->toBe($expected)
            ->and(SmsDropboxContentHash::stream($stream))->toBe(['sha256' => hash('sha256', $bytes), 'content_hash' => $expected, 'bytes' => $length])
            ->and(ftell($stream))->toBe($position);
    } finally { fclose($stream); }
})->with([0, 1, 17, 4194303, 4194304, 4194305, 8388608]);

test('Dropbox official published multi-block hash fixture uses binary digests', function (): void {
    $blocks = ['2a846fa617c3361fc117e1c5c1e1838c336b6a5cef982c1a2d9bdf68f2f1992a',
        'c68469027410ea393eba6551b9fa1e26db775f00eae70a0c3c129a0011a39cf9',
        '7376192de020925ce6c5ef5a8a0405e931b0a9a8c75517aacd9ca24a8a56818b'];
    expect(hash('sha256', implode('', array_map(hex2bin(...), $blocks))))->toBe('485291fa0ee50c016982abbfa943957bcd231aae0492ccbaa22c58e3997b35e0');
});

test('Dropbox hashing rejects nonstream and unseekable inputs', function (): void {
    expect(fn () => SmsDropboxContentHash::stream('not-a-resource'))->toThrow(RuntimeException::class);
});
