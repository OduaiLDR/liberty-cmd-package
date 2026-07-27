<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\SyncDroppedStatus;

use Cmd\Reports\Pmod\Services\DppDataClient;
use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\DroppedStatusSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class SyncDroppedStatus extends Command
{
    protected $signature = 'Sync:dropped-status
        {--source=ALL : Run ALL, LDR, or PLAW}
        {--limit= : Limit contacts updated per portal}';

    protected $description = 'Update eligible dropped contacts to Dropped / Cancelled in DebtPayPro.';

    public function handle(DroppedStatusSyncService $sync, DppDataClient $dpp): int
    {
        $source = strtoupper(trim((string) $this->option('source')));
        $failed = 0;

        try {
            $portals = $sync->portals($source);
            $limit = $this->parseLimit($this->option('limit'));
            foreach ($portals as $portal) {
                $dpp->assertConfigured(strtolower($portal['source']));
            }
        } catch (\Throwable $e) {
            $this->error('[ERROR] Live sync preflight failed: '.$e->getMessage());
            Log::error('SyncDroppedStatus: preflight failed', ['exception' => $e]);

            return Command::FAILURE;
        }

        $this->warn('[WARN] Live mode: CRM status updates will be sent.');

        foreach ($portals as $portal) {
            $tenant = strtolower($portal['source']);
            $lock = Cache::lock("sync-dropped-status:{$tenant}", 7200);

            if (! $lock->get()) {
                $failed++;
                $this->error("[ERROR] {$portal['source']} is already running; live sync skipped.");
                continue;
            }

            try {
                $this->info("[INFO] === {$portal['source']} ===");
                $snowflake = DBConnector::fromEnvironment($portal['env']);
                $report = $sync->buildReport($snowflake, $limit);
                $successes = 0;
                $errors = 0;

                foreach ($report['candidates'] as $candidate) {
                    try {
                        $ok = $dpp->setClientStatus(
                            $tenant,
                            $candidate['contact_id'],
                            DroppedStatusSyncService::TARGET_STATUS
                        );
                        if (! $ok) {
                            $errors++;
                            $this->error("[ERROR] {$portal['source']} contact {$candidate['contact_id']} update failed.");
                            continue;
                        }

                        $successes++;
                        $this->line("[OK] {$portal['source']} contact {$candidate['contact_id']} updated.");
                        Log::info('SyncDroppedStatus: contact updated', [
                            'portal' => $portal['source'],
                            'contact_id' => $candidate['contact_id'],
                            'target_status' => DroppedStatusSyncService::TARGET_STATUS,
                        ]);
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->error("[ERROR] {$portal['source']} contact {$candidate['contact_id']}: {$e->getMessage()}");
                        Log::error('SyncDroppedStatus: contact update failed', [
                            'portal' => $portal['source'],
                            'contact_id' => $candidate['contact_id'],
                            'exception' => $e,
                        ]);
                    }
                }

                $this->info(sprintf(
                    '[INFO] %s scanned=%d selected=%d updated=%d failed=%d skipped=%d',
                    $portal['source'],
                    $report['scanned_count'],
                    $report['selected_count'],
                    $successes,
                    $errors,
                    count($report['skipped'])
                ));

                Log::info('SyncDroppedStatus: portal finished', [
                    'portal' => $portal['source'],
                    'scanned' => $report['scanned_count'],
                    'selected' => $report['selected_count'],
                    'updated' => $successes,
                    'failed' => $errors,
                    'skipped' => count($report['skipped']),
                    'skipped_dropped' => $report['skipped_dropped_count'],
                    'skipped_system_cancel' => $report['skipped_system_cancel_count'],
                    'skipped_missing_status' => $report['skipped_missing_status_count'],
                    'duplicates' => $report['duplicate_count'],
                ]);

                if ($errors > 0) {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("[ERROR] {$portal['source']} live sync failed: ".$e->getMessage());
                Log::error('SyncDroppedStatus: portal failed', [
                    'portal' => $portal['source'],
                    'exception' => $e,
                ]);
            } finally {
                $lock->release();
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function parseLimit(mixed $value): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException('The limit must be a positive integer.');
        }

        return (int) $value;
    }
}
