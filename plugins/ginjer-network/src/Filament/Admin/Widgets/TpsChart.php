<?php

namespace Ginjer\Network\Filament\Admin\Widgets;

class TpsChart extends ShardChart
{
    protected ?string $heading = 'TPS (20 is full speed)';

    protected function field(): string
    {
        return 'tps';
    }
}
