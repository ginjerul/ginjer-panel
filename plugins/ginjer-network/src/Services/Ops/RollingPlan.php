<?php

namespace Ginjer\Network\Services\Ops;

/**
 * The order a rolling restart or update goes through a network's servers, in batches that
 * restart together. Pure, so it can be tested.
 *
 *  - followers_first (default): followers, then the leader. The leader holds the Nodes state; it
 *    restarts once the others have come back, so they can take over quickly if it fails.
 *  - leader_first: the leader, then the followers (when an update changes what followers expect).
 *  - by_name: shard names in alphabetical order.
 * The leader always restarts on its own, never in a batch with followers. The proxy, if included,
 * comes last on its own (restarting it disconnects everyone, so it's off by default).
 */
final class RollingPlan
{
    public const ORDERS = [
        'followers_first' => 'Followers first, leader last',
        'leader_first' => 'Leader first, then followers',
        'by_name' => 'By shard name',
    ];

    /**
     * @param  list<array{id: int, name: string, leader: bool}>  $shards
     * @param  ?int  $proxyId  restarted last when given
     * @return list<list<int>> batches of server ids
     */
    public static function batches(array $shards, string $order, int $batchSize, ?int $proxyId = null): array
    {
        $batchSize = max(1, $batchSize);
        usort($shards, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $leader = array_values(array_filter($shards, fn ($s) => $s['leader']));
        $followers = array_values(array_filter($shards, fn ($s) => !$s['leader']));

        $chunk = fn (array $list) => array_map(fn ($batch) => array_column($batch, 'id'), array_chunk($list, $batchSize));
        $batches = match ($order) {
            'leader_first' => array_merge($chunk($leader), $chunk($followers)),
            'by_name' => self::byName($shards, $batchSize),
            default => array_merge($chunk($followers), $chunk($leader)),
        };
        if ($proxyId !== null) {
            $batches[] = [$proxyId];
        }

        return array_values(array_filter($batches, fn ($b) => $b !== []));
    }

    /** Alphabetical, in batches, with the leader split out into a batch of its own. */
    private static function byName(array $shards, int $batchSize): array
    {
        $batches = [];
        $current = [];
        foreach ($shards as $shard) {
            if ($shard['leader']) {
                if ($current !== []) {
                    $batches[] = $current;
                    $current = [];
                }
                $batches[] = [$shard['id']];

                continue;
            }
            $current[] = $shard['id'];
            if (count($current) >= $batchSize) {
                $batches[] = $current;
                $current = [];
            }
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }
}
