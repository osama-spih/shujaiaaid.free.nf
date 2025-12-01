<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// تنظيف الملفات القديمة يومياً (أقدم من 7 أيام)
Schedule::command('cleanup:old-files --days=7')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->runInBackground();
