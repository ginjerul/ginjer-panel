<?php

namespace Ginjer\Network\Filament\Admin\Widgets;

class MemoryChart extends ShardChart
{
    protected ?string $heading = 'Memory used (MB)';

    protected function field(): string
    {
        return 'memory_mb';
    }
}
