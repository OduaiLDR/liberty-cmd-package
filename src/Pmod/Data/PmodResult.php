<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Data;

final class PmodResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $metadata = [],
    ) {
    }
}
