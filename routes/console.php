<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mail:purge')->hourly();
Schedule::command('mail:send-passwords')->everyMinute()->withoutOverlapping();
Schedule::command('mail:send-reminders')->hourly()->withoutOverlapping();
Schedule::command('entra:sync')->hourly()->withoutOverlapping();
Schedule::command('mail:update-sent-items')->everyMinute()->withoutOverlapping();
Schedule::command('mail:process-held')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('smime:update-roots')->weeklyOn(1, '03:15')->withoutOverlapping();
Schedule::command('smime:recheck-harvested')->dailyAt('03:40')->withoutOverlapping();
