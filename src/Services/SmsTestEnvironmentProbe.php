<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Metadata and filesystem observations only. Never creates storage or starts a worker. */
final class SmsTestEnvironmentProbe
{
    public function report(): array
    {
        $connection = config('queue.default');
        $queue = is_string($connection) ? config('queue.connections.'.$connection, []) : [];
        if (! is_array($queue)) $queue = [];
        $failed = config('queue.failed', []);
        if (! is_array($failed)) $failed = [];
        $root = config('sms-exports.local_root');
        if (! is_string($root) || $root === '') {
            $root = app()->bound('path.storage') ? storage_path('app/private/sms-exports') : null;
        }
        $safeRoot = $this->symlinkFreeRoot($root);
        // Match the actual middleware environment, including --env overrides and
        // cached-config disagreement. Configuration text alone cannot prove auth.
        $environment = null;
        try { if (method_exists(app(), 'environment')) $environment = app()->environment(); }
        catch (Throwable) { /* Unknown runtime environment fails closed in the report. */ }
        $jobs = ($queue['driver'] ?? null) === 'database' && $this->schemaReady(
            $queue['connection'] ?? config('database.default'), $queue['table'] ?? 'jobs',
            ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']
        );
        $failedJobs = ($failed['driver'] ?? null) === 'database-uuids' && $this->schemaReady(
            $failed['database'] ?? config('database.default'), $failed['table'] ?? 'failed_jobs',
            ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']
        );
        return SmsTestEnvironmentReadiness::report([
            'app_env' => $environment, 'queue_connection' => $connection,
            'queue_driver' => $queue['driver'] ?? null, 'queue_retry_after' => $queue['retry_after'] ?? null,
            'sms_queue' => config('sms-exports.queue', 'tu-export'),
            'auth_enabled' => config('services.cmd.auth_enabled', true),
            'identity_url' => config('services.cmd.auth_validate_url'), 'app_url' => config('app.url'),
        ], [
            'php_sapi' => PHP_SAPI, 'jobs_schema_ready' => $jobs, 'failed_jobs_schema_ready' => $failedJobs,
            'private_root_exists' => $safeRoot && file_exists($root),
            'private_root_is_directory' => $safeRoot && is_dir($root),
            'private_root_writable' => $safeRoot && is_dir($root) && is_writable($root),
            'private_root_symlink_free' => $safeRoot,
        ]);
    }

    private function schemaReady(mixed $connection, mixed $table, array $columns): bool
    {
        if (! is_string($connection) || $connection === '' || ! is_string($table)
            || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D', $table)) return false;
        try {
            $schema = DB::connection($connection)->getSchemaBuilder();
            return $schema->hasTable($table) && $schema->hasColumns($table, $columns);
        } catch (Throwable) {
            // Missing connection, unavailable metadata or forbidden schema fails closed.
            // Do not print provider/driver exceptions or connection details.
            return false;
        }
    }

    private function symlinkFreeRoot(mixed $root): bool
    {
        if (! is_string($root)) return false;
        $path = str_replace('\\', '/', rtrim($root, '/\\'));
        if (preg_match('/[\x00-\x1f\x7f]/', $path) || ! preg_match('~^(?:[A-Za-z]:/|/)(?!/)~D', $path)
            || str_contains($path, '//') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) return false;
        while (true) {
            if (is_link($path) || (file_exists($path) && ! is_dir($path))) return false;
            $parent = str_replace('\\', '/', dirname($path));
            if ($parent === $path || $parent === '.') return true;
            $path = $parent;
        }
    }
}
