<?php

use Illuminate\Support\Facades\Schedule;

// Driven by the cPanel cron job (every minute): see docs/SCHEDULER.md.
Schedule::command('invites:process')->everyMinute()->withoutOverlapping();
