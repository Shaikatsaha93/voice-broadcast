<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('calls:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily();
