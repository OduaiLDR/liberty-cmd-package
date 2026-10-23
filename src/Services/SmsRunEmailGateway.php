<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Configuration lookup and one transport attempt; retry decisions belong to the durable SMS ledger. */
interface SmsRunEmailGateway
{
    public function configuration(): array;

    /** Checks local transport configuration only; never requests a token or sends a message. */
    public function assertReady(): void;

    /** Sends only the frozen message; false may mean an uncertain provider outcome. */
    public function send(array $message): bool;
}
