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
                ->modalWidth(Width::TwoExtraLarge)
                ->modalContent(fn (NodesStatusService $service) => $this->statusHtml($service))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            ActionGroup::make([
                $this->powerAction('start', 'Start network', 'tabler-player-play', 'success'),
                $this->powerAction('restart', 'Restart network', 'tabler-reload', 'warning'),
                $this->powerAction('stop', 'Stop network', 'tabler-player-stop', 'danger'),
                $this->powerAction('kill', 'Kill network', 'tabler-alert-triangle', 'danger'),
            ])
                ->label('Power')
                ->icon('tabler-power')
                ->button(),

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

        $rows = '';
        foreach ($network->members->sortBy(fn (NetworkMember $m) => [$m->role->value, $m->shard_name]) as $member) {
            $state = rescue(fn () => $member->server->retrieveStatus()->value, 'unknown', false);
            $nodesInfo = '';
            if ($member->role === MemberRole::Shard && $member->shard_name) {
                $seen = $nodes['shards'][$member->shard_name] ?? null;
                $nodesInfo = $seen
                    ? e($seen['role']) . ', ' . $seen['players'] . ' online, seen ' . e($seen['seen']?->diffForHumans() ?? '?')
                    : ($nodes['reachable'] ? 'not seen in Redis' : '');
            }
            $rows .= '<tr><td style="padding:4px 8px">' . e($member->server->name) . '</td>'
                . '<td style="padding:4px 8px">' . e($member->role->getLabel()) . ($member->shard_name ? ' (' . e($member->shard_name) . ')' : '') . '</td>'
                . '<td style="padding:4px 8px">' . e($state) . '</td>'
                . '<td style="padding:4px 8px">' . $nodesInfo . '</td></tr>';
        }

        $configured = $network->leader()?->shard_name;
        if (!$nodes['reachable']) {
            $summary = '<p><b>Redis:</b> can\'t connect to ' . e($network->redis_host . ':' . $network->redis_port) . ' (' . e($nodes['error'] ?? '') . ')</p>';
        } else {
            $summary = '<p><b>Nodes leader:</b> ' . e($nodes['leader'] ?? 'none (no shard holds the lock)') . '</p>';
            if ($configured && $nodes['leader'] && $configured !== $nodes['leader']) {
                $summary .= '<p style="color:#d97706">The leader set here is ' . e($configured) . ', but ' . e($nodes['leader']) . ' holds the lock (after a failover?). Mark ' . e($nodes['leader']) . ' as the leader below and push the config, so a restart keeps it.</p>';
            }
            $unknown = array_diff(array_keys($nodes['shards']), $network->members->pluck('shard_name')->filter()->all());
            if ($unknown !== []) {
                $summary .= '<p>Also in Redis but not in this network: ' . e(implode(', ', $unknown)) . '</p>';
            }
        }

        if ($rows === '') {
            return new HtmlString($summary . '<p>No servers in this network yet.</p>');
        }

        return new HtmlString($summary . '<table style="width:100%;margin-top:8px"><thead><tr>'
            . '<th style="text-align:left;padding:4px 8px">Server</th><th style="text-align:left;padding:4px 8px">Role</th>'
            . '<th style="text-align:left;padding:4px 8px">State</th><th style="text-align:left;padding:4px 8px">Nodes</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>');
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
