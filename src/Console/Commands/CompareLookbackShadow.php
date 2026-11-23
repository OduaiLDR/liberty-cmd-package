<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class CompareLookbackShadow extends Command
{
    protected $signature = 'payroll:compare-lookback-shadow {period} {runId} {agentKey : Exact normalized employee name}';
    protected $description = 'Compare one employee’s workbook and structured Retention Lookback calculation';

    public function handle(PayrollReportArtifactArchive $archive): int
    {
        $console = $this->getOutput()->getOutput();
        if (PHP_SAPI !== 'cli' || $this->input::class !== ArgvInput::class || !($console instanceof ConsoleOutput)) {
            return self::FAILURE;
        }

        $period = (string) $this->argument('period');
        $runId = (string) $this->argument('runId');
        $agentKey = (string) $this->argument('agentKey');
        try {
            $artifacts = [];
            foreach (['lookback_ldr', 'lookback_plaw'] as $key) {
                $artifacts[$key] = @$archive->read($key, $period, $runId);
            }
            $start = hrtime(true);
            $structured = CustomerServiceCalculationSnapshot::forStructuredLookbackAgent($artifacts, $period, $runId, $agentKey, 'both');
            $structuredMs = (hrtime(true) - $start) / 1_000_000;
            if ($structured['components'] === []) {
                throw new \RuntimeException('The selected employee is absent from this capture.');
            }

            $start = hrtime(true);
            $workbook = @CustomerServiceCalculationSnapshot::forArchivedAgent($artifacts, $period, 'retention_lookback', $agentKey, 'both');
            $workbookMs = (hrtime(true) - $start) / 1_000_000;
            $matches = $structured === $workbook;
            $result = json_encode([
                'matches' => $matches,
                'workbookMs' => round($workbookMs, 3),
                'structuredMs' => round($structuredMs, 3),
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $console->getErrorOutput()->writeln('Lookback comparison requires a valid paired shadow capture and a captured employee.');
            return self::FAILURE;
        }

        $console->writeln($result, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return $matches ? self::SUCCESS : self::FAILURE;
    }
}
