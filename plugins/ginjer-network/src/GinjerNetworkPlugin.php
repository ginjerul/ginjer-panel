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
    }

    public function boot(Panel $panel): void {}
}
