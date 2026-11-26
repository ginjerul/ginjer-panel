<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Jobs\RebalanceNetwork;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Rebalance\Rebalancer;
use Ginjer\Network\Services\Rebalance\ShardMap;
use Ginjer\Network\Services\RegionList;
use Illuminate\Support\HtmlString;

/**
 * Shows how evenly players are spread over the shards and proposes new borders that even it out.
 *
 * @property Network $record
 */
class NetworkRebalancePage extends Page
{
    use Concerns\ChecksNetworkAccess;
    use InteractsWithRecord;

    protected static string $resource = NetworkResource::class;

    public int $days = 7;

    /** @var array<string, mixed>|null */
    private ?array $plan = null;

    protected function networkAbility(): string
    {
        return 'update';
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->checkNetworkAccess();
    }

    public function getTitle(): string
    {
        return $this->record->name . ': rebalance shards';
    }

    public function getSubheading(): ?string
    {
        return "Where players spent their time over the last {$this->days} days (recorded every minute; time on a lagging shard counts more), and borders that would share it evenly.";
    }

    private function plan(): array
    {
        return $this->plan ??= app(Rebalancer::class)->plan($this->record, $this->days);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exclude_back')
                ->label('Back to the network')
                ->icon('tabler-arrow-left')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->record])),
            Action::make('exclude_days')
                ->label(fn () => "Last {$this->days} days")
                ->icon('tabler-calendar')
                ->color('gray')
                ->action(fn () => $this->days = $this->days === 7 ? 14 : ($this->days === 14 ? 3 : 7)),
            Action::make('exclude_rehearse')
                ->label('Rehearse on a test copy')
                ->icon('tabler-flask')
                ->color('gray')
                ->visible(fn () => $this->plan()['enough'] && $this->plan()['regions'] !== [] && $this->record->test_of_network_id === null && auth()->user()?->can('create', Network::class))
                ->modalHeading('Rehearse on a test copy')
                ->modalDescription('Makes a test copy of the network (new servers with a copy of every server\'s files, its own Redis and database; the real network keeps running), then rebalances the copy to these borders. Start the copy and walk around the new borders before you apply them here.')
                ->fillForm(fn () => ['memory_percent' => 50])
                ->schema([
                    \Filament\Forms\Components\TextInput::make('memory_percent')->label('Memory')->numeric()->integer()->minValue(10)->maxValue(100)->suffix('% of each original'),
                ])
                ->modalSubmitActionLabel('Make the copy and rebalance it')
                ->action(function (array $data) {
                    $name = $this->record->name . ' rehearsal ' . now()->format('m-d H:i');
                    \Ginjer\Network\Jobs\CopyNetwork::dispatch($this->record->id, ['name' => $name, 'memory_percent' => (int) $data['memory_percent'], 'rehearse' => $this->plan()['yaml']], (int) auth()->id());
                    NetworkLog::record($this->record, 'rebalance', 'Rebalance rehearsal started', "On a test copy named \"$name\".");
                    Notification::make()->title('Making the rehearsal copy')->body('You\'ll get a notification when the copy is ready, and another when it\'s rebalanced.')->success()->send();
                }),
            Action::make('exclude_undo')
                ->label('Undo last rebalance')
                ->icon('tabler-arrow-back-up')
                ->color('danger')
                ->visible(fn () => !empty($this->record->refresh()->last_rebalance['before']))
                ->requiresConfirmation()
                ->modalHeading('Undo the last rebalance?')
                ->modalDescription(fn () => 'Puts back the borders and the world files from before the rebalance of '
                    . \Illuminate\Support\Carbon::parse($this->record->last_rebalance['at'] ?? now())->diffForHumans()
                    . '. Anything built since then on the land that changed shards is lost. The network goes down for a few minutes, after a backup.')
                ->modalSubmitActionLabel('Undo it')
                ->action(function () {
                    RebalanceNetwork::dispatch($this->record->id, '', (int) auth()->id(), true);
                    NetworkLog::record($this->record, 'rebalance', 'Undoing the last rebalance', null, 'warning');
                    Notification::make()->title('Undoing the rebalance')->body('The network goes down for a few minutes. The result goes to your notifications.')->warning()->send();
                }),
            Action::make('exclude_apply')
                ->label('Apply new borders')
                ->icon('tabler-arrows-split')
                ->color('warning')
                ->visible(fn () => $this->plan()['enough'] && $this->plan()['regions'] !== [])
                ->requiresConfirmation()
                ->modalHeading('Move the shard borders?')
                ->modalDescription(fn () => 'The whole network goes down for a few minutes: players get a warning, the proxy and the shards stop, the land that changes shard ('
                    . round($this->plan()['moved'] * 100) . '% of where players play) is copied from its old shard to its new one, the new borders are pushed, and everything starts again. '
                    . 'Changed world files are kept as .ginjer-old, so it can be undone. ' . ($this->record->backup_before_risky ? 'A network backup is taken first. ' : 'Backing up first is off for this network: take a network backup yourself. ') . 'The new borders are rectangles; drawn shapes are replaced.')
                ->modalSubmitActionLabel('Rebalance now')
                ->action(function () {
                    RebalanceNetwork::dispatch($this->record->id, $this->plan()['yaml'], (int) auth()->id());
                    NetworkLog::record($this->record, 'rebalance', 'Rebalancing started', $this->plan()['yaml']);
                    Notification::make()->title('Rebalancing started')->body('The network goes down for a few minutes. The result goes to your notifications.')->warning()->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $plan = $this->plan();
        if (!$plan['enough']) {
            return $schema->components([Section::make('Not enough data yet')->schema([Html::make(new HtmlString(e(
                'The rebalancer needs at least ' . Rebalancer::MIN_PLAYER_MINUTES . ' player-minutes and two shards; it has ' . round($plan['minutes']) . ' so far. '
                . 'Positions come from Nodes (a version that reports them) through Redis, once a minute while players are online.'
            )))])]);
        }

        $verdict = $plan['worth']
            ? sprintf('The busiest shard carries %.1f× its fair share. With the new borders the busiest would carry %.1f×.', $plan['now'], $plan['after'])
            : sprintf('The shards are fairly even (the busiest carries %.1f× its fair share), so there\'s little to gain. The proposal is shown anyway.', $plan['now']);

        $rows = '';
        foreach ($plan['shards'] as $name => $share) {
            $bar = fn (float $v, string $c) => '<div style="height:8px;border-radius:4px;background:' . $c . ';width:' . min(100, round($v * 100 * 2)) . '%"></div>';
            $rows .= '<tr><td style="padding:6px 8px;font-weight:600">' . e($name) . '</td>'
                . '<td style="padding:6px 8px;width:35%">' . round($share['now'] * 100) . '%' . $bar($share['now'], '#f59e0b') . '</td>'
                . '<td style="padding:6px 8px;width:35%">' . round($share['after'] * 100) . '%' . $bar($share['after'], '#22c55e') . '</td></tr>';
        }
        $table = '<p style="margin-bottom:8px">' . e($verdict) . '</p><table style="width:100%"><thead><tr><th style="text-align:left;padding:6px 8px">Shard</th><th style="text-align:left;padding:6px 8px">Load now</th><th style="text-align:left;padding:6px 8px">With new borders</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        return $schema->components([
            Section::make('Load per shard')->schema([Html::make(new HtmlString($table))]),
            Section::make('Map')
                ->description('Squares are where players spent time (brighter is busier). Dashed lines are today\'s borders, coloured areas the new ones.')
                ->schema([Html::make(new HtmlString($this->svg($plan)))]),
        ]);
    }

    private function svg(array $plan): string
    {
        $points = $plan['points'];
        $xs = array_column($points, 0);
        $zs = array_column($points, 1);
        $pad = 512;
        [$x0, $x1, $z0, $z1] = [min($xs) - $pad, max($xs) + $pad, min($zs) - $pad, max($zs) + $pad];
        $w = max(1, $x1 - $x0);
        $h = max(1, $z1 - $z0);
        $size = 760;
        $scale = $size / max($w, $h);
        $vw = round($w * $scale);
        $vh = round($h * $scale);
        $px = fn ($x) => round(($x - $x0) * $scale, 1);
        $pz = fn ($z) => round(($z - $z0) * $scale, 1);
        $palette = ['#60a5fa', '#f472b6', '#34d399', '#fbbf24', '#a78bfa', '#f87171', '#22d3ee', '#fb923c'];
        $names = array_keys($plan['shards']);
        $color = fn ($n) => $palette[max(0, (int) array_search($n, $names, true)) % count($palette)];

        $out = "<svg viewBox=\"0 0 $vw $vh\" style=\"width:100%;max-width:{$vw}px;background:#0b0d10;border-radius:10px\" xmlns=\"http://www.w3.org/2000/svg\">";
        foreach ($plan['regions'] as $r) {
            $rx = $px(max($x0, $r['min-x']));
            $rz = $pz(max($z0, $r['min-z']));
            $rw = $px(min($x1, $r['max-x'])) - $rx;
            $rh = $pz(min($z1, $r['max-z'])) - $rz;
            if ($rw > 0 && $rh > 0) {
                $out .= "<rect x=\"$rx\" y=\"$rz\" width=\"$rw\" height=\"$rh\" fill=\"{$color($r['server'])}\" fill-opacity=\"0.18\" stroke=\"{$color($r['server'])}\" stroke-width=\"2\"/>"
                    . '<text x="' . ($rx + 8) . '" y="' . ($rz + 20) . '" fill="' . $color($r['server']) . '" font-size="14" font-family="sans-serif" font-weight="600">' . e($r['server']) . '</text>';
            }
        }
        $max = max(array_column($points, 2)) ?: 1;
        $cell = max(1.5, 128 * $scale);
        foreach ($points as [$x, $z, $wt]) {
            $o = round(0.15 + 0.85 * sqrt($wt / $max), 2);
            $out .= '<rect x="' . ($px($x - 64)) . '" y="' . ($pz($z - 64)) . "\" width=\"$cell\" height=\"$cell\" fill=\"#fff\" fill-opacity=\"$o\"/>";
        }
        // today's borders: sample the owner on a grid and draw where it changes
        $now = new ShardMap(RegionList::parse((string) $this->record->regions));
        $step = max(16, (int) (max($w, $h) / 150));
        $lines = '';
        for ($x = $x0; $x < $x1; $x += $step) {
            for ($z = $z0; $z < $z1; $z += $step) {
                $here = $now->ownerAt($x, $z);
                if ($here !== $now->ownerAt($x + $step, $z)) {
                    $lines .= 'M' . $px($x + $step) . ' ' . $pz($z) . 'v' . round($step * $scale, 1);
                }
                if ($here !== $now->ownerAt($x, $z + $step)) {
                    $lines .= 'M' . $px($x) . ' ' . $pz($z + $step) . 'h' . round($step * $scale, 1);
                }
            }
        }
        $out .= "<path d=\"$lines\" stroke=\"#e5e7eb\" stroke-width=\"1.5\" stroke-dasharray=\"4 4\" fill=\"none\"/>";

        return $out . '</svg>';
    }
}
