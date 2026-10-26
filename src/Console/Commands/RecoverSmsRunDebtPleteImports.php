<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Jobs\SmsRunDebtPleteImportJob;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunDebtPleteImportWorkflow;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Recovers only existing outbox entries. It never discovers historical approvals to import. */
class RecoverSmsRunDebtPleteImports extends Command
{
    protected $signature = 'sms:run-import-recover
        {--limit=10 : Maximum existing pending handoffs, from 1 to 100}
        {--queue : Request bounded asynchronous processing}';
    protected $description = 'Preview existing pending SMS import receipts, or request same-plan asynchronous recovery.';

    public function handle(): int
    {
        return SmsDebtPleteImportPrivacy::run(function (): int {
            if (config('sms-exports.debtplete_import_enabled', false) !== true) {
                $this->line('{"status":"disabled","dispatch_requests":0}');
                return self::SUCCESS;
            }
            $submitted = 0;
            try {
                $value = $this->option('limit');
                if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,2}\z/D', $value) !== 1 || (int) $value > 100) {
                    throw new RuntimeException('A bounded import recovery limit is required.');
                }
                if ($this->option('queue')) (new SmsExportQueue)->assertReady();
                $ids = $this->workflow()->pending((int) $value);
                foreach ($ids as $id) {
                    if (!is_string($id) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id) !== 1) {
                        throw new RuntimeException('Unverified pending import identity.');
                    }
                    if ($this->option('queue')) { $this->enqueue($id); ++$submitted; }
                }
                $this->line(json_encode(['status' => $this->option('queue') ? 'requested' : 'preview',
                    'pending_handoffs' => count($ids), 'dispatch_requests' => $submitted], JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            } catch (Throwable) {
                $this->line(json_encode(['status' => 'pending_recovery', 'dispatch_requests' => $submitted], JSON_THROW_ON_ERROR));
                return self::FAILURE;
            }
        });
    }

    protected function workflow(): SmsRunDebtPleteImportWorkflow { return app(SmsRunDebtPleteImportWorkflow::class); }
    protected function enqueue(string $id): void { SmsRunDebtPleteImportJob::dispatch($id)->afterCommit(); }
}

