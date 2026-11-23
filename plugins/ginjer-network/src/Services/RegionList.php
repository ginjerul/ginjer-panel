<?php

namespace Ginjer\Network\Services;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The shard regions, written as the same YAML list Reef reads from reef.yml (sharding.regions):
 *
 *   - {server: americas, min-x: -40000, min-z: -20000, max-x: -8000, max-z: 20000}
 *   - {server: europe, points: [[0, 0], [100, 0], [100, 100]], smooth: true}
 *   - {server: asia, center-x: 0, center-z: 0, radius: 500}
 *
 * The checks match the ones Reef makes when it loads the list, so a list that passes here is not
 * silently skipped by a shard.
 */
final class RegionList
{
    /** @return list<array<string, mixed>> */
    public static function parse(string $yaml): array
    {
        if (trim($yaml) === '') {
            return [];
        }

        $data = Yaml::parse($yaml);
        if (!is_array($data) || !array_is_list($data)) {
            throw new \InvalidArgumentException('Regions must be a YAML list, one "- {server: ...}" per area.');
        }

        return $data;
    }

    /**
     * Problems with the list, empty when it's fine.
     *
     * @param  list<string>  $shardNames  the shard names in this network; empty skips the name check
     * @return list<string>
     */
    public static function problems(string $yaml, array $shardNames = []): array
    {
        try {
            $regions = self::parse($yaml);
        } catch (ParseException $exception) {
            return ['Not valid YAML: ' . $exception->getMessage()];
        } catch (\InvalidArgumentException $exception) {
            return [$exception->getMessage()];
        }

        $problems = [];
        foreach ($regions as $i => $region) {
            $n = $i + 1;
            if (!is_array($region)) {
                $problems[] = "Area $n is not a {server: ...} map.";

                continue;
            }

            $server = $region['server'] ?? null;
            if (!is_string($server) || $server === '') {
                $problems[] = "Area $n has no server name.";
            } elseif ($shardNames !== [] && !in_array($server, $shardNames, true)) {
                $problems[] = "Area $n belongs to \"$server\", which is not a shard in this network (shards: " . implode(', ', $shardNames) . ').';
            }

            $shapeProblem = self::shapeProblem($region);
            if ($shapeProblem !== null) {
                $problems[] = "Area $n: $shapeProblem";
            }
        }

        if ($shardNames !== []) {
            $owners = array_map(fn ($region) => is_array($region) ? ($region['server'] ?? null) : null, $regions);
            foreach ($shardNames as $shard) {
                if (!in_array($shard, $owners, true)) {
                    $problems[] = "Shard \"$shard\" owns no area, so players could never reach it.";
                }
            }
        }

        return $problems;
    }

    /** @param  array<string, mixed>  $region */
    private static function shapeProblem(array $region): ?string
    {
        if (array_key_exists('points', $region)) {
            $points = $region['points'];
            if (!is_array($points) || count($points) < 3) {
                return 'a polygon needs at least 3 points.';
            }
            foreach ($points as $point) {
                if (!is_array($point) || count($point) < 2 || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
                    return 'every point must be [x, z].';
                }
            }

            return null;
        }

        if (array_key_exists('center-x', $region)) {
            if (!is_numeric($region['center-z'] ?? null)) {
                return 'a circle needs center-x and center-z.';
            }
            $rx = $region['radius-x'] ?? $region['radius'] ?? null;
            $rz = $region['radius-z'] ?? $region['radius'] ?? null;
            if (!is_numeric($rx) || !is_numeric($rz) || $rx <= 0 || $rz <= 0) {
                return 'radius must be above 0.';
            }

            return null;
        }

        foreach (['min-x', 'min-z', 'max-x', 'max-z'] as $key) {
            if (!is_numeric($region[$key] ?? null)) {
                return "a rectangle needs min-x, min-z, max-x and max-z ($key is missing).";
            }
        }
        if ($region['min-x'] >= $region['max-x'] || $region['min-z'] >= $region['max-z']) {
            return 'min must be below max.';
        }

        return null;
    }
}
