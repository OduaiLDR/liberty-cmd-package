<?php

declare(strict_types=1);

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Services\SmsStoredDebtWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmsStoredDebtCommand extends Command
{
    protected $signature = 'sms:stored-debt
        {action : status, backfill, refresh or compare}
        {--scope=never : never or previous}
        {--batch=5000 : People at a time}
        {--limit=0 : Stop after this many people, for a sample}
        {--minutes=0 : Stop after this long}
        {--sample=100000 : People to compare}
        {--sweep : With refresh, walk everyone again from the start}';

    protected $description = 'Fills and refreshes stored SMS debt on TblProspects. Status and compare only read.';

    public function handle(): int
    {
        $worker = new SmsStoredDebtWorker(DB::connection('sqlsrv'), storage_path('app/private/sms-stored-debt/state.json'),
            function (string $line): void {
                $this->line($line);
                Log::info('SMS stored debt', ['detail' => $line]);
            });
        $action = (string) $this->argument('action');
        // The system frees this even if the process dies, so a crash can't block the next run.
        $lock = in_array($action, ['backfill', 'refresh'], true) ? $this->lock() : null;
        if ($lock === false) {
            $this->line('Another stored debt run is working.');

            return self::SUCCESS;
        }
        $batch = (int) $this->option('batch');
        $scope = (string) $this->option('scope');
        $result = match ($action) {
            'status' => $worker->status(),
            'backfill' => $worker->backfill($scope, $batch, (int) $this->option('limit'), (int) $this->option('minutes')),
            'refresh' => $this->refresh($worker, $batch),
            'compare' => $worker->compare($scope, (int) $this->option('sample'), $batch),
            default => null,
        };
        if ($result === null) {
            $this->error('Action must be status, backfill, refresh or compare.');

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        Log::info('SMS stored debt '.$action, $result);

        return self::SUCCESS;
    }

    /** @return resource|false */
    private function lock()
    {
        $path = storage_path('app/private/sms-stored-debt/worker.lock');
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0770, true);
        $handle = fopen($path, 'c');

        return $handle !== false && flock($handle, LOCK_EX | LOCK_NB) ? $handle : false;
    }

    private function refresh(SmsStoredDebtWorker $worker, int $batch): array
    {
        if (config('sms-exports.stored_debt_refresh', false) !== true) return ['stopped' => 'switched off'];
        if ($this->option('sweep')) $worker->restartSweep();

        return $worker->refresh($batch, (int) $this->option('minutes') ?: 25);
    }
}
