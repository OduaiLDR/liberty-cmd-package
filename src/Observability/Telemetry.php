<?php

namespace Cmd\Reports\Observability;

use Closure;
use Throwable;

/** Optional bridge: the package has no dependency on Telescope or the host app. */
final class Telemetry
{
    private static ?Closure $sink = null;
    private static ?string $runId = null;
    private static ?int $automationLog = null;

    public static function configure(?Closure $sink): void { self::$sink = $sink; }
    public static function enabled(): bool { return self::$sink !== null; }

    public static function runId(): string
    {
        if (self::$runId === null) {
            $automation = getenv('CMD_TRACE_AUTOMATION_LOG_ID');
            self::reset(getenv('CMD_TRACE_RUN_ID') ?: null);
            if (is_string($automation) && ctype_digit($automation)) { self::automation((int) $automation); }
        }
        return self::$runId;
    }

    public static function reset(?string $id = null): void
    {
        self::$runId = is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id)
            ? $id : bin2hex(random_bytes(16));
        self::$automationLog = null;
        putenv('CMD_TRACE_RUN_ID='.self::$runId);
        putenv('CMD_TRACE_AUTOMATION_LOG_ID=');
    }

    public static function automation(?int $id): void
    {
        self::runId();
        self::$automationLog = $id;
        putenv('CMD_TRACE_AUTOMATION_LOG_ID='.($id ?? ''));
    }
    public static function automationId(): ?int { return self::$automationLog; }

    public static function record(string $kind, string $operation, string $service, float $started, ?bool $success, ?Throwable $error = null, ?int $status = null): void
    {
        if (self::$sink === null) { return; }
        try {
            (self::$sink)([
                'kind' => $kind, 'operation' => $operation, 'service' => $service,
                'duration_ms' => round((microtime(true) - $started) * 1000, 3),
                'outcome' => $success === null ? 'unknown' : ($success ? 'success' : 'failed'),
                'exception_class' => $error ? get_class($error) : null,
                'status_code' => $status, 'run_id' => self::runId(),
                'automation_log_id' => self::$automationLog,
            ]);
        } catch (Throwable) {
            // Observability must never alter business outcomes.
        }
    }

    public static function measure(string $kind, string $operation, string $service, callable $callback): mixed
    {
        if (self::$sink === null) { return $callback(); }
        $start = microtime(true);
        try {
            $result = $callback();
            $success = $result !== false && !($kind === 'transfer' && $result === null) && !(is_array($result) && ($result['success'] ?? true) === false);
            self::record($kind, $operation, $service, $start, $success);
            return $result;
        } catch (Throwable $e) {
            self::record($kind, $operation, $service, $start, false, $e);
            throw $e;
        }
    }

    public static function curl(\CurlHandle $handle): string|bool
    {
        $start = microtime(true);
        try {
            $result = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            self::record('http', 'curl', 'external-api', $start, $result !== false && $status < 400, null, $status);
            return $result;
        } catch (Throwable $e) {
            self::record('http', 'curl', 'external-api', $start, false, $e);
            throw $e;
        }
    }
}
