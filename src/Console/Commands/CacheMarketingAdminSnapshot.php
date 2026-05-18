<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Repositories\MarketingAdminRepository;
use Illuminate\Console\Command;

class CacheMarketingAdminSnapshot extends Command
{
    protected $signature   = 'cmd:marketing-admin-snapshot';
    protected $description = 'Build and cache the nightly Marketing Admin snapshot from Azure SQL.';

    public function handle(MarketingAdminRepository $repo): int
    {
        $this->info('Building Marketing Admin snapshot...');

        $at = $repo->cacheSnapshot();

        $this->info('Snapshot cached at '.$at->toDateTimeString());

        return Command::SUCCESS;
    }
}
