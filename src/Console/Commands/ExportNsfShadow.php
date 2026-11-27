<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\NsfStructuredCapture;
use Cmd\Reports\Services\PayrollReportArtifactArchive;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ExportNsfShadow extends Command
{
    protected $signature = 'payroll:export-nsf-shadow {period} {runId}
        {--with-invocation-proof : Include the invocation evidence required by the PHP Team Leader comparison reader}';
    protected $description = 'Export both verified NSF structured shadow captures for a payroll run';

    public function handle(PayrollReportArtifactArchive $archive): int
    {
        // Runner's automation executor persists Artisan::call() output in its job log.
        // Only the direct Artisan CLI supplies ArgvInput and a real console output together.
        if (PHP_SAPI !== 'cli' || $this->input::class !== ArgvInput::class
            || !($this->getOutput()->getOutput() instanceof ConsoleOutput)) {
            return self::FAILURE;
        }

        try {
            $period = (string) $this->argument('period');
            $runId = (string) $this->argument('runId');
            $artifacts = [];
            foreach (['nsf_ldr', 'nsf_plaw'] as $key) {
                // Filesystem warnings can expose private archive paths.
                $artifacts[$key] = @$archive->read($key, $period, $runId);
            }
            $envelope = NsfStructuredCapture::export($artifacts, $period, $runId, true, (bool) $this->option('with-invocation-proof'));
        } catch (\Throwable) {
            return self::FAILURE;
        }

        $this->getOutput()->writeln($envelope, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return self::SUCCESS;
    }
}
