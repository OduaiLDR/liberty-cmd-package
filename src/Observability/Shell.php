<?php

namespace Cmd\Reports\Observability;

use Throwable;

final class Shell
{
    public static function exec(string $command, ?array &$output = null, ?int &$resultCode = null): string|false
    {
        if (Telemetry::enabled()) { Telemetry::runId(); }
        $start = microtime(true);
        try {
            $result = exec($command, $output, $resultCode);
            Telemetry::record('process', 'shell-exec', 'subprocess', $start, $resultCode === 0, null, $resultCode);
            return $result;
        } catch (Throwable $e) {
            Telemetry::record('process', 'shell-exec', 'subprocess', $start, false, $e);
            throw $e;
        }
    }

    public static function shellExec(string $command): string|false|null
    {
        if (Telemetry::enabled()) { Telemetry::runId(); }
        $start = microtime(true);
        try {
            $result = shell_exec($command);
            // Native shell_exec cannot distinguish no output from execution failure.
            Telemetry::record('process', 'shell-discovery', 'subprocess', $start, $result === null ? null : $result !== false);
            return $result;
        } catch (Throwable $e) {
            Telemetry::record('process', 'shell-discovery', 'subprocess', $start, false, $e);
            throw $e;
        }
    }
}
