<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Console\Commands\GenerateRetentionManagerCommission\GenerateRetentionManagerCommission;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ReadNsfTeamLeaderShadow extends Command
{
    private const MAX_EXPORT_BYTES = 8 * 1024 * 1024;

    protected $signature = 'payroll:read-nsf-team-leader-shadow {period} {runId}';
    protected $description = 'Calculate an NSF Team Leader comparison from a trusted shadow export on stdin';

    public function handle(): int
    {
        // AutomationExecutor persists Artisan::call() output. Payroll details belong on the operator's pipe.
        if (PHP_SAPI !== 'cli' || $this->input::class !== ArgvInput::class
            || !($this->getOutput()->getOutput() instanceof ConsoleOutput)) {
            return self::FAILURE;
        }

        try {
            $input = @stream_get_contents(STDIN, self::MAX_EXPORT_BYTES + 1);
            if ($input === false || strlen($input) > self::MAX_EXPORT_BYTES) {
                return self::FAILURE;
            }
            $envelope = json_decode($input, true, depth: 8, flags: JSON_THROW_ON_ERROR);
            unset($input);
            if (!is_array($envelope)) {
                return self::FAILURE;
            }
            $snapshot = (new GenerateRetentionManagerCommission)->buildPayrollReviewNsfStructuredSnapshot(
                (string) $this->argument('period'),
                (string) $this->argument('runId'),
                $envelope,
            );
            $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::FAILURE;
        }

        $this->getOutput()->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

        return self::SUCCESS;
    }
}
