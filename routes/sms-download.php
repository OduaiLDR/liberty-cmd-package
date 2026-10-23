<?php

use Cmd\Reports\Http\Controllers\SmsExportDownloadController;
use Cmd\Reports\Http\Controllers\SmsRunController;
use Cmd\Reports\Http\Middleware\SmsFreshSession;
use Cmd\Reports\Http\Middleware\SmsPermission;
use Illuminate\Support\Facades\Route;

// A signature is necessary but no longer sufficient: require a fresh host session
// and current SMS download rights. Non-CMD/older hosts fail closed.
if (class_exists(\App\Http\Middleware\VerifyCmdSession::class)
    && class_exists(\App\Http\Middleware\EnforceReportPolicy::class)) {
    Route::middleware(['api', 'signed:relative', SmsFreshSession::class,
        \App\Http\Middleware\VerifyCmdSession::class, SmsPermission::class.':download'])->group(function (): void {
        Route::get('api/cmd/mail-drop-export-report/download', SmsExportDownloadController::class)->name('cmd.sms_export.download');
        Route::get('api/cmd/mail-drop-export-report/runs/{requestId}/download', [SmsRunController::class, 'download'])
            ->whereUuid('requestId')->name('cmd.sms_run.download');
    });
}
