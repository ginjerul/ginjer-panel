<?php

namespace Ginjer\Network\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class GinjerNetworkProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Pelican runs Laravel's scheduler every minute
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('p:ginjer:health')->everyMinute()->withoutOverlapping(5);
            $schedule->command('p:ginjer:restarts')->everyMinute()->withoutOverlapping(5);
            $schedule->command('p:ginjer:backups')->everyFifteenMinutes()->withoutOverlapping(60);
        });
    }
}
