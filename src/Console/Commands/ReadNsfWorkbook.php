<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\CustomerServiceCalculationSnapshot;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ReadNsfWorkbook extends Command
{
    protected $signature = 'payroll:read-nsf-workbook {period} {source} {agent} {workbook} {sha256}';
    protected $description = 'Read one employee statement from an externally identified, SHA-256-pinned historical NSF workbook';

    public function handle(): int
    {
        // AutomationExecutor persists output from Artisan::call(). Payroll details belong only on the operator's pipe.
        if (PHP_SAPI !== 'cli' || $this->input::class !== ArgvInput::class
            || !($this->getOutput()->getOutput() instanceof ConsoleOutput)) return self::FAILURE;
        try {
            $statement = @CustomerServiceCalculationSnapshot::forPinnedNsfWorkbook(
                (string) $this->argument('workbook'), (string) $this->argument('sha256'), (string) $this->argument('period'),
                (string) $this->argument('source'), (string) $this->argument('agent')
            );
            $json = json_encode($statement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return self::FAILURE;
        }
        $this->getOutput()->writeln($json, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
        return self::SUCCESS;
    }
}
