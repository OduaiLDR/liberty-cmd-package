<?php

namespace Cmd\Reports\Services;

use RuntimeException;

/** Simulation is impossible on any production host, environment or database name. */
final class SmsDemoGuard
{
    public static function enabled(): bool
    {
        if (! config('sms-exports.demo', false)) return false;
        $host = (string) config('database.connections.sqlsrv.host');
        $database = (string) config('database.connections.sqlsrv.database');
        if (! app()->environment('local') || ! in_array($host, ['127.0.0.1,14330', 'localhost,14330'], true)
            || ! preg_match('/^sms_demo_[a-z0-9_]+$/D', $database)) {
            throw new RuntimeException('SMS simulation requires APP_ENV=local and a dedicated sms_demo_* database on localhost:14330. Refusing this configuration.');
        }
        $default = (string) config('database.default');
        $jobHost = (string) config('database.connections.'.$default.'.host');
        if (! in_array($jobHost, ['127.0.0.1', 'localhost'], true) || config('sms-exports.queue') !== 'sms-demo') {
            throw new RuntimeException('SMS simulation also requires a local job database and the isolated sms-demo queue.');
        }
        return true;
    }
}
