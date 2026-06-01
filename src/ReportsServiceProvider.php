<?php

namespace Cmd\Reports;

use Illuminate\Support\ServiceProvider;
use Cmd\Reports\Console\Commands\TestDatabaseConnections;
use Cmd\Reports\Console\Commands\SyncBalances;
use Cmd\Reports\Console\Commands\SyncBalancesHistory;
use Cmd\Reports\Console\Commands\SyncEPFData;
use Cmd\Reports\Console\Commands\UpdateEPFRates;
use Cmd\Reports\Console\Commands\SyncEnrollmentPlans;
use Cmd\Reports\Console\Commands\SyncDebtAccounts;
use Cmd\Reports\Console\Commands\SyncSubmittedDate;
use Cmd\Reports\Console\Commands\SyncFirstPaymentDate;
use Cmd\Reports\Console\Commands\SyncFirstPaymentClearedDate;
use Cmd\Reports\Console\Commands\SyncTimeInProgram;
use Cmd\Reports\Console\Commands\SyncSettlementData;
use Cmd\Reports\Console\Commands\SyncSettledDebtsData;
use Cmd\Reports\Console\Commands\SyncEnrollmentStatus;
use Cmd\Reports\Console\Commands\SyncEnrollmentData;
use Cmd\Reports\Console\Commands\SyncVerifiedDebts;
use Cmd\Reports\Console\Commands\GenerateCompanyStatsReport\GenerateCompanyStatsReport;
use Cmd\Reports\Console\Commands\GenerateLegalReport\GenerateLegalReport;
use Cmd\Reports\Console\Commands\GenerateWelcomeLetterReport\GenerateWelcomeLetterReport;
use Cmd\Reports\Console\Commands\GenerateWelcomePacketReport\GenerateWelcomePacketReport;
use Cmd\Reports\Console\Commands\GenerateDroppedReport\GenerateDroppedReport;
use Cmd\Reports\Console\Commands\GenerateScrubListReport\GenerateScrubListReport;
use Cmd\Reports\Console\Commands\GenerateLookbackSummaryReport\GenerateLookbackSummaryReport;
use Cmd\Reports\Console\Commands\GenerateReportSummary\GenerateReportSummary;
use Cmd\Reports\Console\Commands\GenerateSyncSummary\GenerateSyncSummary;
use Cmd\Reports\Console\Commands\SyncContactsData;
use Cmd\Reports\Console\Commands\SyncCollectionCompanies;
use Cmd\Reports\Console\Commands\SyncLastDepositDate;
use Cmd\Reports\Console\Commands\SyncVeritasTransactions;
use Cmd\Reports\Console\Commands\RefreshForthApiTokens;
use Cmd\Reports\Console\Commands\SyncNegotiatorPayrollData;
use Cmd\Reports\Console\Commands\UpdateLendingUSAStatuses;
use Cmd\Reports\Pmod\Actions\AdditionalPaymentAction;
use Cmd\Reports\Pmod\Actions\CapturePmodRequestAction;
use Cmd\Reports\Pmod\Actions\ChangePaymentAction;
use Cmd\Reports\Pmod\Actions\IncreaseAllFuturePaymentsAction;
use Cmd\Reports\Pmod\Actions\PaymentRefundAction;
use Cmd\Reports\Pmod\Actions\PmodExtendProgramAction;
use Cmd\Reports\Pmod\Actions\PmodIncreasePaymentsAction;
use Cmd\Reports\Pmod\Actions\PmodIncreasePaymentsAndExtendProgramAction;
use Cmd\Reports\Pmod\Actions\PmodLumpSumAction;
use Cmd\Reports\Pmod\Actions\RescheduleAllPaymentsAction;
use Cmd\Reports\Pmod\Actions\SkipPaymentAction;
use Cmd\Reports\Pmod\Contracts\PmodExecutionGateway;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Services\ForthPayPmodExecutionGateway;
use Cmd\Reports\Pmod\Services\PmodDispatcher;
use Cmd\Reports\Pmod\Services\PmodEmailNotificationService;



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
                SyncEPFData::class,
                UpdateEPFRates::class,
                SyncSettlementData::class,
                SyncSettledDebtsData::class,
                SyncEnrollmentStatus::class,
                SyncEnrollmentData::class,
                SyncVerifiedDebts::class,
                GenerateCompanyStatsReport::class,
                GenerateLegalReport::class,
                GenerateWelcomeLetterReport::class,
                GenerateWelcomePacketReport::class,
                GenerateDroppedReport::class,
                GenerateScrubListReport::class,
                GenerateLookbackSummaryReport::class,
                GenerateReportSummary::class,
                GenerateSyncSummary::class,
                SyncContactsData::class,
                SyncCollectionCompanies::class,
                SyncLastDepositDate::class,
                SyncVeritasTransactions::class,
                SyncNegotiatorPayrollData::class,
                RefreshForthApiTokens::class,
                UpdateLendingUSAStatuses::class,
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
        // Bind the Forth CRM/Pay execution gateway as the default implementation.
        // Host apps that need a different gateway can override this binding in their
        // own service provider after calling parent::register().
        $this->app->singleton(PmodExecutionGateway::class, ForthPayPmodExecutionGateway::class);
        $this->app->singleton(PmodEmailNotificationService::class);

        $this->app->singleton(PmodDispatcher::class, function ($app) {
            $gateway = $app->make(PmodExecutionGateway::class);
            $liveDraftUpdates = (bool) config('services.pmod.live_draft_updates', false);

            return new PmodDispatcher([
                // Full automation handlers
                new ChangePaymentAction($gateway, $liveDraftUpdates),
                new AdditionalPaymentAction($gateway, $liveDraftUpdates),
                new SkipPaymentAction($gateway, $liveDraftUpdates),
                new RescheduleAllPaymentsAction($gateway, $liveDraftUpdates),
                new IncreaseAllFuturePaymentsAction($gateway, $liveDraftUpdates),
                new PmodLumpSumAction($gateway, $liveDraftUpdates),
                new PmodIncreasePaymentsAction($gateway, $liveDraftUpdates),
                new PmodIncreasePaymentsAndExtendProgramAction($gateway, $liveDraftUpdates),
                new PmodExtendProgramAction($gateway, $liveDraftUpdates),
                new PaymentRefundAction($gateway, $liveDraftUpdates),
                new CapturePmodRequestAction($gateway, PmodActionType::VOID_SETTLEMENT),
                new CapturePmodRequestAction($gateway, PmodActionType::SETTLEMENT_APPROVAL),
            ]);
        });
    }
}
