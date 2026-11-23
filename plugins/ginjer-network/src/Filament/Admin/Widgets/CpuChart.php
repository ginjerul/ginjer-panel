<?php

namespace Ginjer\Network\Filament\Admin\Widgets;

class CpuChart extends ShardChart
{
    protected ?string $heading = 'CPU (%)';

    protected function field(): string
    {
        return 'cpu';
    }
}
