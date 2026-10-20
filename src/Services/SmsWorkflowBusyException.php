<?php

namespace Cmd\Reports\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Only workflow-lock contention is safe for a queue job to release and retry. */
final class SmsWorkflowBusyException extends HttpException
{
    public function __construct()
    {
        parent::__construct(409, 'This SMS operation is still running. Refresh before trying again.');
    }
}
