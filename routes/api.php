<?php

use App\Http\Controllers\Api\TimerAppController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/licenses/activate', [TimerAppController::class, 'activate'])
        ->name('licenses.activate');

    Route::post('/licenses/status', [TimerAppController::class, 'status'])
        ->name('licenses.status');

    Route::post('/licenses/revoke', [TimerAppController::class, 'revoke'])
        ->name('licenses.revoke');

    Route::post('/licenses/heartbeat', [TimerAppController::class, 'heartbeat'])
        ->name('licenses.heartbeat');

    Route::get('/updates/latest', [TimerAppController::class, 'latestUpdate'])
        ->name('updates.latest');

    Route::get('/updates/{appUpdate}/download', [TimerAppController::class, 'download'])
        ->whereNumber('appUpdate')
        ->name('updates.download');
});

Route::prefix('licenses')->group(function (): void {
    Route::post('/activate', [TimerAppController::class, 'activate']);
    Route::post('/status', [TimerAppController::class, 'status']);
    Route::post('/revoke', [TimerAppController::class, 'revoke']);
});
