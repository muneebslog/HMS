<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('lab:retry-failed-cases')->hourly()->withoutOverlapping();
Schedule::command('lab:sync-statuses')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('partner-lab:sync')->everyFifteenMinutes()->between('7:00', '23:59')->withoutOverlapping();
Schedule::command('supervisor:check-missing-checklists')->hourly();
Schedule::command('employee-todos:notify')->dailyAt('08:00');
