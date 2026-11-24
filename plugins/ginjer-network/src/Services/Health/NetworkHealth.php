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

    /**
     * Stores this minute's numbers for the History page and drops what's older than a week.
     *
     * @param  array<string, array<string, mixed>>  $shards
     */
    private function record(Network $network, array $shards): void
    {
        $now = now()->startOfMinute();
        foreach ($shards as $name => $s) {
            \Ginjer\Network\Models\ShardMetric::create([
                'network_id' => $network->id, 'shard' => $name, 'recorded_at' => $now,
                'tps' => $s['tps'], 'mspt' => $s['mspt'], 'players' => $s['players'],
                'memory_mb' => $s['memory_mb'], 'memory_limit_mb' => $s['memory_limit_mb'], 'cpu' => $s['cpu'],
            ]);
        }
        \Ginjer\Network\Models\ShardMetric::where('network_id', $network->id)->where('recorded_at', '<', now()->subDays(\Ginjer\Network\Models\ShardMetric::KEEP_DAYS))->delete();
    }

    /** Starts a 60-second spark profile; the link goes to every root admin. */
    private function profile(\App\Models\Server $server): void
    {
        $tools = app(\Ginjer\Network\Services\Ops\ServerTools::class);
        if ($tools->findPlugin($server, \Ginjer\Network\Services\Ops\ServerTools::SPARK) === null) {
            return;
        }
        $previous = rescue(fn () => $tools->sparkLink($server), null, false);
        $server->send('spark profiler start --timeout 60');
        \Ginjer\Network\Jobs\ProfileWithSpark::dispatch($server->id, null, 60, $previous);
    }

    /** One check: alerts root admins about changes and restarts lagging shards if the network says so. */
    public function check(Network $network): array
    {
        $shards = $this->shards($network);
        $this->record($network, $shards);
        // where players are, for the rebalancer
        rescue(function () use ($network, $shards) {
            $presence = $this->nodes->presence($network);
            if ($presence['players'] !== []) {
                \Ginjer\Network\Services\Rebalance\LoadSampler::store($network, \Ginjer\Network\Services\Rebalance\LoadSampler::cells(
                    $presence['players'], $network->world ?: 'world', array_map(fn ($s) => $s['mspt'], $shards)));
            }
        }, report: false);
        $key = "ginjer-network:health:{$network->id}";
        $result = HealthRules::evaluate(
            array_map(fn ($s) => ['state' => $s['state'], 'reporting' => $s['reporting'], 'tps' => $s['tps'], 'mspt' => $s['mspt']], $shards),
            Cache::get($key, []),
            ['lag_tps' => $network->lag_tps, 'lag_minutes' => $network->lag_minutes, 'restart_on_lag' => $network->restart_on_lag],
            Busy::is($network->id),
        );
        Cache::forever($key, $result['memory']);

        foreach ($result['alerts'] as $alert) {
            \Ginjer\Network\Services\Activity\NetworkLog::record($network, 'health.' . ($alert['kind'] ?? 'other'), $alert['title'], $alert['body'], $alert['level'], null);
        }
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
        // a shard went down or froze: find out why while its log still says so
        foreach ($result['alerts'] as $alert) {
            if (in_array($alert['kind'] ?? null, ['down', 'silent'], true) && ($member = $network->members->firstWhere('shard_name', $alert['shard']))) {
                \Ginjer\Network\Jobs\AnalyzeCrash::dispatch($member->server_id, $network->id, $alert['shard'], $alert['kind']);
            }
        }
        // catch the lag while it's happening: profile with spark if it's installed
        foreach ($result['lagging'] as $name) {
            $member = $network->members->firstWhere('shard_name', $name);
            if ($member) {
                rescue(fn () => $this->profile($member->server), report: false);
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
