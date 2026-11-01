<?php
declare(strict_types=1);
namespace Cmd\Reports\Services;

use Closure;

/** Uses the existing dynamic recipient lookup once and its immutable prepared message thereafter. */
final class SmsAutomaticHandoffEmailGateway implements SmsRunEmailGateway
{
    public function __construct(private readonly SmsRunEmailGateway $gateway, private readonly Closure $guard) {}
    public function configuration(): array { ($this->guard)(); return $this->gateway->configuration(); }
    public function assertReady(): void { ($this->guard)(); $this->gateway->assertReady(); }
    public function send(array $message): bool { ($this->guard)(); return $this->gateway->send($message); }
}
