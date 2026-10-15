<?php

namespace Cmd\Reports\Invoices\Backfill;

/** Read-only view of the Runner's old invoice files. Nothing here can write, move or delete. */
interface LegacySource
{
    /** @return list<string> relative file paths under $dir (recursive), [] when the folder does not exist */
    public function files(string $dir): array;

    public function read(string $path): ?string;

    /** Unix time the file was last written, or null. */
    public function modifiedAt(string $path): ?int;
}
