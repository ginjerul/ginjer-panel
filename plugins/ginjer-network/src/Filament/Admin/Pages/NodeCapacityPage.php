<?php

namespace Ginjer\Network\Filament\Admin\Pages;

use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Ginjer\Network\Services\Ops\NodeCapacity;
use Illuminate\Support\HtmlString;

/** How full every node is, and where another shard would fit. */
class NodeCapacityPage extends Page
{
    protected static ?string $slug = 'node-capacity';

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-server-2';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Ginjer';
    }

    public static function getNavigationLabel(): string
    {
        return 'Node capacity';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('viewList node');
    }

    public function getTitle(): string
    {
        return 'Node capacity';
    }

    public function getSubheading(): ?string
    {
        return '"Given out" is what Pelican promised the servers on a node, against what the node allows (with overallocation). "In use" is what the machine really uses right now, from Wings.';
    }

    public function content(Schema $schema): Schema
    {
        $capacity = app(NodeCapacity::class);
        $nodes = $capacity->nodes();
        $networks = $capacity->networks($nodes);
        $gb = fn (?int $mb) => $mb === null ? '∞' : rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.') . ' GB';
        $bar = function (int $used, ?int $limit, string $label) use ($gb) {
            if ($limit === null || $limit <= 0) {
                return '<div>' . $gb($used) . ' given out, no limit</div>';
            }
            $pct = min(100, (int) round(100 * $used / $limit));
            $color = $pct >= 95 ? '#ef4444' : ($pct >= 80 ? '#f59e0b' : '#22c55e');

            return '<div style="display:flex;justify-content:space-between;font-size:13px"><span>' . e($label) . '</span><span>' . $gb($used) . ' of ' . $gb($limit) . " ($pct%)</span></div>"
                . '<div style="height:8px;border-radius:4px;background:rgba(127,127,127,.2);margin:4px 0 10px"><div style="height:8px;border-radius:4px;width:' . $pct . '%;background:' . $color . '"></div></div>';
        };

        $sections = [];
        foreach ($nodes as $node) {
            $live = $node['live'];
            $html = $bar($node['memory']['given'], $node['memory']['limit'], 'Memory given out')
                . $bar($node['disk']['given'], $node['disk']['limit'], 'Disk given out')
                . ($live ? $bar($live['memory_used'], $live['memory_total'], 'Memory in use') . $bar($live['disk_used'], $live['disk_total'], 'Disk in use')
                    . '<div style="font-size:13px;opacity:.8">CPU ' . e($live['cpu']) . '%, load ' . e($live['load']) . '</div>'
                    : '<div style="font-size:13px;opacity:.7">Wings isn\'t answering, so live use is unknown.</div>')
                . '<div style="font-size:13px;opacity:.8;margin-top:6px">' . e($node['servers']) . ' server(s), ' . e($node['ports']) . ' free port(s)</div>';
            $sections[] = Section::make($node['name'])->schema([Html::make(new HtmlString($html))])->columnSpan(1);
        }

        $rows = '';
        foreach ($networks as $n) {
            $rows .= '<tr><td style="padding:6px 8px;font-weight:600">' . e($n['network']) . '</td>'
                . '<td style="padding:6px 8px">' . e($n['shards']) . ' × ' . $gb($n['memory']) . '</td>'
                . '<td style="padding:6px 8px">' . ($n['fits'] === [] ? '<span style="color:#ef4444">Nowhere: free up memory, disk or ports, or add a node</span>' : e(implode(', ', $n['fits']))) . '</td></tr>';
        }
        $table = $rows === '' ? '<p>No networks with shards yet.</p>'
            : '<table style="width:100%"><thead><tr><th style="text-align:left;padding:6px 8px">Network</th><th style="text-align:left;padding:6px 8px">Shards now</th><th style="text-align:left;padding:6px 8px">Another shard of the same size fits on (how many)</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return $schema->components([
            Section::make('Room for more shards')->description('Counting memory, disk and free ports, with each node\'s limits.')->schema([Html::make(new HtmlString($table))]),
            \Filament\Schemas\Components\Grid::make(['default' => 1, 'lg' => 2])->schema($sections ?: [Html::make(new HtmlString('<p>No nodes yet.</p>'))]),
        ]);
    }
}
