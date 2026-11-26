<?php

namespace Ginjer\Network\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class GinjerNetworkProvider extends ServiceProvider
{
    public function register(): void
    {
        // shows up under Admin → Roles, so staff can get some network pages without root admin
        \App\Models\Role::registerCustomPermissions(['network' => \Ginjer\Network\Policies\NetworkPolicy::PERMISSIONS]);
        \App\Models\Role::registerCustomModelIcon('network', 'tabler-topology-star-3');
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::policy(\Ginjer\Network\Models\Network::class, \Ginjer\Network\Policies\NetworkPolicy::class);

        // Pelican runs Laravel's scheduler every minute
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('p:ginjer:health')->everyMinute()->withoutOverlapping(5);
            $schedule->command('p:ginjer:restarts')->everyMinute()->withoutOverlapping(5);
            $schedule->command('p:ginjer:schedules')->everyMinute()->withoutOverlapping(5);
            $schedule->command('p:ginjer:backups')->everyFifteenMinutes()->withoutOverlapping(60);
        });
    }
}
