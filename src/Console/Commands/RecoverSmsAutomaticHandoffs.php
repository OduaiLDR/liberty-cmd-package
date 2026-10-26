<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Jobs\SmsRunAutomaticHandoffJob;
use Cmd\Reports\Services\SmsDebtPleteImportPrivacy;
use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsRunAutomaticHandoffWorkflow;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Discovers saved approval intents only. The default previews and never queues or sends. */
class RecoverSmsAutomaticHandoffs extends Command
{
    protected $signature = 'sms:automatic-handoff-recover {--limit=10 : Existing saved intents, from 1 to 100} {--queue : Request bounded asynchronous processing}';
    protected $description = 'Preview or queue existing automatic SMS handoff intents without adopting approval history.';
    public function handle(): int
    {
        return SmsDebtPleteImportPrivacy::run(function (): int {
            if (config('sms-exports.handoff_automatic', false) !== true || config('sms-exports.file_publication_enabled', false) !== true
                || config('sms-exports.email_handoff_enabled', false) !== true) {
                $this->line('{"status":"disabled","dispatch_requests":0}'); return self::SUCCESS;
            }
            $requested = 0;
            try {
                $limit = $this->option('limit');
                if (!is_string($limit) || !preg_match('/\A[1-9][0-9]{0,2}\z/D', $limit) || (int) $limit > 100) throw new RuntimeException;
                if ($this->option('queue')) (new SmsExportQueue)->assertReady();
                $ids = $this->workflow()->pending((int) $limit);
                foreach ($ids as $id) {
                    if (!is_string($id) || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id)) throw new RuntimeException;
                    if ($this->option('queue')) { $this->enqueue($id); ++$requested; }
                }
                $this->line(json_encode(['status' => $this->option('queue') ? 'requested' : 'preview', 'pending_handoffs' => count($ids), 'dispatch_requests' => $requested], JSON_THROW_ON_ERROR));
                return self::SUCCESS;
            } catch (Throwable) {
                $this->line(json_encode(['status' => 'pending_recovery', 'dispatch_requests' => $requested], JSON_THROW_ON_ERROR)); return self::FAILURE;
            }
        });
    }
    protected function workflow(): SmsRunAutomaticHandoffWorkflow { return app(SmsRunAutomaticHandoffWorkflow::class); }
    protected function enqueue(string $id): void { SmsRunAutomaticHandoffJob::dispatch($id)->afterCommit(); }
}
