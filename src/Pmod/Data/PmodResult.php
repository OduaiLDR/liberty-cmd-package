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

    /**
     * @param array<string, mixed> $metadata
     */
    public static function failed(string $message, array $metadata = []): self
    {
        return new self(
            status: 'failed',
            message: $message,
            metadata: $metadata,
        );
    }

    /**
     * Only a genuine failure counts. `captured_for_manual_review` and
     * `partial_update` are valid outcomes, not errors - a dry run always
     * captures, so treating capture as failure would report every dry run as
     * broken.
     *
     * Two callers relied on this before it existed and fatalled on every call:
     * PmodCsAgentRequestController (so /api/pmod/request 500d instead of
     * answering 200/422) and TestPmodHandler, which called isSucceeded() at the
     * end of every run.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
