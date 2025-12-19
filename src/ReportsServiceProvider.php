<?php

namespace Cmd\Reports;

use Illuminate\Support\ServiceProvider;
use Cmd\Reports\Console\Commands\TestDatabaseConnections;
use Cmd\Reports\Console\Commands\SyncBalances;
use Cmd\Reports\Console\Commands\SyncBalancesHistory;
use Cmd\Reports\Console\Commands\SyncEnrollmentPlans;
use Cmd\Reports\Console\Commands\SyncDebtAccounts;
use Cmd\Reports\Console\Commands\SyncSubmittedDate;
use Cmd\Reports\Console\Commands\SyncFirstPaymentDate;
use Cmd\Reports\Console\Commands\SyncFirstPaymentClearedDate;
use Cmd\Reports\Console\Commands\SyncTimeInProgram;

class ReportsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'reports');

        // Try new dbConfig first, fallback to legacy configs
        $dbConfigPath = __DIR__ . '/../config/dbConfig.php';
        $databaseConfigPath = __DIR__ . '/../config/database.php';
        $snowflakeConfigPath = __DIR__ . '/../config/snowflake.php';
        
        if (is_file($dbConfigPath)) {
            $this->mergeConfigFrom($dbConfigPath, 'dbConfig');
            $this->publishes([
                $dbConfigPath => config_path('dbConfig.php'),
            ], 'reports-config');
        } elseif (is_file($databaseConfigPath)) {
            // Legacy database config support
            $this->mergeConfigFrom($databaseConfigPath, 'database');
            $this->publishes([
                $databaseConfigPath => config_path('database.php'),
            ], 'reports-config');
        } elseif (is_file($snowflakeConfigPath)) {
            // Legacy snowflake config support
            $this->mergeConfigFrom($snowflakeConfigPath, 'snowflake');
            $this->publishes([
                $snowflakeConfigPath => config_path('snowflake.php'),
            ], 'reports-config');
        }

        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestDatabaseConnections::class,
                SyncBalances::class,
                SyncBalancesHistory::class,
                SyncEnrollmentPlans::class,
                SyncDebtAccounts::class,
                SyncSubmittedDate::class,
                SyncFirstPaymentDate::class,
                SyncFirstPaymentClearedDate::class,
                SyncTimeInProgram::class,
            ]);
        }

        // seed permissions for central and tenants
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Cmd\Reports\Console\Commands\SeedCmdReportPermissions::class,
            ]);

            $this->callAfterResolving('artisan', function (): void {
                $this->app->call(\Cmd\Reports\Console\Commands\SeedCmdReportPermissions::class);
            });
        }
    }

    public function register()
    {
        //
    }
}
