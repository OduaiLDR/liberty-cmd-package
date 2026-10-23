<?php

use Cmd\Reports\Http\Controllers\SmsRunController;
use Cmd\Reports\Http\Controllers\SmsOperationsController;
use Cmd\Reports\Http\Controllers\SmsWorkflowApiController;
use Illuminate\Support\Facades\Route;

// Other consumers retain their Blade routes. CMD hosts must provide both authorization guards.
if (class_exists(\App\Http\Middleware\VerifyCmdSession::class)
    && class_exists(\App\Http\Middleware\EnforceReportPolicy::class)) {
    Route::prefix('api/cmd')->middleware([
        'api', \App\Http\Middleware\VerifyCmdSession::class,
        \App\Http\Middleware\EnforceReportPolicy::class,
    ])->group(function () {
        Route::get('mail-drop-export-report/preview', [SmsWorkflowApiController::class, 'preview'])->name('cmd.mail_drop_export.preview');
        Route::post('mail-drop-export-report/export', [SmsWorkflowApiController::class, 'export'])->name('cmd.mail_drop_export.export');
        Route::get('mail-drop-export-report/export-status', [SmsWorkflowApiController::class, 'exportStatus'])->name('cmd.mail_drop_export.export_status');
        Route::post('mail-drop-export-report/selection', [SmsWorkflowApiController::class, 'selection'])->name('cmd.mail_drop_export.selection');
        Route::get('mail-drop-export-report/selection-status', [SmsWorkflowApiController::class, 'selectionStatus'])->name('cmd.mail_drop_export.selection_status');
        // Person-level SMS runs (TblSMSRuns). Route names keep the page's permission.
        Route::get('mail-drop-export-report/workflow', [SmsOperationsController::class, 'overview'])->name('cmd.mail_drop_export.workflow');
        Route::post('mail-drop-export-report/workflow/load', [SmsOperationsController::class, 'load'])->name('cmd.mail_drop_export.workflow.load');
        Route::post('mail-drop-export-report/workflow/load/{requestId}/retry', [SmsOperationsController::class, 'retryLoad'])->whereUuid('requestId')->name('cmd.mail_drop_export.workflow.load_retry');
        Route::post('mail-drop-export-report/workflow/feedback', [SmsOperationsController::class, 'feedback'])->name('cmd.mail_drop_export.workflow.feedback');
        Route::post('mail-drop-export-report/workflow/enrichment', [SmsOperationsController::class, 'enrichment'])->name('cmd.mail_drop_export.workflow.enrichment');
        Route::post('mail-drop-export-report/workflow/enrichment/{requestId}/retry', [SmsOperationsController::class, 'retryEnrichment'])->whereUuid('requestId')->name('cmd.mail_drop_export.workflow.enrichment_retry');
        Route::get('mail-drop-export-report/runs', [SmsRunController::class, 'index'])->name('cmd.mail_drop_export.runs');
        Route::get('mail-drop-export-report/runs/availability', [SmsRunController::class, 'availability'])->name('cmd.mail_drop_export.runs.availability');
        Route::post('mail-drop-export-report/runs', [SmsRunController::class, 'store'])->name('cmd.mail_drop_export.runs.store');
        Route::get('mail-drop-export-report/runs/{requestId}', [SmsRunController::class, 'show'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.show');
        Route::get('mail-drop-export-report/runs/{requestId}/link', [SmsRunController::class, 'link'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.link');
        Route::post('mail-drop-export-report/runs/{requestId}/file', [SmsRunController::class, 'file'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.file');
        Route::post('mail-drop-export-report/runs/{requestId}/sent', [SmsRunController::class, 'sent'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.sent');
        Route::post('mail-drop-export-report/runs/{requestId}/approve', [SmsRunController::class, 'approve'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.approve');
        Route::get('mail-drop-export-report/runs/{requestId}/details', [SmsRunController::class, 'details'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.details');
        Route::post('mail-drop-export-report/runs/{requestId}/review-only', [SmsRunController::class, 'reviewOnly'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.review_only');
        Route::post('mail-drop-export-report/runs/{requestId}/not-sent', [SmsRunController::class, 'notSent'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.not_sent');
        Route::post('mail-drop-export-report/runs/{requestId}/discard', [SmsRunController::class, 'discard'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.discard');
        Route::post('mail-drop-export-report/runs/{requestId}/retry', [SmsRunController::class, 'retry'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.retry');
        Route::get('marketing-report/sms-history', [SmsWorkflowApiController::class, 'history'])->name('cmd.marketing.sms_history');
        Route::post('marketing-report/invoice', [SmsWorkflowApiController::class, 'invoice'])->name('cmd.marketing.invoice');
    });
}
