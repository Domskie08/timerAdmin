<?php

use App\Services\LicenseRevocationProcessor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:about', function (): void {
    $this->comment('TimerAdmin license console is ready.');
})->purpose('Display a short project summary.');

Artisan::command('dtimer:process-revocations', function (): void {
    $processed = app(LicenseRevocationProcessor::class)->processDue();

    $this->info("Processed {$processed} DTimer license revocation(s).");
})->purpose('Complete DTimer WiFi license revocations after the 30-day waiting period.');

Schedule::command('dtimer:process-revocations')->hourly();
