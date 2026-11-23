<?php

namespace Ginjer\Network\Filament\Admin\Widgets;

class PlayersChart extends ShardChart
{
    protected ?string $heading = 'Players';

    protected function field(): string
    {
        return 'players';
    }
}
