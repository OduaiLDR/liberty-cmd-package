<?php
declare(strict_types=1);
namespace Cmd\Reports\Services;

use Closure;

/** Automatic-only pre-call guard; a successful remote acknowledgement is never discarded by a post-call gate check. */
final class SmsAutomaticHandoffPublisher implements SmsRunLinkPublisher
{
    public function __construct(private readonly SmsRunLinkPublisher $publisher, private readonly Closure $guard) {}
    public function identity(): string { ($this->guard)(); return $this->publisher->identity(); }
    public function policy(string $date): array { ($this->guard)(); return $this->publisher->policy($date); }
    public function publish(string $id, array $part, mixed $stream, array $policy): array
    { ($this->guard)(); return $this->publisher->publish($id, $part, $stream, $policy); }
    public function verify(string $id, array $part, array $receipt, array $policy): void
    { ($this->guard)(); $this->publisher->verify($id, $part, $receipt, $policy); }
}
