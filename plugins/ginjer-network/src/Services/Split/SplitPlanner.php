<?php

namespace Ginjer\Network\Services\Split;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Throwable;

/**
 * Works out how to split one server into shards, without changing anything:
 * the regions (balanced by where Nodes players are), which region the original server keeps,
 * RAM for each shard, and a node and port for each new one.
 *
 * The original server becomes the Nodes leader and keeps the region around 0, 0 (where new
 * players spawn), plus the Nether and the End.
 */
class SplitPlanner
{
    public function __construct(private DaemonFileRepository $files) {}

    /**
     * @param  list<int>  $nodeIds  nodes new shards may go on (empty: the source's node)
     * @return array{
     *     source_id: int, world: string, players: int, count: int,
     *     stats: array{territories: int, towns: int, residents: int, from_nodes: bool},
     *     shards: list<array{name: string, source: bool, leader: bool, region: array{min-x: int, min-z: int, max-x: int, max-z: int}, weight: float, players: int, memory: int, node_id: ?int, node_name: string, allocation_id: ?int, address: string}>,
     *     warnings: list<string>, errors: list<string>
     * }
     */
    public function plan(Server $source, ?int $players = null, ?int $count = null, array $nodeIds = [], string $world = 'world'): array
    {
        $warnings = [];
        $errors = [];

        // ---- where the players are
        $weights = ['points' => [], 'territories' => 0, 'towns' => 0, 'residents' => 0];
        $fromNodes = false;
        $worldJson = $this->read($source, 'plugins/nodes/world.json');
        if ($worldJson !== null) {
            try {
                $weights = WorldWeights::fromNodesFiles($worldJson, $this->read($source, 'plugins/nodes/towns.json'));
                $fromNodes = $weights['points'] !== [];
            } catch (Throwable $exception) {
                $warnings[] = 'Couldn\'t read Nodes\' world.json (' . $exception->getMessage() . '), so the map is split evenly.';
            }
        } else {
            $warnings[] = 'No plugins/nodes/world.json on this server, so the map around spawn is split evenly instead of by where towns are.';
        }

        if ($this->read($source, 'reef.yml') === null) {
            $warnings[] = 'This server isn\'t running Reef yet (no reef.yml). Switch it to the Reef jar, or the shards won\'t hand players over at borders.';
        }

        $players ??= ResourcePlanner::guessPeakPlayers($weights['residents']);
        $count ??= ResourcePlanner::shardCountFor($players);
        $count = max(2, min(16, $count));
        if ($players < ResourcePlanner::WORTH_SHARDING_PLAYERS) {
            $warnings[] = "With about $players players at peak, one server is usually simpler and faster than shards. Splitting is worth it from roughly " . ResourcePlanner::WORTH_SHARDING_PLAYERS . ' players.';
        }

        // ---- regions
        $regions = RegionSplitter::split($weights['points'], $count);
        $names = $this->names($regions);

        // the original server keeps the region with world spawn
        $sourceIndex = 0;
        foreach ($regions as $i => $region) {
            if ($region['min-x'] <= 0 && 0 < $region['max-x'] && $region['min-z'] <= 0 && 0 < $region['max-z']) {
                $sourceIndex = $i;
                break;
            }
        }

        // ---- RAM, nodes, ports
        $nodeIds = $nodeIds === [] ? [$source->node_id] : array_values(array_unique(array_map('intval', $nodeIds)));
        $nodes = Node::query()->whereIn('id', $nodeIds)->get()->keyBy('id');
        $free = $nodes->map(fn (Node $node) => $this->freeMemory($node))->all();

        $shards = [];
        foreach ($regions as $i => $region) {
            $isSource = $i === $sourceIndex;
            $shardPlayers = (int) round($players * $region['weight']);
            $shards[] = [
                'name' => $names[$i],
                'source' => $isSource,
                'leader' => $isSource,
                'region' => array_intersect_key($region, array_flip(['min-x', 'min-z', 'max-x', 'max-z'])),
                'weight' => $region['weight'],
                'players' => $shardPlayers,
                'memory' => ResourcePlanner::memoryFor($shardPlayers, $isSource),
                'node_id' => $isSource ? $source->node_id : null,
                'node_name' => $isSource ? $source->node->name : '',
                'allocation_id' => $isSource ? $source->allocation_id : null,
                'address' => $isSource ? ($source->allocation?->address ?? '') : '',
            ];
        }

        // the source only needs the difference on its own node
        $sourceNode = $source->node_id;
        $free[$sourceNode] ??= $this->freeMemory($source->node);
        $free[$sourceNode] -= $shards[$sourceIndex]['memory'] - $source->memory;

        $taken = [];
        $order = array_keys($shards);
        usort($order, fn ($a, $b) => $shards[$b]['memory'] <=> $shards[$a]['memory']); // biggest first
        foreach ($order as $i) {
            if ($shards[$i]['source']) {
                continue;
            }
            $need = $shards[$i]['memory'];
            $candidates = array_filter($nodeIds, fn ($id) => isset($nodes[$id]) && ($free[$id] ?? 0) >= $need);
            usort($candidates, fn ($a, $b) => ($free[$b] ?? 0) <=> ($free[$a] ?? 0));

            $placed = false;
            foreach ($candidates as $nodeId) {
                $allocation = Allocation::query()
                    ->where('node_id', $nodeId)
                    ->whereNull('server_id')
                    ->whereNotIn('id', $taken)
                    ->orderByRaw('CASE WHEN ip = ? THEN 0 ELSE 1 END', [$source->allocation?->ip ?? ''])
                    ->orderBy('port')
                    ->first();
                if ($allocation === null) {
                    continue;
                }
                $taken[] = $allocation->id;
                $free[$nodeId] -= $need;
                $shards[$i]['node_id'] = $nodeId;
                $shards[$i]['node_name'] = $nodes[$nodeId]->name;
                $shards[$i]['allocation_id'] = $allocation->id;
                $shards[$i]['address'] = $allocation->address;
                $placed = true;
                break;
            }
            if (!$placed) {
                $errors[] = "No room for shard \"{$shards[$i]['name']}\" ({$need} MB): no chosen node has that much free memory and a free port. Add allocations or pick more nodes.";
            }
        }

        if ($free[$sourceNode] < 0) {
            $errors[] = "The original server needs {$shards[$sourceIndex]['memory']} MB, which is more than its node {$source->node->name} has free.";
        }

        return [
            'source_id' => $source->id,
            'world' => $world,
            'players' => $players,
            'count' => $count,
            'stats' => ['territories' => $weights['territories'], 'towns' => $weights['towns'], 'residents' => $weights['residents'], 'from_nodes' => $fromNodes],
            'shards' => $shards,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    /** Memory a node can still give out, in MB. Same rule as Node::isViable(): no limit or -1 overallocation means unlimited. */
    public function freeMemory(Node $node): int
    {
        if ($node->memory <= 0 || $node->memory_overallocate < 0) {
            return PHP_INT_MAX >> 1;
        }
        $limit = (int) floor($node->memory * (1 + $node->memory_overallocate / 100));

        return $limit - (int) Server::query()->where('node_id', $node->id)->sum('memory');
    }

    /**
     * Names by direction for two shards, numbered otherwise (sorted west to east, then north to south).
     *
     * @param  list<array{min-x: int, min-z: int, max-x: int, max-z: int}>  $regions
     * @return list<string>
     */
    private function names(array $regions): array
    {
        if (count($regions) === 2) {
            $splitOnX = $regions[0]['max-x'] !== $regions[1]['max-x'];
            $firstIsLow = $splitOnX ? $regions[0]['max-x'] < $regions[1]['max-x'] : $regions[0]['max-z'] < $regions[1]['max-z'];
            [$low, $high] = $splitOnX ? ['west', 'east'] : ['north', 'south'];

            return $firstIsLow ? [$low, $high] : [$high, $low];
        }

        $order = array_keys($regions);
        $centre = fn ($r, $axis) => (max(-50000, $r["min-$axis"]) + min(50000, $r["max-$axis"])) / 2;
        usort($order, fn ($a, $b) => [$centre($regions[$a], 'x'), $centre($regions[$a], 'z')] <=> [$centre($regions[$b], 'x'), $centre($regions[$b], 'z')]);
        $names = [];
        foreach ($order as $n => $i) {
            $names[$i] = 'shard-' . ($n + 1);
        }
        ksort($names);

        return array_values($names);
    }

    private function read(Server $server, string $path): ?string
    {
        try {
            return (string) $this->files->setServer($server)->getContent($path);
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
