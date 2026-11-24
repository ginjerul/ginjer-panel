<?php

namespace Ginjer\Network\Services;

use Ginjer\Network\Models\Network;
use Illuminate\Support\Carbon;
use Predis\Client;
use Throwable;

/**
 * Reads what Nodes keeps in Redis about its shards:
 * - <prefix>:leader        the name of the shard holding the leader lock
 * - <prefix>:shard:<name>  "leader|follower" | last seen (ms) | players | TPS | ms per tick (newer Nodes), expires when the shard stops
 */
class NodesStatusService
{
    /**
     * @return array{reachable: bool, error: ?string, leader: ?string, shards: array<string, array{role: string, seen: ?Carbon, players: int, tps: ?float, mspt: ?float}>}
     */
    public function status(Network $network): array
    {
        $status = ['reachable' => false, 'error' => null, 'leader' => null, 'shards' => []];

        try {
            $redis = $this->client($network);
            $prefix = $network->key_prefix;

            $leader = $redis->get("$prefix:leader");
            $status['leader'] = $leader === null ? null : (string) $leader;

            $shards = [];
            foreach ($redis->keys("$prefix:shard:*") as $key) {
                $name = substr((string) $key, strlen("$prefix:shard:"));
                $parts = explode('|', (string) $redis->get($key));
                $shards[$name] = [
                    'role' => $parts[0] ?? '?',
                    'seen' => isset($parts[1]) && is_numeric($parts[1]) ? Carbon::createFromTimestampMs((int) $parts[1]) : null,
                    'players' => (int) ($parts[2] ?? 0),
                    'tps' => isset($parts[3]) && is_numeric($parts[3]) ? (float) $parts[3] : null,
                    'mspt' => isset($parts[4]) && is_numeric($parts[4]) ? (float) $parts[4] : null,
                ];
            }
            ksort($shards);
            $status['shards'] = $shards;
            $status['reachable'] = true;
            $redis->disconnect();
        } catch (Throwable $exception) {
            $status['error'] = $exception->getMessage();
        }

        return $status;
    }

    /**
     * Who is online on every shard, from what each shard's Nodes writes to <prefix>:presence.
     *
     * @return array{reachable: bool, error: ?string, players: list<array{uuid: string, name: string, shard: string, ping: ?int, where: ?string, staff: bool}>}
     */
    public function presence(Network $network): array
    {
        try {
            $redis = $this->client($network);
            $hash = $redis->hgetall("{$network->key_prefix}:presence");
            $redis->disconnect();

            return ['reachable' => true, 'error' => null, 'players' => self::parsePresence($hash, now()->getTimestampMs())];
        } catch (Throwable $exception) {
            return ['reachable' => false, 'error' => $exception->getMessage(), 'players' => []];
        }
    }

    /**
     * @param  array<string, string>  $hash  shard => {"presence": {uuid: name}, "staff": {uuid: rank}, "ping": {...}, "where": {...}, "time": ms}
     * @return list<array{uuid: string, name: string, shard: string, ping: ?int, where: ?string, staff: bool}>
     */
    public static function parsePresence(array $hash, int $nowMs, int $maxAgeMs = 120_000): array
    {
        $players = [];
        foreach ($hash as $shard => $json) {
            $data = json_decode((string) $json, true);
            if (!is_array($data) || ($nowMs - (int) ($data['time'] ?? 0)) > $maxAgeMs) {
                continue; // a shard that stopped reporting
            }
            foreach ((array) ($data['presence'] ?? []) as $uuid => $name) {
                // a player moving between shards can show on both for a moment: keep the newest report
                if (isset($players[$uuid]) && $players[$uuid]['time'] >= (int) $data['time']) {
                    continue;
                }
                $players[$uuid] = [
                    'uuid' => (string) $uuid, 'name' => (string) $name, 'shard' => (string) $shard,
                    'ping' => isset($data['ping'][$uuid]) ? (int) $data['ping'][$uuid] : null,
                    'where' => isset($data['where'][$uuid]) ? (string) $data['where'][$uuid] : null,
                    'staff' => isset($data['staff'][$uuid]), 'time' => (int) $data['time'],
                ];
            }
        }
        $players = array_map(function ($p) {
            unset($p['time']);

            return $p;
        }, array_values($players));
        usort($players, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $players;
    }

    private function client(Network $network): Client
    {
        $timeout = (float) config('ginjer-network.redis_timeout', 2);

        return new Client([
            'scheme' => 'tcp',
            'host' => $network->redis_host,
            'port' => $network->redis_port,
            'password' => filled($network->redis_password) ? $network->redis_password : null,
            'database' => $network->redis_database,
            'timeout' => $timeout,
            'read_write_timeout' => $timeout,
        ]);
    }
}
