<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/**
 * Provider boundary for immutable SMS run archives.
 *
 * The default provider binding is lazy and disabled until explicitly configured.
 * Providers guarantee create-only publication and reads by the returned version.
 */
interface SmsRunArchiveStore
{
    /** Stable opaque identity of the provider location, owner and encryption contract. */
    public function identity(): string;

    /**
     * @param array<string,string> $metadata
     * @return string Immutable provider version identity.
     */
    public function putIfAbsent(string $key, string $sourcePath, array $metadata): string;

    /** @return array{bytes:int,metadata:array<string,string>}|null */
    public function metadata(string $key, string $version): ?array;

    /** @return resource */
    public function read(string $key, string $version): mixed;
}
