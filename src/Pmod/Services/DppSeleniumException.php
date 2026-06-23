<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use RuntimeException;
use Throwable;

/**
 * Thrown when the headless-browser cancel flow against login.debtpaypro.com
 * fails — bad credentials, missing selector, alert popup not handled, etc.
 *
 * Carries tenant + contactId context so logs and the recap email can identify
 * which run got stuck without re-deriving from the stack trace.
 */
final class DppSeleniumException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $tenant,
        public readonly ?string $contactId = null,
        public readonly ?string $stage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function context(): array
    {
        return [
            'tenant' => $this->tenant,
            'contact_id' => $this->contactId,
            'stage' => $this->stage,
        ];
    }
}
