<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Jobs\SmsRunEmailJob;
use Cmd\Reports\Services\SmsRunEmailWorkflow;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Explicit bridge for approved links until the external publisher is connected. */
final class SmsRunEmailCommand extends Command
{
    protected $signature = 'sms:run-email {request-id : Approved SMS run UUID}
        {--action=status : status, prepare or queue}
        {--links-file= : Private local JSON receipt containing ordered approved external links}
        {--actor= : Operator email recorded when preparing the handoff}
        {--payload-sha256= : Exact prepared payload hash required to queue delivery}
        {--confirm-send : Explicitly confirm queuing a real external email}';

    protected $description = 'Inspect or explicitly prepare/queue one approved SMS email using dynamic TblReports recipients.';

    public function handle(SmsRunEmailWorkflow $emails): int
    {
        $id = (string) $this->argument('request-id');
        $action = (string) $this->option('action');
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)
            || ! in_array($action, ['status', 'prepare', 'queue'], true)) {
            $this->error('Use a lower-case SMS UUID and action status, prepare or queue.');
            return self::FAILURE;
        }
        try {
            if ($action === 'prepare') {
                $actor = trim((string) $this->option('actor'));
                if (! filter_var($actor, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('An explicit operator email is required.');
                }
                $status = $emails->prepare($id, $this->links((string) $this->option('links-file')), $actor);
            } elseif ($action === 'queue') {
                $hash = (string) $this->option('payload-sha256');
                $status = $emails->status($id);
                if (! $this->option('confirm-send') || ! preg_match('/^[a-f0-9]{64}$/D', $hash)
                    || ! is_array($status) || ($status['status'] ?? null) !== 'ready'
                    || ! hash_equals((string) ($status['payload_sha256'] ?? ''), $hash)) {
                    throw new RuntimeException('Only an explicitly confirmed, matching ready payload can be queued.');
                }
                SmsRunEmailJob::dispatch($id, $hash);
                $this->info('SMS email job queued. This does not confirm provider acceptance or SMS delivery.');
                return self::SUCCESS;
            } else {
                $status = $emails->status($id);
            }
            if ($status === null) {
                $this->info('No prepared SMS email is available. Check activation, schema, approved links and dynamic recipient setup.');
                return self::SUCCESS;
            }
            $this->info('SMS email state: '.$status['status']);
            $this->line('Prepared payload SHA-256: '.$status['payload_sha256']);
            $this->line('No email was sent or queued by this '.$action.' action.');
            return self::SUCCESS;
        } catch (Throwable) {
            // Never echo capability URLs, recipients, private paths or provider errors.
            $this->error('SMS email action did not complete. Check the private setup, frozen payload and handoff state. Do not blindly resend an uncertain attempt.');
            return self::FAILURE;
        }
    }

    private function links(string $path): array
    {
        $resolved = $path === '' ? false : realpath($path);
        if ($resolved === false || ! is_file($resolved) || is_link($path) || filesize($resolved) > 8 * 1024 * 1024) {
            throw new RuntimeException('A bounded private local link receipt is required.');
        }
        for ($parent = $path; dirname($parent) !== $parent; $parent = dirname($parent)) {
            if (is_link($parent)) {
                throw new RuntimeException('Symbolic link receipts are not permitted.');
            }
        }
        if (PHP_OS_FAMILY !== 'Windows' && (fileperms($resolved) & 0077) !== 0) {
            throw new RuntimeException('Link receipt permissions must be private to the operator.');
        }
        $stream = fopen($resolved, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The link receipt cannot be opened.');
        }
        try {
            $stat = fstat($stream);
            $current = lstat($resolved);
            if ($stat === false || $current === false || $stat['ino'] !== $current['ino'] || $stat['dev'] !== $current['dev']) {
                throw new RuntimeException('The link receipt changed while opening.');
            }
            if (($stat['mode'] & 0170000) !== 0100000
                || (PHP_OS_FAMILY !== 'Windows' && ($stat['mode'] & 0077) !== 0)) {
                throw new RuntimeException('The opened link receipt must be a private regular file.');
            }
            $json = stream_get_contents($stream, 8 * 1024 * 1024 + 1);
        } finally {
            fclose($stream);
        }
        if (! is_string($json) || strlen($json) > 8 * 1024 * 1024) {
            throw new RuntimeException('The link receipt exceeds its limit.');
        }
        $links = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($links) || ! array_is_list($links)) {
            throw new RuntimeException('Use the exact ordered link receipt list.');
        }
        return $links;
    }
}
