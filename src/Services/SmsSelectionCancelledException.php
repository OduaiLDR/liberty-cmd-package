<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Internal cooperative stop. It is handled after the selection snapshot rolls back. */
final class SmsSelectionCancelledException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Selection cancellation was requested.');
    }
}
