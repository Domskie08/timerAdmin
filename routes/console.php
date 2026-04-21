<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('app:about', function (): void {
    $this->comment('TimerAdmin license console is ready.');
})->purpose('Display a short project summary.');

