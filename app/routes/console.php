<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('monitors:dispatch-due')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('monitors:dispatch-favicon-refresh')
    ->weekly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('monitors:prune-checks')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('model:prune')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping();
