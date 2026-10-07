<?php

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
        Route::get('marketing-report/sms-history', [SmsWorkflowApiController::class, 'history'])->name('cmd.marketing.sms_history');
        Route::post('marketing-report/invoice', [SmsWorkflowApiController::class, 'invoice'])->name('cmd.marketing.invoice');
    });
}
