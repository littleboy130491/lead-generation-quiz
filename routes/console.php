<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('analyses:dispatch-pending')->everyMinute()->withoutOverlapping();
Schedule::command('analyses:recover-stale')->everyMinute()->withoutOverlapping();
Schedule::command('reports:dispatch-unsent')->everyMinute()->withoutOverlapping();
Schedule::command('reports:recover-stale')->everyMinute()->withoutOverlapping();
Schedule::command('submissions:mark-abandoned')->hourly()->withoutOverlapping();
