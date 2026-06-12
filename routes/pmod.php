<?php

declare(strict_types=1);

use Cmd\Reports\Pmod\Http\Controllers\PmodCsAgentRequestController;
use Cmd\Reports\Pmod\Http\Controllers\PmodPortalWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::post('/webhooks/pmod-approval', PmodPortalWebhookController::class)
        ->name('webhooks.pmod-approval');
});

Route::prefix('api')->middleware('api')->group(function (): void {
    Route::post('/pmod/request', PmodCsAgentRequestController::class)
        ->name('api.pmod.request');
});
