<?php

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/** A summary is derived data. Refresh only after the outer eligibility write commits. */
final class SmsAvailabilityRefresh
{
    public static function afterCommit(ConnectionInterface $db): void
    {
        $refresh = static function (): void {
            try {
                app(SmsAvailabilityService::class)->invalidate();
            } catch (Throwable $error) {
                // Never turn an already committed send/import into a reported business failure.
                // The bounded summary freshness interval also recovers missed notifications.
                try {
                    Log::warning('SMS summary refresh could not be requested.', ['exception' => $error]);
                } catch (Throwable) {
                    // A logging outage must not change the outcome of the committed write either.
                }
            }
        };

        if ($db->transactionLevel() > 0) {
            $db->afterCommit($refresh);
        } else {
            $refresh();
        }
    }
}
