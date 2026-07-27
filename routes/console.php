<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('lab:retry-failed-cases')->everyThirtyMinutes();
Schedule::command('supervisor:check-missing-checklists')->everyTwoHours();
