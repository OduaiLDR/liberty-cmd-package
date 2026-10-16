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
        $root = rtrim(str_replace('\\', '/', $this->root), '/');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                // Forward slashes on every OS (Windows returns backslashes).
                $out[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1), '/');
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
        $relative = str_replace('\\', '/', $relative);
        if (str_contains($relative, '..')) {
            return null;
        }

        return rtrim(str_replace('\\', '/', $this->root), '/') . '/' . ltrim($relative, '/');
    }
}
