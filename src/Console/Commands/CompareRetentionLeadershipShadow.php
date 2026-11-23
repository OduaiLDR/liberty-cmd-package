<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Cmd\Reports\Services\RetentionStructuredCapture;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CompareRetentionLeadershipShadow extends Command
{
    protected $signature = 'payroll:compare-retention-leadership-shadow {period} {runId}';
    protected $description = 'Compare workbook and structured Retention leadership results for one shadow run';

    public function handle(): int
    {
        $console = $this->getOutput()->getOutput();
        if (PHP_SAPI !== 'cli' || $this->input::class !== ArgvInput::class || !($console instanceof ConsoleOutput)) {
            return self::FAILURE;
        }

        $period = (string) $this->argument('period');
        $runId = (string) $this->argument('runId');
        try {
            $periodEnd = RetentionStructuredCapture::periodEnd($period);
            $archive = RetentionStructuredCapture::archive();
            $artifacts = [];
            foreach (['retention_ldr', 'retention_plaw'] as $key) {
                $artifacts[$key] = @$archive->read($key, $period, $runId);
            }

            $manager = new GenerateRetentionManagerCommission;
            $reports = [];
            $matched = true;
            foreach (['retention_manager', 'retention_team_leader'] as $kind) {
                $start = hrtime(true);
                $structured = $manager->buildPayrollReviewRetentionStructuredSnapshot($kind, $period, $runId, $artifacts);
                $structuredMs = (hrtime(true) - $start) / 1_000_000;

                $start = hrtime(true);
                $workbook = @$manager->buildPayrollReviewLeadershipSnapshot($kind, $period, $periodEnd, $artifacts);
                $workbookMs = (hrtime(true) - $start) / 1_000_000;

                $reports[$kind] = [
                    'matches' => $structured === $workbook,
                    'workbookMs' => round($workbookMs, 3),
                    'structuredMs' => round($structuredMs, 3),
                ];
                $matched = $matched && $reports[$kind]['matches'];
            }
            $json = json_encode($reports, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $console->getErrorOutput()->writeln('Retention comparison requires a valid paired shadow capture.');
            return self::FAILURE;
        }

        $console->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return $matched ? self::SUCCESS : self::FAILURE;
    }
}
