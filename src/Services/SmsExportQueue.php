<?php

namespace Cmd\Reports\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

class SmsExportQueue
{
    public const JOB_TIMEOUT = 7000;

    /** One run step can pick a tier subset from millions of people. Must stay under the queue's retry_after (14400). */
    public const RUN_STEP_TIMEOUT = 14000;

    public function assertReady(): void
    {
        $connection = (string) config('queue.default');
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 0);
        if ($connection !== 'database' || $retryAfter <= self::JOB_TIMEOUT) {
            throw new HttpException(503,
                'SMS exports need the asynchronous database queue, a tu-export worker, and DB_QUEUE_RETRY_AFTER above 7000 seconds.');
        }
    }

    /** A run step may last RUN_STEP_TIMEOUT, so the queue must wait longer than that before it hands the step to a second worker. */
    public function assertReadyForRunSteps(): void
    {
        $this->assertReady();
        $connection = (string) config('queue.default');
        if ((int) config("queue.connections.{$connection}.retry_after", 0) <= self::RUN_STEP_TIMEOUT) {
            throw new HttpException(503,
                'SMS run steps need DB_QUEUE_RETRY_AFTER above 14000 seconds, so a long step is never handed to a second worker.');
        }
    }
}
