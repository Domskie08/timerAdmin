<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

class PhilippineInternetClock
{
    public static function now(): CarbonImmutable
    {
        $timezone = config('timer.philippine_timezone', 'Asia/Manila');
        $timeApiUrl = config('timer.philippine_time_url');
        $timeoutSeconds = max(1, (int) config('timer.philippine_time_timeout_seconds', 3));

        if (is_string($timeApiUrl) && trim($timeApiUrl) !== '') {
            try {
                $response = Http::acceptJson()
                    ->timeout($timeoutSeconds)
                    ->get($timeApiUrl);

                if ($response->successful()) {
                    $payload = $response->json();
                    $dateTime = $payload['datetime'] ?? $payload['utc_datetime'] ?? null;

                    if (is_string($dateTime) && trim($dateTime) !== '') {
                        return CarbonImmutable::parse($dateTime)->setTimezone($timezone);
                    }

                    $unixTime = $payload['unixtime'] ?? null;

                    if (is_numeric($unixTime)) {
                        return CarbonImmutable::createFromTimestampUTC((int) $unixTime)->setTimezone($timezone);
                    }
                }
            } catch (\Throwable) {
                // Fall back to local Philippine time if the internet clock is unavailable.
            }
        }

        return CarbonImmutable::now($timezone);
    }
}
