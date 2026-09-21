<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:clean')->dailyAt('02:30')
    ->timezone(config('app.timezone'))->environments(['production'])
    ->when(fn (): bool => (bool) config('backup.enabled'))->withoutOverlapping(360);

Schedule::command('backup:run --isolated=1')->dailyAt('03:00')
    ->timezone(config('app.timezone'))->environments(['production'])
    ->when(fn (): bool => (bool) config('backup.enabled'))->withoutOverlapping(360);

Schedule::command('backup:monitor')->dailyAt('04:00')
    ->timezone(config('app.timezone'))->environments(['production'])
    ->when(fn (): bool => (bool) config('backup.enabled'))->withoutOverlapping(360);
