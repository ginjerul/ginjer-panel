<?php

namespace Ginjer\Network\Filament\Admin\Widgets;

class TickChart extends ShardChart
{
    protected ?string $heading = 'Time per tick (ms; over 50 is lag)';

    protected function field(): string
    {
        return 'mspt';
    }
}
