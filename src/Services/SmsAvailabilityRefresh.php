<?php

namespace Cmd\Reports\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use LogicException;
use PDO;
use Throwable;

/** A summary is derived data. Refresh only after the outer eligibility write commits. */
final class SmsAvailabilityRefresh
{
    public static function durableEnabled(): bool
    {
        return (bool) config('sms-exports.durable_availability_enabled', false);
    }

    /** Give an old autocommit statement an atomic revision only in the opt-in mode. */
    public static function transaction(ConnectionInterface $db, callable $callback): mixed
    {
        return self::durableEnabled() ? $db->transaction($callback) : $callback();
    }

    public static function afterCommit(ConnectionInterface $db): void
    {
        $durable = self::durableEnabled();
        if ($durable) {
            if ($db->transactionLevel() < 1) {
                throw new LogicException('Durable SMS availability must be dirtied inside the eligibility transaction.');
            }
            // Deliberately uncaught: a missing revision must roll back the business write.
            app(SmsAvailabilityLedger::class)->dirty($db);
        }
        $refresh = static fn () => self::request($durable);

        if ($db->transactionLevel() > 0) {
            $db->afterCommit($refresh);
        } else {
            $refresh();
        }
    }

    /** Raw commits do not run Laravel's afterCommit callbacks. */
    public static function beforeNativeCommit(ConnectionInterface $db, PDO $pdo): void
    {
        if ($db->getPdo() !== $pdo || $db->transactionLevel() !== 0 || ! $pdo->inTransaction()) {
            throw new LogicException('Native SMS availability requires the active sqlsrv PDO and no Laravel transaction.');
        }
        app(SmsAvailabilityLedger::class)->dirty($db);
    }

    /** Call only after a successful native commit with a persisted revision. */
    public static function afterNativeCommit(): void
    {
        self::request(true);
    }

    private static function request(bool $durable): void
    {
        try {
            if ($durable) {
                app(SmsDurableAvailabilityService::class)->request();
            } else {
                app(SmsAvailabilityService::class)->invalidate();
            }
        } catch (Throwable $error) {
            // Never report an already committed send/import as a business failure.
            // The durable revision (or legacy freshness interval) recovers a missed request.
            try {
                Log::warning('SMS summary refresh could not be requested.', ['exception' => $error]);
            } catch (Throwable) {
                // Logging must not change the outcome of a committed write either.
            }
        }
    }
}
