<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('lab:retry-failed-cases')->hourly()->withoutOverlapping();
Schedule::command('supervisor:check-missing-checklists')->hourly();
Schedule::command('nurse-questionnaires:check-missing')->hourly();
Schedule::command('procedures:check-missing-vitals')->hourly();
Schedule::command('employee-todos:notify')->dailyAt('08:00');
Schedule::command('attendance:sync')->everyTwoMinutes();
Schedule::command('attendance:process')->everyFiveMinutes();
Schedule::command('attendance:close-day')->dailyAt('02:00');
Schedule::command('attendance:notify-missing')->everyFifteenMinutes();
Schedule::command('attendance:daily-summary')->dailyAt('08:00');
