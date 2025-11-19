<?php

use App\Http\Middleware\PulseRequestRecorderMiddleware;
use App\Http\Middleware\SetSanctumDomain;
use App\Http\Middleware\XSS;
use Cmd\Reports\Http\Controllers\CancelReportController;
use Cmd\Reports\Http\Controllers\ContactReportController;
use Cmd\Reports\Http\Controllers\EnrollmentReportController;
use Cmd\Reports\Http\Controllers\LeadReportController;
use Cmd\Reports\Http\Controllers\MarketingReportController;
use Cmd\Reports\Http\Controllers\NegotiatorReportController;
use Cmd\Reports\Http\Controllers\NsfReportController;
use Cmd\Reports\Http\Controllers\ProgramCompletionController;
use Cmd\Reports\Http\Controllers\TeamRanksController;
use Cmd\Reports\Http\Controllers\TrancheSummaryController;
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
