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
use Ginjer\Network\Services\Activity\NetworkLog;
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

    /** Staff with only "view network" see the page read-only. */
    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->can('view', $this->getRecord()), 403);
    }

    private function userCan(string $ability): bool
    {
        return (bool) auth()->user()?->can($ability, $this->getRecord());
    }

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return parent::form($schema)->disabled(fn () => !$this->userCan('update'));
    }

    protected function getFormActions(): array
    {
        return $this->userCan('update') ? parent::getFormActions() : [];
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        abort_unless($this->userCan('update'), 403);
        parent::save($shouldRedirect, $shouldSendSavedNotification);
        NetworkLog::record($this->getRecord(), 'settings', 'Network settings changed', implode(', ', array_diff(array_keys($this->getRecord()->getChanges()), ['updated_at'])) ?: null);
    }

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
                    ->visible(fn () => $this->userCan('control'))
                    ->label('Clear ground items on every shard')
                    ->icon('tabler-trash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Players get a 30-second warning, then Reef removes ground items, arrows and experience orbs on every running shard. Named items are kept. Handy after a war.')
                    ->action(function () {
                        $sent = 0;
                        foreach ($this->getRecord()->load('members.server')->membersWithRole(MemberRole::Shard) as $shard) {
                            try {
                                \Ginjer\Network\Services\Ops\Console::run($shard->server, 'reef clear all 30');
                                $sent++;
                            } catch (\Throwable) {
                                // offline: nothing on the ground is ticking there anyway
                            }
                        }
                        NetworkLog::record($this->getRecord(), 'clear', "Cleared ground items on $sent shard(s)");
                        Notification::make()->title("Clearing on $sent shard(s) in 30 seconds")->body('Shards that are offline were skipped.')->success()->send();
                    }),
                Action::make('exclude_maintenance')
                    ->label(fn () => $this->getRecord()->maintenance ? 'End maintenance' : 'Maintenance mode')
                    ->icon(fn () => $this->getRecord()->maintenance ? 'tabler-door-enter' : 'tabler-barrier-block')
                    ->color(fn () => $this->getRecord()->maintenance ? 'success' : 'warning')
                    ->visible(fn () => $this->userCan('control'))
                    ->modalHeading(fn () => $this->getRecord()->maintenance ? 'End maintenance?' : 'Maintenance mode')
                    ->modalDescription(fn () => $this->getRecord()->maintenance
                        ? 'Everyone can join again: the shards\' whitelists go off (unless a shard had its own before) and the proxy\'s MOTD comes back.'
                        : 'Only the players below can join. Every shard turns its whitelist on, everyone else online is kicked with a message, and the proxy shows the maintenance MOTD in the server list.')
                    ->fillForm(fn () => ['maintenance_allow' => $this->getRecord()->maintenance_allow, 'maintenance_motd' => $this->getRecord()->maintenance_motd ?: \Ginjer\Network\Services\Ops\Maintenance::DEFAULT_MOTD])
                    ->schema(fn () => $this->getRecord()->maintenance ? [] : [
                        \Filament\Forms\Components\Textarea::make('maintenance_allow')
                            ->label('Players who can still join')
                            ->rows(3)
                            ->placeholder("Steve\nAlex")
                            ->helperText('Minecraft names, one per line. Staff, usually.'),
                        \Filament\Forms\Components\TextInput::make('maintenance_motd')
                            ->label('Server list message')
                            ->helperText('MiniMessage, like <red>Back soon!</red>'),
                    ])
                    ->modalSubmitActionLabel(fn () => $this->getRecord()->maintenance ? 'End maintenance' : 'Start maintenance')
                    ->action(function (array $data, \Ginjer\Network\Services\Ops\Maintenance $maintenance) {
                        $network = $this->getRecord();
                        if ($network->maintenance) {
                            $report = $maintenance->disable($network);
                            NetworkLog::record($network, 'maintenance', 'Maintenance ended', implode("\n", $report), 'success');
                        } else {
                            $network->update($data);
                            $report = $maintenance->enable($network);
                            NetworkLog::record($network, 'maintenance', 'Maintenance started', implode("\n", $report), 'warning');
                        }
                        Notification::make()
                            ->title($network->maintenance ? 'Maintenance mode is on' : 'Maintenance is over')
                            ->body(new HtmlString('<ul>' . implode('', array_map(fn ($l) => '<li>' . e($l) . '</li>', $report)) . '</ul>'))
                            ->color($network->maintenance ? 'warning' : 'success')
                            ->persistent()
                            ->send();
                    }),
                Action::make('exclude_rolling_restart')
                    ->visible(fn () => $this->userCan('control'))
                    ->label('Rolling restart')
                    ->icon('tabler-refresh-dot')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Restarts the shards as set under Rolling restarts on this page (by default one at a time, followers first, leader last), waiting for each to come back. The network never goes down as a whole.')
                    ->action(fn () => $this->startRolling([])),
            ])
                ->label(fn () => $this->getRecord()->maintenance ? 'Power (maintenance on)' : 'Power')
                ->icon('tabler-power')
                ->button(),

            Action::make('exclude_update')
                ->visible(fn () => $this->userCan('control'))
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
                ->visible(fn () => $this->userCan('update'))
                ->label('Push config to shards')
                ->tooltip('Push config to shards')
                ->icon('tabler-upload')
                ->requiresConfirmation()
                ->modalDescription('Writes the regions, shard names and Redis settings into reef.yml and plugins/nodes/config.yml on every shard. Shards use them after their next restart.')
                ->action(function (ShardConfigService $service) {
                    $network = $this->getRecord()->load('members.server');

                    $problems = $service->problems($network);
                    if ($problems !== []) {
                        NetworkLog::record($network, 'push', 'Config push refused', implode("\n", $problems), 'warning');
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
                    NetworkLog::record($network, 'push', "Config pushed to $done of $total shard(s)", $results->map(fn ($r) => "{$r['server']}: {$r['message']}")->implode("\n"), $failed > 0 ? 'warning' : 'info');
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

            ActionGroup::make([
                Action::make('exclude_page_console')
                    ->label('Console')
                    ->tooltip('Every shard\'s console, and commands on all of them')
                    ->icon('tabler-terminal-2')
                    ->visible(fn () => $this->userCan('console'))
                    ->url(fn () => NetworkResource::getUrl('console', ['record' => $this->getRecord()])),
                Action::make('exclude_page_players')
                    ->label('Players')
                    ->tooltip('Who is online where; kick and ban')
                    ->icon('tabler-users')
                    ->visible(fn () => $this->userCan('players'))
                    ->url(fn () => NetworkResource::getUrl('players', ['record' => $this->getRecord()])),
                Action::make('exclude_page_activity')
                    ->label('Activity')
                    ->tooltip('Who did what, and alerts')
                    ->icon('tabler-list-details')
                    ->visible(fn () => $this->userCan('view'))
                    ->url(fn () => NetworkResource::getUrl('activity', ['record' => $this->getRecord()])),
                Action::make('exclude_page_schedules')
                    ->label('Schedules')
                    ->tooltip('Announcements and commands at set times')
                    ->icon('tabler-calendar-time')
                    ->visible(fn () => $this->userCan('control'))
                    ->url(fn () => NetworkResource::getUrl('schedules', ['record' => $this->getRecord()])),
                Action::make('exclude_page_history')
                    ->label('History')
                    ->tooltip('A week of TPS, players and memory')
                    ->icon('tabler-chart-line')
                    ->visible(fn () => $this->userCan('view'))
                    ->url(fn () => NetworkResource::getUrl('history', ['record' => $this->getRecord()])),
                Action::make('exclude_page_backups')
                    ->label('Backups')
                    ->tooltip('Back up and restore the whole network')
                    ->icon('tabler-database')
                    ->visible(fn () => $this->userCan('control'))
                    ->url(fn () => NetworkResource::getUrl('backups', ['record' => $this->getRecord()])),
                Action::make('exclude_page_plugins')
                    ->label('Plugins')
                    ->tooltip('Plugins on all shards')
                    ->icon('tabler-plug')
                    ->visible(fn () => $this->userCan('update'))
                    ->url(fn () => NetworkResource::getUrl('plugins', ['record' => $this->getRecord()])),
                Action::make('exclude_page_plugin_updates')
                    ->label('Plugin updates')
                    ->tooltip('Newer versions, and copies that differ')
                    ->icon('tabler-package')
                    ->visible(fn () => $this->userCan('update'))
                    ->url(fn () => NetworkResource::getUrl('plugin-updates', ['record' => $this->getRecord()])),
                Action::make('exclude_page_config_sync')
                    ->label('Config sync')
                    ->tooltip('The same plugin configs on every shard')
                    ->icon('tabler-file-settings')
                    ->visible(fn () => $this->userCan('update'))
                    ->url(fn () => NetworkResource::getUrl('config-sync', ['record' => $this->getRecord()])),
                Action::make('exclude_page_database')
                    ->label('Database')
                    ->tooltip('One MySQL database for the network\'s plugins')
                    ->icon('tabler-database-cog')
                    ->visible(fn () => $this->userCan('update'))
                    ->url(fn () => NetworkResource::getUrl('database', ['record' => $this->getRecord()])),
                Action::make('exclude_page_rebalance')
                    ->label('Rebalance shards')
                    ->tooltip('Borders that share players evenly')
                    ->icon('tabler-arrows-split')
                    ->visible(fn () => $this->userCan('update'))
                    ->url(fn () => NetworkResource::getUrl('rebalance', ['record' => $this->getRecord()])),
            ])
                ->label('Tools')
                ->icon('tabler-layout-grid')
                ->color('gray')
                ->button(),

            ActionGroup::make([
                Action::make('exclude_velocity')
                    ->label('Velocity servers')
                    ->icon('tabler-route')
                    ->modalHeading('Shards for velocity.toml')
                    ->modalDescription('Push config writes this into the proxy\'s velocity.toml for you when the proxy is in the network. Without one, paste it into the [servers] section yourself.')
                    ->modalContent(fn () => $this->velocityHtml())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('exclude_test_copy')
                    ->label('Make a test copy')
                    ->icon('tabler-copy')
                    ->visible(fn () => $this->userCan('update') && auth()->user()?->can('create', Network::class))
                    ->modalHeading('Make a test copy of this network')
                    ->modalDescription('Creates a new server for each server in this network on the same nodes, copies their files as they are now, and joins them in a new network with its own Redis and database. Try updates and plugins there first. The real network keeps running and isn\'t changed. The copy is left stopped.')
                    ->fillForm(fn () => ['name' => $this->getRecord()->name . ' test', 'memory_percent' => 50])
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('name')->required()->maxLength(100),
                        \Filament\Forms\Components\TextInput::make('memory_percent')
                            ->label('Memory')
                            ->numeric()->integer()->minValue(10)->maxValue(100)->suffix('% of each original')
                            ->helperText('A test copy rarely needs as much. The nodes need this much free, plus the disk space of the copies.'),
                    ])
                    ->modalSubmitActionLabel('Make the copy')
                    ->action(function (array $data) {
                        \Ginjer\Network\Jobs\CopyNetwork::dispatch($this->getRecord()->id, ['name' => $data['name'], 'memory_percent' => (int) $data['memory_percent']], (int) auth()->id());
                        NetworkLog::record($this->getRecord(), 'copy', "Test copy \"{$data['name']}\" started");
                        Notification::make()->title('Making the test copy')->body('It takes a while for a big world. You\'ll get a notification when it\'s ready.')->success()->send();
                    }),
                Action::make('exclude_open_original')
                    ->label('Open the real network')
                    ->icon('tabler-external-link')
                    ->visible(fn () => $this->getRecord()->test_of_network_id !== null && Network::find($this->getRecord()->test_of_network_id) !== null)
                    ->url(fn () => NetworkResource::getUrl('edit', ['record' => $this->getRecord()->test_of_network_id])),
                DeleteAction::make()->visible(fn () => $this->userCan('delete')),
            ])
                ->label('More')
                ->icon('tabler-dots')
                ->color('gray')
                ->button(),
        ];
    }

    /** @param  array{reef?: bool, nodes?: bool, ginjermap?: bool}  $what */
    private function startRolling(array $what): void
    {
        \Ginjer\Network\Jobs\RollingUpdate::dispatch($this->getRecord()->id, $what, (int) auth()->id());
        $update = in_array(true, $what, true);
        NetworkLog::record($this->getRecord(), $update ? 'update' : 'restart', $update ? 'Update started: ' . implode(', ', array_keys(array_filter($what))) : 'Rolling restart started');
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
            ->visible(fn () => $this->userCan('control'))
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
                NetworkLog::record($this->getRecord(), 'power', ucfirst($action) . " network ($count servers)", null, $action === 'kill' ? 'warning' : 'info');

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
