<?php

namespace Ginjer\Network\Services\Split;

/**
 * Splits the world into N rectangles holding about the same weight each, by cutting it in two
 * again and again (a k-d split): each cut goes across the longer side of the weighted points, at
 * the point where the weight on each side matches how many shards that side gets.
 *
 * Cuts land on chunk borders (multiples of 16), as Reef recommends. The outer rectangles reach
 * the edge of the Minecraft world, so no position is ever outside every region (Reef pushes
 * players back from such places).
 */
final class RegionSplitter
{
    /** The edge of a Minecraft world, on a chunk border. */
    public const WORLD_EDGE = 29999984;

    /**
     * @param  list<array{0: int|float, 1: int|float, 2: float}>  $points  [x, z, weight]
     * @param  array{0: int, 1: int, 2: int, 3: int}|null  $emptyArea  [minX, minZ, maxX, maxZ] split evenly when there are no points
     * @return list<array{min-x: int, min-z: int, max-x: int, max-z: int, weight: float}>
     */
    public static function split(array $points, int $count, ?array $emptyArea = null): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Need at least one shard.');
        }
        $box = [-self::WORLD_EDGE, -self::WORLD_EDGE, self::WORLD_EDGE, self::WORLD_EDGE];
        $emptyArea ??= [-5000, -5000, 5000, 5000];

        if ($points === []) {
            // no Nodes data: split an even grid of points over the area people use
            [$x0, $z0, $x1, $z1] = $emptyArea;
            for ($x = $x0 + 8; $x < $x1; $x += 128) {
                for ($z = $z0 + 8; $z < $z1; $z += 128) {
                    $points[] = [$x, $z, 1.0];
                }
            }
        }

        $total = array_sum(array_column($points, 2)) ?: 1.0;
        $regions = [];
        self::cut($box, $points, $count, $regions);

        return array_map(fn ($r) => [
            'min-x' => $r[0], 'min-z' => $r[1], 'max-x' => $r[2], 'max-z' => $r[3],
            'weight' => round($r[4] / $total, 4),
        ], $regions);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $box
     * @param  list<array{0: int|float, 1: int|float, 2: float}>  $points
     * @param  list<array{0: int, 1: int, 2: int, 3: int, 4: float}>  $out
     */
    private static function cut(array $box, array $points, int $count, array &$out): void
    {
        $weight = array_sum(array_column($points, 2));
        if ($count === 1) {
            $out[] = [...$box, $weight];

            return;
        }

        $leftCount = intdiv($count, 2);
        $target = $weight * $leftCount / $count;

        // cut across the axis the points are spread along most (ignoring the outer 5% each side)
        $axis = self::spread($points, 0) >= self::spread($points, 1) ? 0 : 1;
        usort($points, fn ($a, $b) => $a[$axis] <=> $b[$axis]);

        $lo = $box[$axis] + 16;
        $hi = $box[$axis + 2] - 16;
        $cut = null;
        $sum = 0.0;
        $n = count($points);
        for ($i = 0; $i < $n; $i++) {
            $sum += $points[$i][2];
            if ($sum >= $target && $i + 1 < $n && $points[$i + 1][$axis] > $points[$i][$axis]) {
                $cut = self::chunkBorder(($points[$i][$axis] + $points[$i + 1][$axis]) / 2);
                break;
            }
        }
        if ($cut === null) {
            // all points on one line, or none: cut through the middle of what's there
            $cut = $n > 0 ? self::chunkBorder(($points[0][$axis] + $points[$n - 1][$axis]) / 2 + 8) : self::chunkBorder(($box[$axis] + $box[$axis + 2]) / 2);
        }
        $cut = max($lo, min($hi, $cut));

        $left = $box;
        $right = $box;
        $left[$axis + 2] = $cut;
        $right[$axis] = $cut;

        $leftPoints = array_values(array_filter($points, fn ($p) => $p[$axis] < $cut));
        $rightPoints = array_values(array_filter($points, fn ($p) => $p[$axis] >= $cut));

        self::cut($left, $leftPoints, $leftCount, $out);
        self::cut($right, $rightPoints, $count - $leftCount, $out);
    }

    /** @param  list<array{0: int|float, 1: int|float, 2: float}>  $points */
    private static function spread(array $points, int $axis): float
    {
        if (count($points) < 2) {
            return 0;
        }
        $values = array_column($points, $axis);
        sort($values);
        $n = count($values);

        return $values[min($n - 1, (int) floor($n * 0.95))] - $values[(int) floor($n * 0.05)];
    }

    private static function chunkBorder(float $value): int
    {
        return (int) (round($value / 16) * 16);
    }
}
