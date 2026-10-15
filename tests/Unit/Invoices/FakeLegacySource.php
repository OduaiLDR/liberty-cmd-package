<?php

namespace Cmd\Reports\Tests\Unit\Invoices;

use Cmd\Reports\Invoices\Backfill\LegacySource;

final class FakeLegacySource implements LegacySource
{
    /** @param array<string, array{0: string, 1?: int}> $files path => [bytes, mtime] */
    public function __construct(public array $files = [])
    {
    }

    public function files(string $dir): array
    {
        $dir = trim($dir, '/');
        $out = array_values(array_filter(array_keys($this->files), static fn (string $p) => $dir === '' || str_starts_with($p, $dir . '/')));
        sort($out);

        return $out;
    }

    public function read(string $path): ?string
    {
        return $this->files[$path][0] ?? null;
    }

    public function modifiedAt(string $path): ?int
    {
        return $this->files[$path][1] ?? null;
    }
}
