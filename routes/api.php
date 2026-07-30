<?php

use App\Http\Controllers\Api\TimerAppController;
use App\Http\Controllers\Api\DtimerWifiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/licenses/activate', [TimerAppController::class, 'activate'])
        ->name('licenses.activate');

    Route::post('/licenses/status', [TimerAppController::class, 'status'])
        ->name('licenses.status');

    Route::post('/licenses/heartbeat', [TimerAppController::class, 'heartbeat'])
        ->name('licenses.heartbeat');

    Route::post('/licenses/coin-sales/batch', [TimerAppController::class, 'storeCoinSales'])
        ->name('licenses.coin-sales.batch');

    Route::post('/dtimer/machines/link', [DtimerWifiController::class, 'link'])
        ->name('dtimer.machines.link');

    Route::post('/dtimer/heartbeat', [DtimerWifiController::class, 'heartbeat'])
        ->name('dtimer.heartbeat');

    Route::post('/dtimer/coin-sales/batch', [DtimerWifiController::class, 'storeCoinSales'])
        ->name('dtimer.coin-sales.batch');

    Route::get('/updates/latest', [TimerAppController::class, 'latestUpdate'])
        ->name('updates.latest');

    Route::get('/updates', [TimerAppController::class, 'updates'])
        ->name('updates.index');

    Route::get('/updates/{appUpdate}/download', [TimerAppController::class, 'download'])
        ->whereNumber('appUpdate')
        ->name('updates.download');
});

Route::prefix('licenses')->group(function (): void {
    Route::post('/activate', [TimerAppController::class, 'activate']);
    Route::post('/status', [TimerAppController::class, 'status']);
});
