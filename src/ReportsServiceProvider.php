<?php

namespace Cmd\Reports;

use Illuminate\Support\ServiceProvider;

class ReportsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'reports');
        
        $this->mergeConfigFrom(__DIR__ . '/../config/snowflake.php', 'reports');
        $this->publishes([
            __DIR__ . '/../config/reports.php' => config_path('snowflake.php'),
        ], 'reports-config');
    }

    public function register()
    {
        //
    }
}
