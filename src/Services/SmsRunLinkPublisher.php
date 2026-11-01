<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Exact approved CSV publication only; does not send email or grant folder membership. */
interface SmsRunLinkPublisher
{
    public function identity(): string;
    public function policy(string $sendDate): array;
    public function publish(string $requestId, array $part, mixed $stream, array $policy): array;
    public function verify(string $requestId, array $part, array $receipt, array $policy): void;
}
