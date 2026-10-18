<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the review schedule flat: any overdue cards beyond the daily capacity
// are pushed onto the next days that still have room.
//
// Nothing runs the Laravel scheduler in the Docker stack (no cron, no
// schedule:work), so this line only fires where one is wired up. The live path
// is on demand: QuizController@store and QuizBuilderService::smartMix rebalance
// on every quiz build, and ReviewController@session does it via dueWords().
Schedule::command('srs:rebalance')->dailyAt('03:00');
