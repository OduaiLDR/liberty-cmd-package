<?php

namespace Cmd\Reports\Console\Commands;

use Cmd\Reports\Database\Seeders\CmdReportsPermissionSeeder;
use Illuminate\Console\Command;

class SeedCmdReportPermissions extends Command
{
    protected $signature = 'cmd-reports:seed-permissions';
    protected $description = 'Seed CMD report permissions for the central database and all tenant databases';

    public function handle(): int
    {
        $this->call(CmdReportsPermissionSeeder::class);

        $this->info('CMD report permissions seeded for central + tenants.');

        return self::SUCCESS;
    }
}