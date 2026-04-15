<?php

use App\Http\Middleware\PulseRequestRecorderMiddleware;
use App\Http\Middleware\SetSanctumDomain;
use App\Http\Middleware\XSS;
use Cmd\Reports\Http\Controllers\CancelReportController;
use Cmd\Reports\Http\Controllers\CapitalReportController;
use Cmd\Reports\Http\Controllers\ContactReportController;
use Cmd\Reports\Http\Controllers\EnrollmentReportController;
use Cmd\Reports\Http\Controllers\LeadReportController;
use Cmd\Reports\Http\Controllers\CreditorContactsReportController;
use Cmd\Reports\Http\Controllers\EpfPaidReportController;
use Cmd\Reports\Http\Controllers\EpfDueReportController;
use Cmd\Reports\Http\Controllers\MailDropExportController;
use Cmd\Reports\Http\Controllers\MailerDataReportController;
use Cmd\Reports\Http\Controllers\MarketingReportController;
use Cmd\Reports\Http\Controllers\MarketingAdminController;
use Cmd\Reports\Http\Controllers\DropSummaryController;
use Cmd\Reports\Http\Controllers\NegotiatorReportController;
use Cmd\Reports\Http\Controllers\NsfReportController;
use Cmd\Reports\Http\Controllers\ProgramCompletionController;
use Cmd\Reports\Http\Controllers\TeamRanksController;
use Cmd\Reports\Http\Controllers\TrancheSummaryController;
use Cmd\Reports\Http\Controllers\JordanExpensesReportController;
use Cmd\Reports\Http\Controllers\LlgExecAdminReportController;
use Cmd\Reports\Http\Controllers\VeritasReportController;
use Cmd\Reports\Http\Controllers\AgentRoiReportController;
use Cmd\Reports\Http\Controllers\SettlementAnalysisReportController;
use Cmd\Reports\Http\Controllers\EnrollmentModelReportController;
use Cmd\Reports\Http\Controllers\GrowthModelReportController;
use Cmd\Reports\Http\Controllers\EnrollmentFrequencyReportController;
use Cmd\Reports\Http\Controllers\LeadSummaryReportController;
use Cmd\Reports\Http\Controllers\ContactAnalysisReportController;
use Cmd\Reports\Http\Controllers\LdrPastDueReportController;
use Cmd\Reports\Http\Controllers\LegalReportController;
use Cmd\Reports\Http\Controllers\ReconsiderationReportController;
use Cmd\Reports\Http\Controllers\DroppedReportController;
use Cmd\Reports\Http\Controllers\CancellationReportController;
use Cmd\Reports\Http\Controllers\WelcomeLetterReportController;
use Cmd\Reports\Http\Controllers\OfferAuthorizationReportController;
use Cmd\Reports\Http\Controllers\UnclearedSettlementPaymentsReportController;
use Cmd\Reports\Http\Controllers\RetentionCommissionReportController;
use Cmd\Reports\Http\Controllers\WelcomePacketReportController;
use Cmd\Reports\Http\Controllers\LendingUsaProspectsReportController;
use Cmd\Reports\Http\Controllers\ClientSubmissionReportController;
use Cmd\Reports\Http\Controllers\AgentSummaryReportController;
use Cmd\Reports\Http\Controllers\SalesAdminReportController;
use Cmd\Reports\Http\Controllers\SettlementAdminReportController;
use Cmd\Reports\Http\Controllers\SalesManagerCommissionReportController;
use Cmd\Reports\Http\Controllers\SalesTeamLeaderCommissionReportController;
use Cmd\Reports\Http\Controllers\LeaderboardReportController;
use Cmd\Reports\Http\Controllers\InvoiceReportController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/**
 * Register all cmd reports routes.
 *
 * @param bool $withNames whether to attach the default route names (only use once to avoid name collisions)
 */
$registerCmdReportRoutes = function (bool $withNames = true): void {
    $name = static fn (string $n) => $withNames ? $n : null;

    Route::get('/cancel-report', [CancelReportController::class, 'index'])
        ->name($name('cmd.reports.cancel_report'));
    Route::get('/nsf-report', [NsfReportController::class, 'index'])
        ->name($name('cmd.reports.nsf_report'));
    Route::get('/mailer-data-report', [MailerDataReportController::class, 'index'])
        ->name($name('cmd.reports.mailer_data_report'));
    Route::get('/creditor-contacts-report', [CreditorContactsReportController::class, 'index'])
        ->name($name('cmd.reports.creditor_contacts_report'));
    Route::get('/epf-paid-report', [EpfPaidReportController::class, 'index'])
        ->name($name('cmd.reports.epf_paid_report'));
    Route::get('/epf-due-report', [EpfDueReportController::class, 'index'])
        ->name($name('cmd.reports.epf_due_report'));
    Route::get('/capital-report', [CapitalReportController::class, 'index'])
        ->name($name('cmd.reports.capital_report'));
    Route::get('/jordan-expenses-report', [JordanExpensesReportController::class, 'index'])
        ->name($name('cmd.reports.jordan_expenses_report'));
    Route::get('/llg-exec-admin-report', [LlgExecAdminReportController::class, 'index'])
        ->name($name('cmd.reports.llg_exec_admin_report'));
    Route::get('/veritas-report', [VeritasReportController::class, 'index'])
        ->name($name('cmd.reports.veritas_report'));
    Route::get('/agent-roi-report', [AgentRoiReportController::class, 'index'])
        ->name($name('cmd.reports.agent_roi_report'));
    Route::get('/lead-summary-report', [LeadSummaryReportController::class, 'index'])
        ->middleware('can:cmd.reports.lead_summary_report')
        ->name($name('cmd.reports.lead_summary_report'));
    Route::get('/contact-analysis-report', [ContactAnalysisReportController::class, 'index'])
        ->middleware('can:cmd.reports.contact_analysis_report')
        ->name($name('cmd.reports.contact_analysis_report'));
    Route::get('/settlement-analysis-report', [SettlementAnalysisReportController::class, 'index'])
        ->name($name('cmd.reports.settlement_analysis_report'));
    Route::get('/enrollment-model-report', [EnrollmentModelReportController::class, 'index'])
        ->name($name('cmd.reports.enrollment_model_report'));
    Route::get('/growth-model-report', [GrowthModelReportController::class, 'index'])
        ->name($name('cmd.reports.growth_model_report'));
    Route::get('/enrollment-frequency-report', [EnrollmentFrequencyReportController::class, 'index'])
        ->name($name('cmd.reports.enrollment_frequency_report'));
    Route::get('/ldr-past-due-report', [LdrPastDueReportController::class, 'index'])
        ->name($name('cmd.reports.ldr_past_due_report'));
    Route::get('/legal-report', [LegalReportController::class, 'index'])
        ->name($name('cmd.reports.legal_report'));
    Route::get('/reconsideration-report', [ReconsiderationReportController::class, 'index'])
        ->name($name('cmd.reports.reconsideration_report'));
    Route::get('/dropped-report', [DroppedReportController::class, 'index'])
        ->name($name('cmd.reports.dropped_report'));
    Route::get('/cancellation-report', [CancellationReportController::class, 'index'])
        ->name($name('cmd.reports.cancellation_report'));
    Route::get('/welcome-letter-report', [WelcomeLetterReportController::class, 'index'])
        ->name($name('cmd.reports.welcome_letter_report'));
    Route::get('/offer-authorization-report', [OfferAuthorizationReportController::class, 'index'])
        ->name($name('cmd.reports.offer_authorization_report'));
    Route::get('/uncleared-settlement-payments-report', [UnclearedSettlementPaymentsReportController::class, 'index'])
        ->name($name('cmd.reports.uncleared_settlement_payments_report'));
    Route::get('/retention-commission-report', [RetentionCommissionReportController::class, 'index'])
        ->name($name('cmd.reports.retention_commission_report'));
    Route::get('/welcome-packet-report', [WelcomePacketReportController::class, 'index'])
        ->name($name('cmd.reports.welcome_packet_report'));
    Route::get('/lending-usa-prospects-report', [LendingUsaProspectsReportController::class, 'index'])
        ->name($name('cmd.reports.lending_usa_prospects_report'));
    Route::get('/client-submission-report', [ClientSubmissionReportController::class, 'index'])
        ->name($name('cmd.reports.client_submission_report'));
    Route::get('/agent-summary-report', [AgentSummaryReportController::class, 'index'])
        ->name($name('cmd.reports.agent_summary_report'));
    Route::get('/sales-admin-report', [SalesAdminReportController::class, 'index'])
        ->name($name('cmd.reports.sales_admin_report'));
    Route::get('/settlement-admin-report', [SettlementAdminReportController::class, 'index'])
        ->name($name('cmd.reports.settlement_admin_report'));
    Route::get('/sales-manager-commission-report', [SalesManagerCommissionReportController::class, 'index'])
        ->name($name('cmd.reports.sales_manager_commission_report'));
    Route::get('/sales-team-leader-commission-report', [SalesTeamLeaderCommissionReportController::class, 'index'])
        ->name($name('cmd.reports.sales_team_leader_commission_report'));
    Route::get('/leaderboard-report', [LeaderboardReportController::class, 'index'])
        ->name($name('cmd.reports.leaderboard_report'));
    Route::get('/invoice-report', [InvoiceReportController::class, 'index'])
        ->name($name('cmd.reports.invoice_report'));
    Route::get('/training-report', [\Cmd\Reports\Http\Controllers\TrainingReportController::class, 'index'])
        ->name($name('cmd.reports.training_report'));
    Route::get('/marketing-report', [MarketingReportController::class, 'index'])
        ->middleware('can:cmd.reports.marketing_report')
        ->name($name('cmd.reports.marketing_report'));
    Route::patch('/marketing-report/{pk}/mail', [MarketingReportController::class, 'updateMailDropCost'])
        ->middleware('can:cmd.reports.marketing_report')
        ->name($name('cmd.reports.marketing_report.mail.update'));
    Route::patch('/marketing-report/{pk}/data', [MarketingReportController::class, 'updateDataDropCost'])
        ->middleware('can:cmd.reports.marketing_report')
        ->name($name('cmd.reports.marketing_report.data.update'));
    Route::get('/contact-report', [ContactReportController::class, 'index'])
        ->middleware('can:cmd.reports.contact_report')
        ->name($name('cmd.reports.contact_report'));
    Route::get('/enrollment-report', [EnrollmentReportController::class, 'index'])
        ->middleware('can:cmd.reports.enrollment_report')
        ->name($name('cmd.reports.enrollment_report'));
    Route::get('/lead-report', [LeadReportController::class, 'index'])
        ->middleware('can:cmd.reports.lead_report')
        ->name($name('cmd.reports.lead_report'));
    Route::get('/negotiator-report', [NegotiatorReportController::class, 'index'])
        ->middleware('can:cmd.reports.team_ranks')
        ->name($name('cmd.reports.negotiator_report'));
    Route::get('/team-ranks', [TeamRanksController::class, 'index'])
        ->middleware('can:cmd.reports.team_ranks')
        ->name($name('cmd.reports.team_ranks'));
    Route::get('/program-completion', [ProgramCompletionController::class, 'index'])
        ->middleware('can:cmd.reports.program_completion')
        ->name($name('cmd.reports.program_completion'));
    Route::get('/program-completion/data', [ProgramCompletionController::class, 'data'])
        ->middleware('can:cmd.reports.program_completion')
        ->name($name('cmd.reports.program_completion.data'));

    Route::get('/tranche-summary', [TrancheSummaryController::class, 'index'])
        ->middleware('can:cmd.reports.tranche_summary')
        ->name($name('cmd.reports.tranche_summary'));

    // New custom reports moved into package
    Route::get('/marketing-admin', [MarketingAdminController::class, 'index'])
        ->middleware('can:cmd.reports.marketing_admin_report')
        ->name($name('cmd.reports.marketing_admin'));

    Route::get('/drop-summary', [DropSummaryController::class, 'index'])
        ->middleware('can:cmd.reports.drop_summary_report')
        ->name($name('cmd.reports.drop_summary'));

    Route::get('/mail-drop-export', [MailDropExportController::class, 'index'])
        ->middleware('can:cmd.reports.mail_drop_export')
        ->name($name('cmd.reports.mail_drop_export'));
    Route::post('/mail-drop-export', [MailDropExportController::class, 'export'])
        ->middleware('can:cmd.reports.mail_drop_export')
        ->name($name('cmd.reports.mail_drop_export.export'));
};

// Central-domain access (no tenancy), with distinct names to avoid overriding tenant routes.
foreach (config('tenancy.central_domains', []) as $domain) {
    Route::domain($domain)
        ->prefix('cmd/reports')
        ->middleware([
            'web',
            'auth',
        ])
        ->group(function () use ($registerCmdReportRoutes) {
            $registerCmdReportRoutes(false);
        });
}

// Tenant (subdomain) access with tenancy middleware and canonical route names.
Route::prefix('cmd/reports')
    ->middleware([
        InitializeTenancyByDomainOrSubdomain::class,
        SetSanctumDomain::class,
        PreventAccessFromCentralDomains::class,
        PulseRequestRecorderMiddleware::class,
        XSS::class,
        'web',
        'auth',
    ])
    ->group(function () use ($registerCmdReportRoutes) {
        $registerCmdReportRoutes(true);
    });
