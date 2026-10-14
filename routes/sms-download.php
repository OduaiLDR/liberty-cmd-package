<?php

use Cmd\Reports\Http\Controllers\SmsExportDownloadController;
use Cmd\Reports\Http\Controllers\SmsRunController;
use Illuminate\Support\Facades\Route;

// The authenticated status API grants a short-lived signed download capability.
// Stream directly from CMD runner rather than through Lambda/API Gateway.
Route::get('api/cmd/mail-drop-export-report/download', SmsExportDownloadController::class)
    ->middleware(['api', 'signed:relative'])->name('cmd.sms_export.download');
Route::get('api/cmd/mail-drop-export-report/runs/{requestId}/download', [SmsRunController::class, 'download'])
    ->whereUuid('requestId')->middleware(['api', 'signed:relative'])->name('cmd.sms_run.download');
