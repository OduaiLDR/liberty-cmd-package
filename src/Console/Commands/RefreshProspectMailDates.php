<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Repositories\ProspectMailDateIndex;
use Cmd\Reports\Services\SmsWorkflowBusyException;
use Cmd\Reports\Services\SmsWorkflowLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RefreshProspectMailDates extends Command
{
    protected $signature = 'prospect-lists:refresh-mail-dates
        {--batch-size=5000 : Maximum prospects per batch}
        {--batches=12 : Maximum batches per invocation}
        {--rebuild : Invalidate and restart after in-place source corrections}';

    protected $description = 'Build an indexed lookup of the latest mailing date for each prospect';

    public function handle(): int
    {
        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20000]]);
        $batches = filter_var($this->option('batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($batchSize === false || $batches === false) {
            $this->error('Choose a batch size from 1 to 20000 and a batch count from 1 to 500.');

            return self::INVALID;
        }

        $db = DB::connection('sqlsrv');
        $index = new ProspectMailDateIndex($db);
        if (! $index->installed()) {
            $this->error('Apply the prospect mail date index migration first.');

            return self::FAILURE;
        }

        try {
            $result = SmsWorkflowLock::run('prospect-mail-dates', fn (): array => $index->refresh(
                now('America/Los_Angeles'), $batchSize, $batches, (bool) $this->option('rebuild'),
            ));
        } catch (SmsWorkflowBusyException) {
            $this->line('Another prospect mailing-date refresh is running.');

            return self::SUCCESS;
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
