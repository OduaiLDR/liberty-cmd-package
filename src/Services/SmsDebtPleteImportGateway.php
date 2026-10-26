<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Explicit tenant destination; the durable workflow controls all retries. */
interface SmsDebtPleteImportGateway
{
    public function identity(): array;

    public function register(array $payload): array;

    public function upload(string $requestId, int $part, string $planSha256, mixed $stream): array;

    public function status(string $requestId): array;
}
