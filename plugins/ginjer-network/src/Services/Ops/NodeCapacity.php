<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;

/**
 * How full each node is: what Pelican has handed out (memory, disk, ports) against what the
 * node allows, what the machine really uses right now, and whether another shard of each
 * network would fit.
 */
class NodeCapacity
{
    /**
     * @return list<array{id: int, name: string, memory: array{limit: ?int, given: int, free: ?int}, disk: array{limit: ?int, given: int, free: ?int},
     *                    ports: int, servers: int, live: ?array{memory_used: int, memory_total: int, disk_used: int, disk_total: int, cpu: float, load: float}}>
     *                    memory and disk in MB; null limit: unlimited
     */
    public function nodes(bool $live = true): array
    {
        $out = [];
        foreach (Node::query()->orderBy('name')->get() as $node) {
            $given = Server::query()->where('node_id', $node->id)->selectRaw('COALESCE(SUM(memory), 0) as memory, COALESCE(SUM(disk), 0) as disk, COUNT(*) as count')->first();
            $limit = fn (int $total, int $over) => $total <= 0 || $over < 0 ? null : (int) floor($total * (1 + $over / 100));
            $memoryLimit = $limit((int) $node->memory, (int) $node->memory_overallocate);
            $diskLimit = $limit((int) $node->disk, (int) $node->disk_overallocate);
            $stats = $live ? rescue(fn () => $node->statistics(), null, false) : null;
            $out[] = [
                'id' => $node->id,
                'name' => $node->name,
                'memory' => ['limit' => $memoryLimit, 'given' => (int) $given->memory, 'free' => $memoryLimit === null ? null : $memoryLimit - (int) $given->memory],
                'disk' => ['limit' => $diskLimit, 'given' => (int) $given->disk, 'free' => $diskLimit === null ? null : $diskLimit - (int) $given->disk],
                'ports' => Allocation::query()->where('node_id', $node->id)->whereNull('server_id')->count(),
                'servers' => (int) $given->count,
                'live' => $stats && ($stats['memory_total'] ?? 0) > 0 ? [
                    'memory_used' => (int) round($stats['memory_used'] / 1048576), 'memory_total' => (int) round($stats['memory_total'] / 1048576),
                    'disk_used' => (int) round($stats['disk_used'] / 1048576), 'disk_total' => (int) round($stats['disk_total'] / 1048576),
                    'cpu' => round((float) $stats['cpu_percent'], 1), 'load' => round((float) $stats['load_average5'], 2),
                ] : null,
            ];
        }

        return $out;
    }

    /**
     * For each network: the size of a typical shard (the median), and the nodes another one fits on.
     *
     * @param  list<array<string, mixed>>  $nodes  from nodes()
     * @return list<array{network: string, shards: int, memory: int, disk: int, fits: list<string>, how_many: int}>
     */
    public function networks(array $nodes): array
    {
        $out = [];
        foreach (Network::with('members.server')->orderBy('name')->get() as $network) {
            $servers = $network->membersWithRole(MemberRole::Shard)->map->server->filter();
            if ($servers->isEmpty()) {
                continue;
            }
            $memory = (int) $servers->pluck('memory')->median();
            $disk = (int) $servers->pluck('disk')->median();
            $fits = [];
            $howMany = 0;
            foreach ($nodes as $node) {
                $byMemory = $node['memory']['free'] === null ? PHP_INT_MAX : intdiv(max(0, $node['memory']['free']), max(1, $memory));
                $byDisk = $node['disk']['free'] === null || $disk <= 0 ? PHP_INT_MAX : intdiv(max(0, $node['disk']['free']), $disk);
                $n = min($byMemory, $byDisk, $node['ports']);
                if ($n > 0) {
                    $fits[] = $node['name'] . ($n < PHP_INT_MAX ? " ($n)" : '');
                    $howMany += $n;
                }
            }
            $out[] = ['network' => $network->name, 'shards' => $servers->count(), 'memory' => $memory, 'disk' => $disk, 'fits' => $fits, 'how_many' => $howMany];
        }

        return $out;
    }
}
