<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the review schedule flat: any overdue cards beyond the daily capacity
// are pushed onto the next days that still have room. Also runs on demand when
// a review session starts, so this is a safety net rather than the only path.
Schedule::command('srs:rebalance')->dailyAt('03:00');
