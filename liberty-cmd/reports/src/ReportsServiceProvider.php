<?php

namespace Cmd\Reports;

use Illuminate\Support\ServiceProvider;
use Cmd\Reports\Console\Commands\TestSnowflakeJWT;

class ReportsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'reports');

        $configPath = __DIR__ . '/../config/snowflake.php';
        if (is_file($configPath)) {
            $this->mergeConfigFrom($configPath, 'snowflake');
            $this->publishes([
                $configPath => config_path('snowflake.php'),
            ], 'reports-config');
        }


        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestSnowflakeJWT::class,
            ]);
        }
    }

    public function register()
    {
        //
    }
}
