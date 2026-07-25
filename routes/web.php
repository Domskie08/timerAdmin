<?php

use App\Http\Controllers\Admin\AppUpdateController;
use App\Http\Controllers\Admin\ClientController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DashboardPhotoController as AdminDashboardPhotoController;
use App\Http\Controllers\Admin\LicenseController;
use App\Http\Controllers\Admin\NewsPostController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\DashboardPhotoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Client\AuthController as ClientAuthController;
use App\Http\Controllers\Client\DashboardController as ClientDashboardController;
use App\Http\Controllers\Client\DtimerWifiController as ClientDtimerWifiController;
use App\Http\Controllers\Client\LicensingController as ClientLicensingController;
use App\Http\Controllers\Client\PcTimerController as ClientPcTimerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SupportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/login', '/admin/login');

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/support', [SupportController::class, 'index'])->name('support');
Route::get('/dashboard-photos/{dashboardPhoto}', [DashboardPhotoController::class, 'show'])
    ->whereNumber('dashboardPhoto')
    ->name('dashboard-photos.show');

Route::middleware('guest')->group(function (): void {
    Route::get('/admin/login', [LoginController::class, 'create'])->name('login');
    Route::post('/admin/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/client/login', [ClientAuthController::class, 'createLogin'])->name('client.login');
    Route::post('/client/login', [ClientAuthController::class, 'storeLogin'])->name('client.login.store');
    Route::get('/client/register', [ClientAuthController::class, 'createRegister'])->name('client.register');
    Route::post('/client/register', [ClientAuthController::class, 'storeRegister'])->name('client.register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/licenses', [LicenseController::class, 'store'])->name('licenses.store');
    Route::post('/licenses/pc', [LicenseController::class, 'storePc'])->name('licenses.pc.store');
    Route::post('/licenses/pisowifi', [LicenseController::class, 'storePisoWifi'])->name('licenses.pisowifi.store');
    Route::get('/licenses/export', [LicenseController::class, 'export'])->name('licenses.export');
    Route::post('/licenses/{license}/renew', [LicenseController::class, 'renew'])
        ->whereNumber('license')
        ->name('licenses.renew');
    Route::delete('/licenses/{license}', [LicenseController::class, 'destroy'])
        ->whereNumber('license')
        ->name('licenses.destroy');
    Route::post('/news', [NewsPostController::class, 'store'])->name('news.store');
    Route::post('/updates', [AppUpdateController::class, 'store'])->name('updates.store');
    Route::delete('/updates/{appUpdate}', [AppUpdateController::class, 'destroy'])->name('updates.destroy');
    Route::post('/dashboard-photos', [AdminDashboardPhotoController::class, 'store'])->name('dashboard-photos.store');
    Route::delete('/dashboard-photos/{dashboardPhoto}', [AdminDashboardPhotoController::class, 'destroy'])
        ->whereNumber('dashboardPhoto')
        ->name('dashboard-photos.destroy');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'client'])->prefix('client')->name('client.')->group(function (): void {
    Route::get('/', [ClientDashboardController::class, 'index'])->name('dashboard');
    Route::get('/pc-timer', [ClientPcTimerController::class, 'index'])->name('pc-timer');
    Route::get('/dtimer-wifi', [ClientDtimerWifiController::class, 'index'])->name('dtimer-wifi');
    Route::get('/licensing', [ClientLicensingController::class, 'index'])->name('licensing');
    Route::post('/licenses/claim', [ClientLicensingController::class, 'claim'])->name('licenses.claim');
    Route::post('/licenses/{license}/revocations', [ClientLicensingController::class, 'requestRevocation'])
        ->whereNumber('license')
        ->name('licenses.revocations.store');
});
