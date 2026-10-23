<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\SmsRunArchiveWorkflow;
use Illuminate\Console\Command;
use Throwable;

/** Operator-only explicit archive work; never scheduled or dispatched by an HTTP read. */
final class SmsRunArchiveCommand extends Command
{
    protected $signature = 'sms:run-archive {request-id : Approved versioned SMS run UUID} {--action=verify : verify, archive or restore}';
    protected $description = 'Explicitly verify, archive or restore one approved SMS artifact using its reviewed private storage contract.';

    public function handle(): int
    {
        $id = (string) $this->argument('request-id');
        $action = (string) $this->option('action');
        if (! in_array($action, ['verify', 'archive', 'restore'], true)
            || ! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $id)) {
            $this->error('Use an approved lower-case run UUID and action verify, archive or restore.');
            return self::FAILURE;
        }
        try {
            app(SmsRunArchiveWorkflow::class)->{$action}($id);
        } catch (Throwable) {
            // Never expose provider URLs, raw exceptions, credentials or recipient data.
            $this->error('SMS archive operation did not complete. Check the reviewed configuration, run receipt and private operator diagnostics. No send was requested.');
            return self::FAILURE;
        }
        $this->info('SMS run '.$id.': '.$action.' verified successfully.');
        return self::SUCCESS;
    }
}
