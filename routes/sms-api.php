<?php

use Cmd\Reports\Http\Controllers\SmsRunController;
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
        Route::get('mail-drop-export-report/runs', [SmsRunController::class, 'index'])->name('cmd.mail_drop_export.runs');
        Route::get('mail-drop-export-report/runs/availability', [SmsRunController::class, 'availability'])->name('cmd.mail_drop_export.runs.availability');
        Route::post('mail-drop-export-report/runs', [SmsRunController::class, 'store'])->name('cmd.mail_drop_export.runs.store');
        Route::get('mail-drop-export-report/runs/{requestId}', [SmsRunController::class, 'show'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.show');
        Route::get('mail-drop-export-report/runs/{requestId}/link', [SmsRunController::class, 'link'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.link');
        Route::post('mail-drop-export-report/runs/{requestId}/file', [SmsRunController::class, 'file'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.file');
        Route::post('mail-drop-export-report/runs/{requestId}/sent', [SmsRunController::class, 'sent'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.sent');
        Route::post('mail-drop-export-report/runs/{requestId}/discard', [SmsRunController::class, 'discard'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.discard');
        Route::post('mail-drop-export-report/runs/{requestId}/retry', [SmsRunController::class, 'retry'])->whereUuid('requestId')->name('cmd.mail_drop_export.runs.retry');
        Route::get('marketing-report/sms-history', [SmsWorkflowApiController::class, 'history'])->name('cmd.marketing.sms_history');
        Route::post('marketing-report/invoice', [SmsWorkflowApiController::class, 'invoice'])->name('cmd.marketing.invoice');
    });
}
