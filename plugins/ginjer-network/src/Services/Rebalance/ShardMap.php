<?php

namespace Ginjer\Network\Services\Rebalance;

/**
 * Which shard owns a position, exactly as Reef decides it (dev.reef.sharding.Sharding): the first
 * region in the list that contains the point; rectangles are min-inclusive and max-exclusive,
 * circles are 32 to 1024-sided polygons, smooth polygons a Catmull-Rom curve. A chunk belongs to
 * the shard that owns its centre.
 */
final class ShardMap
{
    /** @var list<array{server: string, xs: list<float>, zs: list<float>, rect: bool, box: array{0: float, 1: float, 2: float, 3: float}}> */
    private array $regions = [];

    /** @param  list<array<string, mixed>>  $regions  the parsed regions list */
    public function __construct(array $regions)
    {
        foreach ($regions as $map) {
            $this->regions[] = self::shape($map);
        }
    }

    public function ownerAt(float $x, float $z): ?string
    {
        foreach ($this->regions as $r) {
            if (self::contains($r, $x, $z)) {
                return $r['server'];
            }
        }

        return null;
    }

    public function chunkOwner(int $chunkX, int $chunkZ): ?string
    {
        return $this->ownerAt(($chunkX << 4) + 8.5, ($chunkZ << 4) + 8.5);
    }

    private static function shape(array $map): array
    {
        $server = (string) ($map['server'] ?? '');
        if (isset($map['points']) && is_array($map['points'])) {
            $pts = array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $map['points']);
            if (($map['smooth'] ?? false) === true) {
                $pts = self::smooth($pts);
            }

            return self::fromPoints($server, $pts, false);
        }
        if (array_key_exists('center-x', $map)) {
            $cx = (float) $map['center-x'];
            $cz = (float) $map['center-z'];
            $rx = (float) ($map['radius-x'] ?? $map['radius']);
            $rz = (float) ($map['radius-z'] ?? $map['radius']);
            $n = (int) min(1024, max(32, ceil(2 * M_PI * max($rx, $rz) / 8)));
            $pts = [];
            for ($i = 0; $i < $n; $i++) {
                $pts[] = [$cx + $rx * cos(2 * M_PI * $i / $n), $cz + $rz * sin(2 * M_PI * $i / $n)];
            }

            return self::fromPoints($server, $pts, false);
        }
        $x0 = (float) $map['min-x'];
        $z0 = (float) $map['min-z'];
        $x1 = (float) $map['max-x'];
        $z1 = (float) $map['max-z'];

        return self::fromPoints($server, [[$x0, $z0], [$x1, $z0], [$x1, $z1], [$x0, $z1]], true);
    }

    private static function fromPoints(string $server, array $pts, bool $rect): array
    {
        $xs = array_column($pts, 0);
        $zs = array_column($pts, 1);

        return ['server' => $server, 'xs' => $xs, 'zs' => $zs, 'rect' => $rect, 'box' => [min($xs), min($zs), max($xs), max($zs)]];
    }

    private static function contains(array $r, float $x, float $z): bool
    {
        [$x0, $z0, $x1, $z1] = $r['box'];
        if ($x < $x0 || $x >= $x1 || $z < $z0 || $z >= $z1) {
            return false;
        }
        if ($r['rect']) {
            return true;
        }
        $inside = false;
        $xs = $r['xs'];
        $zs = $r['zs'];
        $n = count($xs);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            if (($zs[$i] > $z) !== ($zs[$j] > $z)
                && $x < ($xs[$j] - $xs[$i]) * ($z - $zs[$i]) / ($zs[$j] - $zs[$i]) + $xs[$i]) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /** Closed Catmull-Rom spline through the points (Reef's and GinjerMap's maths). */
    private static function smooth(array $p): array
    {
        $n = count($p);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $p0 = $p[($i + $n - 1) % $n];
            $p1 = $p[$i];
            $p2 = $p[($i + 1) % $n];
            $p3 = $p[($i + 2) % $n];
            $steps = (int) min(256, max(4, ceil(hypot($p2[0] - $p1[0], $p2[1] - $p1[1]) / 8)));
            for ($s = 0; $s < $steps; $s++) {
                $t = $s / $steps;
                $t2 = $t * $t;
                $t3 = $t2 * $t;
                $pt = [];
                for ($k = 0; $k < 2; $k++) {
                    $pt[$k] = 0.5 * (2 * $p1[$k] + (-$p0[$k] + $p2[$k]) * $t + (2 * $p0[$k] - 5 * $p1[$k] + 4 * $p2[$k] - $p3[$k]) * $t2
                        + (-$p0[$k] + 3 * $p1[$k] - 3 * $p2[$k] + $p3[$k]) * $t3);
                }
                $out[] = $pt;
            }
        }

        return $out;
    }
}
