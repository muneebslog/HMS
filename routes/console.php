<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('lab:retry-failed-cases')->everyThirtyMinutes();
Schedule::command('supervisor:check-missing-checklists')->hourly();
Schedule::command('employee-todos:notify')->dailyAt('08:00');
