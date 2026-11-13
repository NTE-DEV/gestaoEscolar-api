<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('metrics:collect-system')->everyFiveMinutes();
