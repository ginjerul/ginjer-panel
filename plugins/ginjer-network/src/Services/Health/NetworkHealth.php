<?php

namespace Ginjer\Network\Services\Health;

use App\Models\Role;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Jobs\RestartShard;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\NodesStatusService;
use Ginjer\Network\Services\Ops\Busy;
use Illuminate\Support\Facades\Cache;

/** Looks at every shard of a network: Wings' state and resources, and what Nodes reports in Redis. */
class NetworkHealth
{
    public function __construct(private DaemonServerRepository $daemon, private NodesStatusService $nodes) {}

    /**
     * @return array<string, array{server: string, state: ?string, memory_mb: ?int, memory_limit_mb: ?int, cpu: ?float, uptime_s: ?int,
     *                              reporting: bool, role: ?string, players: ?int, tps: ?float, mspt: ?float}>
     */
    public function shards(Network $network): array
    {
        $status = $this->nodes->status($network);
        $out = [];
        foreach ($network->loadMissing('members.server')->membersWithRole(MemberRole::Shard) as $shard) {
            $details = rescue(fn () => $this->daemon->setServer($shard->server)->getDetailsIfAnswered(3), null, false);
            $usage = $details['utilization'] ?? [];
            $nodes = $status['shards'][$shard->shard_name] ?? null;
            $out[$shard->shard_name] = [
                'server' => $shard->server->name,
                'state' => $details['state'] ?? null,
                'memory_mb' => isset($usage['memory_bytes']) ? (int) round($usage['memory_bytes'] / 1048576) : null,
                'memory_limit_mb' => isset($usage['memory_limit_bytes']) ? (int) round($usage['memory_limit_bytes'] / 1048576) : null,
                'cpu' => isset($usage['cpu_absolute']) ? round((float) $usage['cpu_absolute'], 1) : null,
                'uptime_s' => isset($usage['uptime']) ? (int) ($usage['uptime'] / 1000) : null,
                'reporting' => $nodes !== null,
                'role' => $nodes['role'] ?? null,
                'players' => $nodes['players'] ?? null,
                'tps' => $nodes['tps'] ?? null,
                'mspt' => $nodes['mspt'] ?? null,
            ];
        }

        return $out;
    }

    /** One check: alerts root admins about changes and restarts lagging shards if the network says so. */
    public function check(Network $network): array
    {
        $shards = $this->shards($network);
        $key = "ginjer-network:health:{$network->id}";
        $result = HealthRules::evaluate(
            array_map(fn ($s) => ['state' => $s['state'], 'reporting' => $s['reporting'], 'tps' => $s['tps'], 'mspt' => $s['mspt']], $shards),
            Cache::get($key, []),
            ['lag_tps' => $network->lag_tps, 'lag_minutes' => $network->lag_minutes, 'restart_on_lag' => $network->restart_on_lag],
            Busy::is($network->id),
        );
        Cache::forever($key, $result['memory']);

        if ($network->health_alerts && $result['alerts'] !== []) {
            $admins = User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::ROOT_ADMIN))->get();
            foreach ($result['alerts'] as $alert) {
                foreach ($admins as $admin) {
                    rescue(fn () => Notification::make()
                        ->title("{$network->name}: {$alert['title']}")
                        ->body($alert['body'])
                        ->status($alert['level'])
                        ->actions([Action::make('open')->label('Open network')->url(NetworkResource::getUrl('edit', ['record' => $network], panel: 'admin'))])
                        ->sendToDatabase($admin), report: false);
                }
            }
        }
        foreach ($result['restart'] as $name) {
            $member = $network->members->firstWhere('shard_name', $name);
            if ($member) {
                RestartShard::dispatch($member->server_id, $network->id);
            }
        }

        return $result;
    }
}
