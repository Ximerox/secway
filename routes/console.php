<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('mail:purge')->hourly();
