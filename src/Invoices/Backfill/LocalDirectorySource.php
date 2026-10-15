<?php

namespace Cmd\Reports\Invoices\Backfill;

/** A folder on the Runner's disk, read only. Paths are relative to $root and cannot climb out of it. */
final class LocalDirectorySource implements LegacySource
{
    public function __construct(private readonly string $root)
    {
    }

    public function files(string $dir): array
    {
        $base = $this->resolve($dir);
        if ($base === null || ! is_dir($base)) {
            return [];
        }
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = ltrim(substr($file->getPathname(), strlen(rtrim($this->root, '/')) + 1), '/');
            }
        }
        sort($out);

        return $out;
    }

    public function read(string $path): ?string
    {
        $full = $this->resolve($path);

        return $full !== null && is_file($full) ? (string) file_get_contents($full) : null;
    }

    public function modifiedAt(string $path): ?int
    {
        $full = $this->resolve($path);

        return $full !== null && is_file($full) ? (int) filemtime($full) : null;
    }

    private function resolve(string $relative): ?string
    {
        if (str_contains($relative, '..')) {
            return null;
        }

        return rtrim($this->root, '/') . '/' . ltrim($relative, '/');
    }
}
