<?php

use Cmd\Reports\Http\Controllers\ProspectListController;
use Cmd\Reports\Http\Middleware\ProspectListServiceAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('api/internal/prospect-lists')
    ->middleware(['api', ProspectListServiceAuth::class])
    ->name('cmd.prospect-list.')
    ->group(function (): void {
        Route::get('catalog', [ProspectListController::class, 'catalog'])->name('catalog');
        Route::post('counts', [ProspectListController::class, 'count'])->name('counts');
        Route::post('exports', [ProspectListController::class, 'export'])->name('exports');
        Route::get('{id}', [ProspectListController::class, 'show'])->whereUuid('id')->name('show');
        Route::get('{id}/download/{part?}', [ProspectListController::class, 'download'])->whereUuid('id')->whereNumber('part')->name('download');
        Route::post('{id}/inspect', [ProspectListController::class, 'inspect'])->whereUuid('id')->name('inspect');
    });
