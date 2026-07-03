<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mail:purge')->hourly();
Schedule::command('mail:send-passwords')->everyMinute()->withoutOverlapping();
Schedule::command('mail:send-reminders')->hourly()->withoutOverlapping();
