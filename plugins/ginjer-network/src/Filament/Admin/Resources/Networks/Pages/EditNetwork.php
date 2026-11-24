<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\NetworkPowerService;
use Ginjer\Network\Services\NodesStatusService;
use Ginjer\Network\Services\ShardConfigService;
use Illuminate\Support\HtmlString;

/**
 * @method Network getRecord()
 */
class EditNetwork extends EditRecord
{
    protected static string $resource = NetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('status')
                ->label('Status')
                ->tooltip('Status')
                ->icon('tabler-heartbeat')
                ->color('gray')
                ->modalHeading(fn () => $this->getRecord()->name . ' status')
                ->modalWidth(Width::FiveExtraLarge)
                ->modalContent(fn (NodesStatusService $service) => $this->statusHtml($service))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            ActionGroup::make([
                $this->powerAction('start', 'Start network', 'tabler-player-play', 'success'),
                $this->powerAction('restart', 'Restart network', 'tabler-reload', 'warning'),
                $this->powerAction('stop', 'Stop network', 'tabler-player-stop', 'danger'),
                $this->powerAction('kill', 'Kill network', 'tabler-alert-triangle', 'danger'),
                Action::make('exclude_clear_items')
                    ->label('Clear ground items on every shard')
                    ->icon('tabler-trash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Players get a 30-second warning, then Reef removes ground items, arrows and experience orbs on every running shard. Named items are kept. Handy after a war.')
                    ->action(function () {
                        $sent = 0;
                        foreach ($this->getRecord()->load('members.server')->membersWithRole(MemberRole::Shard) as $shard) {
                            try {
                                $shard->server->send('reef clear all 30');
                                $sent++;
                            } catch (\Throwable) {
                                // offline: nothing on the ground is ticking there anyway
                            }
                        }
                        Notification::make()->title("Clearing on $sent shard(s) in 30 seconds")->body('Shards that are offline were skipped.')->success()->send();
                    }),
                Action::make('exclude_rolling_restart')
                    ->label('Rolling restart')
                    ->icon('tabler-refresh-dot')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Restarts the shards as set under Rolling restarts on this page (by default one at a time, followers first, leader last), waiting for each to come back. The network never goes down as a whole.')
                    ->action(fn () => $this->startRolling([])),
            ])
                ->label('Power')
                ->icon('tabler-power')
                ->button(),

            Action::make('exclude_update')
                ->label('Update shards')
                ->icon('tabler-download')
                ->color('primary')
                ->modalHeading('Update the shards')
                ->modalDescription('Downloads the newest files once, uploads them to every shard, then restarts the shards as set under Rolling restarts on this page (by default one at a time, followers first, leader last), waiting for each to come back.')
                ->fillForm(fn () => [
                    'reef' => true,
                    'nodes' => filled($this->getRecord()->nodes_repo),
                    'ginjermap' => filled($this->getRecord()->nodes_repo),
                    'reef_jar_url' => $this->getRecord()->reef_jar_url ?: 'https://github.com/ginjerul/reef/releases/download/dev-main/reef.jar',
                    'nodes_repo' => $this->getRecord()->nodes_repo ?: 'ginjerul/ginjer-nodes',
                    'nodes_release' => $this->getRecord()->nodes_release ?: 'dev-master',
                ])
                ->schema([
                    \Filament\Schemas\Components\Grid::make(3)->schema([
                        \Filament\Forms\Components\Toggle::make('reef')->label('Reef'),
                        \Filament\Forms\Components\Toggle::make('nodes')->label('Nodes'),
                        \Filament\Forms\Components\Toggle::make('ginjermap')->label('GinjerMap'),
                    ]),
                    \Filament\Forms\Components\TextInput::make('reef_jar_url')->label('Reef download')->url()->helperText('Default: the newest main build from Reef\'s dev-main release.'),
                    \Filament\Schemas\Components\Grid::make(2)->schema([
                        \Filament\Forms\Components\TextInput::make('nodes_repo')->label('Nodes repository')->helperText('Its releases need nodes.jar and ginjermap.jar.'),
                        \Filament\Forms\Components\TextInput::make('nodes_release')->label('Release')->helperText('A tag, dev-master, or latest.'),
                    ]),
                    \Filament\Forms\Components\TextInput::make('github_token')
                        ->label('GitHub token')
                        ->password()->revealable()
                        ->dehydrated(fn ($state) => filled($state))
                        ->helperText(fn () => filled($this->getRecord()->github_token) ? 'A token is saved. Leave empty to keep it.' : 'Only for a private repository: a fine-grained token that can read its contents. Stored encrypted.'),
                ])
                ->modalSubmitActionLabel('Update')
                ->action(function (array $data) {
                    $this->getRecord()->update(\Illuminate\Support\Arr::only($data, ['reef_jar_url', 'nodes_repo', 'nodes_release', 'github_token']));
                    $this->startRolling(['reef' => (bool) $data['reef'], 'nodes' => (bool) $data['nodes'], 'ginjermap' => (bool) $data['ginjermap']]);
                }),

            Action::make('push_config')
                ->label('Push config to shards')
                ->tooltip('Push config to shards')
                ->icon('tabler-upload')
                ->requiresConfirmation()
                ->modalDescription('Writes the regions, shard names and Redis settings into reef.yml and plugins/nodes/config.yml on every shard. Shards use them after their next restart.')
                ->action(function (ShardConfigService $service) {
                    $network = $this->getRecord()->load('members.server');

                    $problems = $service->problems($network);
                    if ($problems !== []) {
                        Notification::make()
                            ->title('Nothing was pushed')
                            ->body(new HtmlString('<ul><li>' . implode('</li><li>', array_map('e', $problems)) . '</li></ul>'))
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    $results = collect($service->push($network));
                    $total = $results->count();
                    $failed = $results->where('ok', false)->count();
                    $done = $results->where('complete', true)->count();
                    Notification::make()
                        ->title(match (true) {
                            $failed > 0 => "$failed of $total shard(s) failed",
                            $done < $total => "Config pushed to $done of $total shard(s)",
                            default => "Config pushed to all $total shard(s)",
                        })
                        ->body(new HtmlString('<ul>' . $results->map(fn ($r) => '<li><b>' . e($r['server']) . ':</b> ' . e($r['message']) . '</li>')->implode('') . '</ul>'))
                        ->color($failed > 0 ? 'danger' : ($done < $total ? 'warning' : 'success'))
                        ->persistent()
                        ->send();
                }),

            Action::make('plugins')
                ->label('Plugins')
                ->tooltip('Plugins on all shards')
                ->icon('tabler-plug')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('plugins', ['record' => $this->getRecord()])),

            Action::make('history')
                ->label('History')
                ->tooltip('A week of TPS, players and memory')
                ->icon('tabler-chart-line')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('history', ['record' => $this->getRecord()])),

            Action::make('backups')
                ->label('Backups')
                ->tooltip('Back up and restore the whole network')
                ->icon('tabler-database')
                ->color('gray')
                ->url(fn () => NetworkResource::getUrl('backups', ['record' => $this->getRecord()])),

            Action::make('velocity')
                ->label('Velocity servers')
                ->tooltip('Velocity servers')
                ->icon('tabler-route')
                ->color('gray')
                ->modalHeading('Shards for velocity.toml')
                ->modalDescription('Push config writes this into the proxy\'s velocity.toml for you when the proxy is in the network. Without one, paste it into the [servers] section yourself.')
                ->modalContent(fn () => $this->velocityHtml())
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            DeleteAction::make(),
        ];
    }

    /** @param  array{reef?: bool, nodes?: bool, ginjermap?: bool}  $what */
    private function startRolling(array $what): void
    {
        \Ginjer\Network\Jobs\RollingUpdate::dispatch($this->getRecord()->id, $what, (int) auth()->id());
        $update = in_array(true, $what, true);
        Notification::make()
            ->title($update ? 'Update started' : 'Rolling restart started')
            ->body('The shards restart one at a time. You\'ll get a notification when it\'s done. This needs the panel\'s queue worker.')
            ->success()
            ->send();
    }

    private function powerAction(string $action, string $label, string $icon, string $color): Action
    {
        // "exclude_" keeps the label: Pelican otherwise shows actions as bare icons, which is too
        // easy to misread in a menu that can kill every server
        return Action::make("exclude_power_$action")
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->modalDescription(match ($action) {
                'start' => 'Starts Redis, then the Nodes leader, then the other shards, then the proxy, a few seconds apart.',
                'stop' => 'Stops the proxy first so players leave cleanly, then the followers, the leader and Redis.',
                'restart' => 'Stops everything in order, then starts everything in order.',
                'kill' => 'Kills every server at once. Unsaved world and Nodes data is lost.',
            })
            ->action(function (NetworkPowerService $service) use ($action) {
                $count = $service->run($this->getRecord(), $action);

                Notification::make()
                    ->title($count === 0 ? 'This network has no servers yet' : ucfirst($action) . " sent to $count server(s)")
                    ->body($count > 0 && $action !== 'kill' ? 'Servers get it in order, a few seconds apart. This needs the panel\'s queue worker to be running.' : null)
                    ->color($count === 0 ? 'warning' : 'success')
                    ->send();
            });
    }

    private function statusHtml(NodesStatusService $service): HtmlString
    {
        $network = $this->getRecord()->load('members.server');
        $nodes = $service->status($network);
        $shards = app(\Ginjer\Network\Services\Health\NetworkHealth::class)->shards($network);
        $cell = fn ($v) => '<td style="padding:4px 8px;white-space:nowrap">' . $v . '</td>';
        $tpsColor = fn (?float $tps) => $tps === null ? '' : ($tps >= 19 ? 'color:rgb(34 197 94)' : ($tps >= $network->lag_tps ? 'color:rgb(234 179 8)' : 'color:rgb(239 68 68)'));

        $rows = '';
        foreach ($shards as $name => $s) {
            $memory = $s['memory_mb'] !== null ? number_format($s['memory_mb'] / 1024, 1) . ($s['memory_limit_mb'] ? ' / ' . number_format($s['memory_limit_mb'] / 1024, 1) : '') . ' GB' : '-';
            $rows .= '<tr>' . $cell('<b>' . e($name) . '</b><br><span style="opacity:.7">' . e($s['server']) . '</span>')
                . $cell(e($s['state'] ?? 'not answering'))
                . $cell($s['reporting'] ? e($s['role']) : '<span style="color:rgb(234 179 8)">not reporting</span>')
                . $cell($s['tps'] !== null ? '<span style="' . $tpsColor($s['tps']) . '">' . number_format($s['tps'], 1) . '</span>' : '-')
                . $cell($s['mspt'] !== null ? number_format($s['mspt'], 1) . ' ms' : '-')
                . $cell($s['players'] ?? '-')
                . $cell($memory)
                . $cell($s['cpu'] !== null ? $s['cpu'] . '%' : '-')
                . '</tr>';
        }
        foreach ($network->members->filter(fn (NetworkMember $m) => $m->role !== MemberRole::Shard) as $member) {
            $state = rescue(fn () => $member->server->retrieveStatus()->value, 'unknown', false);
            $rows .= '<tr>' . $cell('<b>' . e($member->role->getLabel()) . '</b><br><span style="opacity:.7">' . e($member->server->name) . '</span>') . $cell(e($state)) . str_repeat($cell(''), 6) . '</tr>';
        }

        if (!$nodes['reachable']) {
            $summary = '<p><b>Redis:</b> can\'t connect to ' . e($network->redis_host . ':' . $network->redis_port) . ' (' . e($nodes['error'] ?? '') . ')</p>';
        } else {
            $summary = '<p><b>Nodes leader:</b> ' . e($nodes['leader'] ?? 'none (no shard holds the lock)') . '</p>';
            $configured = $network->leader()?->shard_name;
            if ($configured && $nodes['leader'] && $configured !== $nodes['leader']) {
                $summary .= '<p style="color:#d97706">The leader set here is ' . e($configured) . ', but ' . e($nodes['leader']) . ' holds the lock (after a failover?). Mark ' . e($nodes['leader']) . ' as the leader below and push the config, so a restart keeps it.</p>';
            }
        }
        if ($rows === '') {
            return new HtmlString($summary . '<p>No servers in this network yet.</p>');
        }
        $head = fn ($t) => '<th style="text-align:left;padding:4px 8px">' . $t . '</th>';

        return new HtmlString($summary . '<table style="width:100%;margin-top:8px"><thead><tr>'
            . $head('Shard') . $head('State') . $head('Nodes') . $head('TPS') . $head('Tick') . $head('Players') . $head('Memory') . $head('CPU')
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p style="margin-top:8px;opacity:.7">TPS and tick time come from Nodes (it needs a version that reports them). 20 TPS is full speed; a tick over 50 ms means the shard can\'t keep up.</p>');
    }

    private function velocityHtml(): HtmlString
    {
        $shards = $this->getRecord()->load('members.server.allocation')->membersWithRole(MemberRole::Shard);
        if ($shards->isEmpty()) {
            return new HtmlString('<p>No shards yet.</p>');
        }

        $proxy = $this->getRecord()->membersWithRole(MemberRole::Proxy)->first()?->server ?? $shards->first()->server;
        $lines = $shards->map(fn (NetworkMember $m) => ($m->shard_name ?: $m->server->name) . ' = "' . (ShardConfigService::addressFor($m, $proxy) ?: 'no-allocation') . '"');
        $try = 'try = [' . $shards->map(fn (NetworkMember $m) => '"' . ($m->shard_name ?: $m->server->name) . '"')->implode(', ') . ']';

        return new HtmlString('<pre style="font-family:monospace;white-space:pre-wrap;padding:8px;border-radius:6px;background:rgba(127,127,127,.12)">[servers]' . "\n" . e($lines->implode("\n")) . "\n" . e($try) . '</pre>'
            . '<p style="margin-top:8px">Players can join through any shard: Reef moves them to the shard that owns their position.</p>');
    }
}
