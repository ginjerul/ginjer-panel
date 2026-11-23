<?php

namespace Ginjer\Network;

use Filament\Contracts\Plugin;
use Filament\Panel;

class GinjerNetworkPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ginjer-network';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverResources(plugin_path($this->getId(), "src/Filament/$id/Resources"), "Ginjer\\Network\\Filament\\$id\\Resources");
        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Ginjer\\Network\\Filament\\$id\\Pages");
        if ($panel->getId() === 'admin') {
            // the History page's charts (registered so they render; kept off the dashboard)
            $panel->widgets([
                Filament\Admin\Widgets\TpsChart::class,
                Filament\Admin\Widgets\TickChart::class,
                Filament\Admin\Widgets\PlayersChart::class,
                Filament\Admin\Widgets\MemoryChart::class,
                Filament\Admin\Widgets\CpuChart::class,
            ]);
        }
    }

    public function boot(Panel $panel): void {}
}
