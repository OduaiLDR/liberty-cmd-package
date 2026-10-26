<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Jobs\SmsRunDebtPleteImportJob;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow;
use Illuminate\Console\Command;
use Throwable;

/** Status is a saved read. Every mutating action is explicit and retains the same approved UUID. */
class SmsRunDebtPleteImportCommand extends Command
{
    protected $signature = 'sms:run-import {request-id : Existing approved SMS request UUID}
        {--action=status : status, prepare, step or queue}
        {--actor= : Operator email recorded when preparing this handoff}
        {--confirm-import : Confirm the configured tenant import of this approved batch}';
    protected $description = 'Read saved SMS import progress, or explicitly prepare and process the same approved handoff.';

    public function handle(): int
    {
        return SmsDebtPleteImportPrivacy::run(function (): int {
            $id = $this->argument('request-id'); $action = $this->option('action');
            if (!is_string($id) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id) !== 1
                || !in_array($action, ['status', 'prepare', 'step', 'queue'], true)) {
                $this->error('Use the existing lower-case SMS request UUID and a supported import action.');
                return self::FAILURE;
            }
            try {
                if ($action === 'status' && config('sms-exports.debtplete_import_enabled', false) !== true) {
                    $this->line('{"status":"disabled"}');
                    return self::SUCCESS;
                }
                if ($action !== 'status' && !$this->option('confirm-import')) {
                    $this->error('An explicit import confirmation is required.');
                    return self::FAILURE;
                }
                $workflow = $this->workflow();
                if ($action === 'status') {
                    $status = $workflow->status($id);
                } else {
                    if ($action === 'step') {
                        $status = $workflow->step($id);
                    } else {
                        $status = $workflow->prepare($id, (string) $this->option('actor'));
                        if ($action === 'queue' && !in_array($status['status'], ['completed', 'held'], true)) {
                            $this->enqueue($id);
                        }
                    }
                }
                $this->line(json_encode($status ?? ['status' => 'not_requested'], JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            } catch (Throwable) {
                $this->error('SMS import outcome is unconfirmed. Inspect its retained outbox before continuing the same handoff.');
                return self::FAILURE;
            }
        });
    }

    protected function workflow(): SmsRunDebtPleteImportWorkflow { return app(SmsRunDebtPleteImportWorkflow::class); }
    protected function enqueue(string $id): void { SmsRunDebtPleteImportJob::dispatch($id)->afterCommit(); }
}

