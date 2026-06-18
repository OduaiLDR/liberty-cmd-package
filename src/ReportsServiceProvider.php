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
use Cmd\Reports\Console\Commands\GenerateAgentSummaryReport\GenerateAgentSummaryReport;
use Cmd\Reports\Console\Commands\GenerateDPPPastDueReport\GenerateDPPPastDueReport;
use Cmd\Reports\Console\Commands\GenerateResumePayments\GenerateResumePayments;
use Cmd\Reports\Pmod\Console\Commands\DumpForthStagesStatuses;
use Cmd\Reports\Console\Commands\GenerateNSFReport\GenerateNSFReport;
use Cmd\Reports\Console\Commands\GeneratePauseHoldReport\GeneratePauseHoldReport;
use Cmd\Reports\Console\Commands\GenerateGraduationReport\GenerateGraduationReport;
use Cmd\Reports\Console\Commands\GenerateSuppressionReport\GenerateSuppressionReport;
use Cmd\Reports\Console\Commands\GenerateWelcomeLetterReport\GenerateWelcomeLetterReport;
use Cmd\Reports\Console\Commands\GenerateWelcomePacketReport\GenerateWelcomePacketReport;
use Cmd\Reports\Console\Commands\GenerateDroppedReport\GenerateDroppedReport;
use Cmd\Reports\Console\Commands\GenerateScrubListReport\GenerateScrubListReport;
use Cmd\Reports\Console\Commands\GenerateScrubListReportPLAW\GenerateScrubListReportPLAW;
use Cmd\Reports\Console\Commands\GenerateScrubListReportLDR\GenerateScrubListReportLDR;
use Cmd\Reports\Console\Commands\GenerateSettlementReports\GenerateSettlementReports;
use Cmd\Reports\Console\Commands\GenerateLookbackSummaryReport\GenerateLookbackSummaryReport;
use Cmd\Reports\Console\Commands\GenerateReportSummary\GenerateReportSummary;
use Cmd\Reports\Console\Commands\GenerateSyncSummary\GenerateSyncSummary;
use Cmd\Reports\Console\Commands\GenerateRetentionCommissionReport\GenerateRetentionCommissionReport;
use Cmd\Reports\Console\Commands\GenerateCancelRequestsAgentReport\GenerateCancelRequestsAgentReport;
use Cmd\Reports\Console\Commands\GenerateRetentionBonusCommission\GenerateRetentionBonusCommission;
use Cmd\Reports\Console\Commands\SyncContactsData;
use Cmd\Reports\Console\Commands\SyncCollectionCompanies;
use Cmd\Reports\Console\Commands\SyncLastDepositDate;
use Cmd\Reports\Console\Commands\SyncVeritasTransactions;
use Cmd\Reports\Console\Commands\ProcessAgentTrainingCompletions;
use Cmd\Reports\Console\Commands\SyncPhoneNumbers;
use Cmd\Reports\Console\Commands\SyncCalls;
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
use Cmd\Reports\Console\Commands\SyncAgentCommissionTierEnrollments;
use Cmd\Reports\Console\Commands\GenerateEmployeesReport\GenerateEmployeesReport;
use Cmd\Reports\Console\Commands\SyncLeaderboardRecords\SyncLeaderboardRecords;


class ReportsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/pmod.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'reports');

        // PMOD migrations ship with the package but are NOT auto-loaded.
        // Consumers must explicitly opt in by publishing them:
        //     php artisan vendor:publish --tag=pmod-migrations
        // This gives sensitive deployments full control over when/how schema
        // changes are applied (and lets tenancy-aware apps publish into a
        // tenant migrations directory of their choice).
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'pmod-migrations');

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
                GenerateAgentSummaryReport::class,
                GenerateDPPPastDueReport::class,
                DumpForthStagesStatuses::class,
                GenerateNSFReport::class,
                GeneratePauseHoldReport::class,
                GenerateGraduationReport::class,
                GenerateSuppressionReport::class,
                GenerateWelcomeLetterReport::class,
                GenerateWelcomePacketReport::class,
                GenerateDroppedReport::class,
                GenerateScrubListReport::class,
                GenerateScrubListReportPLAW::class,
                GenerateScrubListReportLDR::class,
                GenerateSettlementReports::class,
                GenerateLookbackSummaryReport::class,
                GenerateReportSummary::class,
                GenerateSyncSummary::class,
                GenerateRetentionCommissionReport::class,
                GenerateCancelRequestsAgentReport::class,
                GenerateRetentionBonusCommission::class,
                SyncContactsData::class,
                SyncCollectionCompanies::class,
                SyncLastDepositDate::class,
                SyncVeritasTransactions::class,
                ProcessAgentTrainingCompletions::class,
                SyncPhoneNumbers::class,
                SyncCalls::class,
                SyncNegotiatorPayrollData::class,
                RefreshForthApiTokens::class,
                UpdateLendingUSAStatuses::class,
                SyncAgentCommissionTierEnrollments::class,
                GenerateEmployeesReport::class,
                SyncLeaderboardRecords::class,
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

        // Singleton so Generate:resume-payments reuses one Panther/Chromium
        // browser session across all contacts in a run.
        $this->app->singleton(\Cmd\Reports\Pmod\Services\DppSeleniumService::class, static function (): \Cmd\Reports\Pmod\Services\DppSeleniumService {
            return \Cmd\Reports\Pmod\Services\DppSeleniumService::fromConfig();
        });

        // DebtPayPro "post" data-API client for Phase 4 status/note writes
        // (replicates the VBA UpdateCRMData* subs). Constructor needs config, so
        // bind it explicitly rather than relying on autowiring.
        $this->app->singleton(\Cmd\Reports\Pmod\Services\DppDataClient::class, static function (): \Cmd\Reports\Pmod\Services\DppDataClient {
            return \Cmd\Reports\Pmod\Services\DppDataClient::fromConfig();
        });

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
