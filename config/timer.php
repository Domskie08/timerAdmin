<?php

return [
    'active_window_minutes' => (int) env('TIMER_ACTIVE_WINDOW_MINUTES', 10),
    'update_upload_max_kb' => (int) env('TIMER_UPDATE_UPLOAD_MAX_KB', 204800),
    'philippine_timezone' => env('TIMER_PHILIPPINE_TIMEZONE', 'Asia/Manila'),
    'philippine_time_url' => env('TIMER_PHILIPPINE_TIME_URL', 'https://worldtimeapi.org/api/timezone/Asia/Manila'),
    'philippine_time_timeout_seconds' => (int) env('TIMER_PHILIPPINE_TIME_TIMEOUT_SECONDS', 3),
];
