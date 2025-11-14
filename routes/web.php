<?php

use Cmd\Reports\Http\Controllers\CancelReportController;
use Cmd\Reports\Http\Controllers\ContactReportController;
use Cmd\Reports\Http\Controllers\MarketingReportController;
use Cmd\Reports\Http\Controllers\NsfReportController;
use Cmd\Reports\Http\Controllers\ProgramCompletionController;
use Cmd\Reports\Http\Controllers\EnrollmentReportController;
use Cmd\Reports\Http\Controllers\LeadReportController;
use Cmd\Reports\Http\Controllers\TrancheSummaryController;
use Cmd\Reports\Http\Controllers\TeamRanksController;
use Cmd\Reports\Http\Controllers\NegotiatorReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('cmd/reports')
    ->middleware(['web', 'auth'])
    ->group(function () {
        Route::get('/cancel-report', [CancelReportController::class, 'index'])
            ->name('cmd.reports.cancel_report');
        Route::get('/nsf-report', [NsfReportController::class, 'index'])
            ->name('cmd.reports.nsf_report');
        Route::get('/marketing-report', [MarketingReportController::class, 'index'])
            ->middleware('can:cmd.reports.marketing_report')
            ->name('cmd.reports.marketing_report');
        Route::patch('/marketing-report/{pk}/mail', [MarketingReportController::class, 'updateMailDropCost'])
            ->middleware('can:cmd.reports.marketing_report')
            ->name('cmd.reports.marketing_report.mail.update');
        Route::patch('/marketing-report/{pk}/data', [MarketingReportController::class, 'updateDataDropCost'])
            ->middleware('can:cmd.reports.marketing_report')
            ->name('cmd.reports.marketing_report.data.update');
        Route::get('/contact-report', [ContactReportController::class, 'index'])
            ->middleware('can:cmd.reports.contact_report')
            ->name('cmd.reports.contact_report');
        Route::get('/enrollment-report', [EnrollmentReportController::class, 'index'])
            ->middleware('can:cmd.reports.enrollment_report')
            ->name('cmd.reports.enrollment_report');
        Route::get('/lead-report', [LeadReportController::class, 'index'])
            ->middleware('can:cmd.reports.lead_report')
            ->name('cmd.reports.lead_report');
        Route::get('/negotiator-report', [NegotiatorReportController::class, 'index'])
            ->middleware('can:cmd.reports.team_ranks')
            ->name('cmd.reports.negotiator_report');
        Route::get('/team-ranks', [TeamRanksController::class, 'index'])
            ->middleware('can:cmd.reports.team_ranks')
            ->name('cmd.reports.team_ranks');
        Route::get('/program-completion', [ProgramCompletionController::class, 'index'])
            ->middleware('can:cmd.reports.program_completion')
            ->name('cmd.reports.program_completion');
        Route::get('/program-completion/data', [ProgramCompletionController::class, 'data'])
            ->middleware('can:cmd.reports.program_completion')
            ->name('cmd.reports.program_completion.data');

        Route::get('/tranche-summary', [TrancheSummaryController::class, 'index'])
            ->middleware('can:cmd.reports.tranche_summary')
            ->name('cmd.reports.tranche_summary');
    });
