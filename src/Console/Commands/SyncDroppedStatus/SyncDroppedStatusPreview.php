<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands\SyncDroppedStatus;

use Cmd\Reports\Services\DBConnector;
use Cmd\Reports\Services\DroppedStatusSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class SyncDroppedStatusPreview extends Command
{
    protected $signature = 'Sync:dropped-status-preview
        {--source=ALL : Run ALL, LDR, or PLAW}
        {--limit= : Limit selected candidate contacts per portal}
        {--output-dir= : Optional output directory for the generated workbooks}';

    protected $description = 'Preview dropped-status CRM updates for LDR and PLAW without sending CRM requests.';

    public function handle(DroppedStatusSyncService $sync, Formatter $formatter): int
    {
        $source = strtoupper(trim((string) $this->option('source')));
        $outputDir = trim((string) $this->option('output-dir'));
        $failed = 0;

        try {
            $portals = $sync->portals($source);
            $limit = $this->parseLimit($this->option('limit'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->info('[INFO] Dropped status preview: starting. No CRM updates will be sent.');

        foreach ($portals as $portal) {
            $tenant = strtolower($portal['source']);
            // Preview must remain database-write-free even when the application's
            // default cache store is database-backed.
            $lock = Cache::store('file')->lock("sync-dropped-status:{$tenant}", 7200);

            if (! $lock->get()) {
                $failed++;
                $this->error("[ERROR] {$portal['source']} is already running; preview skipped.");
                continue;
            }

            try {
                $this->info("[INFO] === {$portal['source']} ===");
                $snowflake = DBConnector::fromEnvironment($portal['env']);
                $report = $sync->buildReport($snowflake, $limit);
                $result = $formatter->buildWorkbook(
                    $report,
                    $portal['source'],
                    $outputDir !== '' ? $outputDir : null
                );

                $this->printSummary($portal['source'], $report, $result['path']);
                Log::info('SyncDroppedStatusPreview: portal finished', [
                    'portal' => $portal['source'],
                    'scanned' => $report['scanned_count'],
                    'candidates' => $report['candidate_count'],
                    'selected' => $report['selected_count'],
                    'skipped' => count($report['skipped']),
                    'workbook' => $result['path'],
                    'crm_updates_sent' => false,
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("[ERROR] {$portal['source']} preview failed: ".$e->getMessage());
                Log::error('SyncDroppedStatusPreview: portal failed', [
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

    /** @param array<string,mixed> $report */
    private function printSummary(string $source, array $report, string $path): void
    {
        $this->info(sprintf(
            '[INFO] %s scanned=%d candidates=%d selected=%d skipped_dropped=%d skipped_system_cancel=%d skipped_missing_status=%d duplicates=%d',
            $source,
            $report['scanned_count'],
            $report['candidate_count'],
            $report['selected_count'],
            $report['skipped_dropped_count'],
            $report['skipped_system_cancel_count'],
            $report['skipped_missing_status_count'],
            $report['duplicate_count']
        ));
        $this->info('[INFO] No CRM updates were sent.');
        $this->info("[INFO] Workbook: {$path}");
        $this->line($path);
    }
}
