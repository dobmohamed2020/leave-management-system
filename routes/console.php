<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// تشغيل الترحيل السنوي للأرصدة تلقائياً بنهاية السنة المالية في 30 يونيو (أو بداية 1 يوليو)
Schedule::command('leave:year-end')
    ->yearlyOn(6, 30, '23:59')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/year-end-rollover.log'));
