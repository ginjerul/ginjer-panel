<?php

namespace Ginjer\Network\Filament\Admin\Widgets;

use Filament\Widgets\ChartWidget;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\ShardMetric;

/** One line per shard over the last day or week, for one measure (TPS, tick time, players, memory, CPU). */
abstract class ShardChart extends ChartWidget
{
    /** Only on the History page, not the dashboard. */
    protected static bool $isDiscovered = false;

    public ?Network $record = null;

    public ?string $filter = '24h';

    protected ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 1;

    /** Column in ginjer_shard_metrics. */
    abstract protected function field(): string;

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return ['6h' => 'Last 6 hours', '24h' => 'Last 24 hours', '7d' => 'Last 7 days'];
    }

    protected function getData(): array
    {
        if ($this->record === null) {
            return ['datasets' => [], 'labels' => []];
        }
        [$hours, $bucket] = match ($this->filter) {
            '6h' => [6, 5],
            '7d' => [168, 60],
            default => [24, 15],
        };
        $since = now()->subHours($hours)->startOfMinute();
        $rows = ShardMetric::query()
            ->where('network_id', $this->record->id)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get(['shard', 'recorded_at', $this->field()]);

        // average into buckets of $bucket minutes so a week stays readable
        $labels = [];
        for ($t = $since->copy(); $t->lte(now()); $t->addMinutes($bucket)) {
            $labels[] = $t->format($hours > 24 ? 'D H:i' : 'H:i');
        }
        $datasets = [];
        $colors = ['#3b82f6', '#22c55e', '#f59e0b', '#ef4444', '#a855f7', '#06b6d4', '#ec4899', '#84cc16'];
        foreach ($rows->groupBy('shard') as $i => $shardRows) {
            $sums = [];
            foreach ($shardRows as $row) {
                $value = $row->{$this->field()};
                if ($value === null) {
                    continue;
                }
                $b = intdiv((int) $since->diffInMinutes($row->recorded_at), $bucket);
                $sums[$b][] = (float) $value;
            }
            $data = [];
            foreach (array_keys($labels) as $b) {
                $data[] = isset($sums[$b]) ? round(array_sum($sums[$b]) / count($sums[$b]), 2) : null;
            }
            $color = $colors[count($datasets) % count($colors)];
            $datasets[] = ['label' => $i, 'data' => $data, 'borderColor' => $color, 'backgroundColor' => $color, 'pointRadius' => 0, 'spanGaps' => true, 'tension' => 0.3];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }
}
