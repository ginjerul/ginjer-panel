<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\RegionList;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chunky (map pre-generation) and spark (profiling) for a server: finds them, installs them from
 * Modrinth when they're missing (the same API and Wings download Pelican's Modrinth plugin uses),
 * and builds the console commands.
 */
class ServerTools
{
    public const CHUNKY = 'chunky';

    public const SPARK = 'spark';

    public function __construct(private DaemonFileRepository $files) {}

    /** The enabled jar in plugins/ whose name starts with $name, or null. */
    public function findPlugin(Server $server, string $name): ?string
    {
        return collect($this->files->setServer($server)->getDirectory('plugins'))
            ->pluck('name')
            ->first(fn ($file) => preg_match('/^' . preg_quote($name, '/') . '[^\/]*\.jar$/i', (string) $file));
    }

    /** Downloads the newest Paper build of a Modrinth project into plugins/. Returns the file name. */
    public function installFromModrinth(Server $server, string $project): string
    {
        $versions = Http::asJson()->timeout(10)->withHeaders(['User-Agent' => 'ginjer-network'])
            ->get("https://api.modrinth.com/v2/project/$project/version", ['loaders' => '["paper"]'])
            ->throw()
            ->json();
        $file = collect($versions[0]['files'] ?? [])->first(fn ($f) => !empty($f['primary'])) ?? ($versions[0]['files'][0] ?? null);
        if ($file === null || !preg_match('/^[\w.+-]+\.jar$/', $file['filename'] ?? '')) {
            throw new RuntimeException("Modrinth has no Paper download for $project.");
        }

        $this->files->setServer($server)->getHttpClient()->timeout(600)->post("/api/servers/{$server->uuid}/files/pull", [
            'url' => $file['url'],
            'root' => '/plugins',
            'file_name' => $file['filename'],
            'foreground' => true,
        ]);

        return $file['filename'];
    }

    /**
     * The area to pre-generate: a square of $radius blocks around 0, 0, or, on a shard, only the
     * part of that square the shard owns (other shards' chunks would be wasted on its copy).
     *
     * @return array{min-x: int, min-z: int, max-x: int, max-z: int}|null null: the shard owns none of it
     */
    public static function area(?array $regions, ?string $shard, int $radius): ?array
    {
        $square = ['min-x' => -$radius, 'min-z' => -$radius, 'max-x' => $radius, 'max-z' => $radius];
        if ($regions === null || $shard === null) {
            return $square;
        }
        $box = null;
        foreach ($regions as $region) {
            if (($region['server'] ?? null) !== $shard) {
                continue;
            }
            $b = self::bounds($region);
            $box = $box === null ? $b : [
                'min-x' => min($box['min-x'], $b['min-x']), 'min-z' => min($box['min-z'], $b['min-z']),
                'max-x' => max($box['max-x'], $b['max-x']), 'max-z' => max($box['max-z'], $b['max-z']),
            ];
        }
        if ($box === null) {
            return null;
        }
        $clipped = [
            'min-x' => max($box['min-x'], -$radius), 'min-z' => max($box['min-z'], -$radius),
            'max-x' => min($box['max-x'], $radius), 'max-z' => min($box['max-z'], $radius),
        ];

        return $clipped['min-x'] < $clipped['max-x'] && $clipped['min-z'] < $clipped['max-z'] ? $clipped : null;
    }

    /** @return list<string> Chunky console commands for an area */
    public static function chunkyCommands(string $world, array $area): array
    {
        $cx = intdiv($area['min-x'] + $area['max-x'], 2);
        $cz = intdiv($area['min-z'] + $area['max-z'], 2);
        $rx = intdiv($area['max-x'] - $area['min-x'], 2);
        $rz = intdiv($area['max-z'] - $area['min-z'], 2);

        return [
            "chunky world $world",
            'chunky shape rectangle',
            "chunky center $cx $cz",
            "chunky radius $rx $rz",
            'chunky start',
        ];
    }

    /** The shard name and regions of a server that's a shard in a network, or nulls. */
    public static function shardOf(Server $server): array
    {
        $member = NetworkMember::with('network')->where('server_id', $server->id)->first();
        if ($member === null || $member->role !== MemberRole::Shard) {
            return [null, null];
        }

        return [$member->shard_name, RegionList::parse($member->network->regions ?? '')];
    }

    /** The newest spark viewer link in the server's log, or null. */
    public function sparkLink(Server $server): ?string
    {
        $log = (string) $this->files->setServer($server)->getContent('logs/latest.log', 20 * 1024 * 1024);
        preg_match_all('#https://spark\.lucko\.me/[A-Za-z0-9]+#', $log, $m);

        return $m[0] === [] ? null : end($m[0]);
    }

    /** @param  array<string, mixed>  $region */
    private static function bounds(array $region): array
    {
        if (isset($region['points'])) {
            $xs = array_column($region['points'], 0);
            $zs = array_column($region['points'], 1);

            return ['min-x' => (int) min($xs), 'min-z' => (int) min($zs), 'max-x' => (int) max($xs), 'max-z' => (int) max($zs)];
        }
        if (isset($region['center-x'])) {
            $rx = $region['radius-x'] ?? $region['radius'];
            $rz = $region['radius-z'] ?? $region['radius'];

            return ['min-x' => (int) ($region['center-x'] - $rx), 'min-z' => (int) ($region['center-z'] - $rz), 'max-x' => (int) ($region['center-x'] + $rx), 'max-z' => (int) ($region['center-z'] + $rz)];
        }

        return ['min-x' => (int) $region['min-x'], 'min-z' => (int) $region['min-z'], 'max-x' => (int) $region['max-x'], 'max-z' => (int) $region['max-z']];
    }
}
