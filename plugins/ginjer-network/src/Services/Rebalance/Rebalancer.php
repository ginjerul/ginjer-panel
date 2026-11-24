<?php

namespace Ginjer\Network\Services\Rebalance;

use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\RegionList;
use Ginjer\Network\Services\Split\RegionSplitter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Proposes new shard borders from where players actually spend their time (the last week), so
 * every shard carries about the same load. Each new area goes to the shard that already owns most
 * of its load, so as little as possible changes hands.
 */
class Rebalancer
{
    /** Below this much data a proposal would follow noise. */
    public const MIN_PLAYER_MINUTES = 600;

    /** A shard this much over its fair share is worth rebalancing. */
    public const WORTH_IT = 1.25;

    /**
     * @return array{enough: bool, minutes: float, days: int, shards: array<string, array{now: float, after: float}>, now: float, after: float,
     *               worth: bool, moved: float, regions: list<array<string, mixed>>, yaml: string, points: list<array{0: int, 1: int, 2: float}>}
     */
    public function plan(Network $network, int $days = 7): array
    {
        $points = LoadSampler::points($network, $days);
        $names = $network->loadMissing('members.server')->membersWithRole(MemberRole::Shard)->pluck('shard_name')->filter()->values()->all();
        $current = RegionList::parse((string) $network->regions);
        $daysWithData = (int) DB::table('ginjer_load_cells')->where('network_id', $network->id)
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())->distinct()->count('day');

        return self::propose($points, $names, $current) + ['days' => $daysWithData];
    }

    /**
     * @param  list<array{0: int|float, 1: int|float, 2: float}>  $points
     * @param  list<string>  $names
     * @param  list<array<string, mixed>>  $current
     */
    public static function propose(array $points, array $names, array $current): array
    {
        $total = array_sum(array_column($points, 2));
        $out = ['enough' => $total >= self::MIN_PLAYER_MINUTES && count($names) >= 2, 'minutes' => round($total, 1), 'shards' => [], 'now' => 0.0, 'after' => 0.0,
            'worth' => false, 'moved' => 0.0, 'regions' => [], 'yaml' => '', 'points' => $points];
        if (!$out['enough']) {
            return $out;
        }
        $fair = 1 / count($names);
        $now = new ShardMap($current);
        $shareNow = array_fill_keys($names, 0.0);
        foreach ($points as [$x, $z, $w]) {
            $owner = $now->ownerAt($x, $z);
            if (isset($shareNow[$owner])) {
                $shareNow[$owner] += $w / $total;
            }
        }

        // split, then give each new area to the shard that owns most of it now: its load first,
        // then its land (near where players are), so as little land as possible changes shard
        $rects = RegionSplitter::split($points, count($names));
        $score = [];
        foreach (array_keys($rects) as $r) {
            $score[$r] = array_fill_keys($names, 0.0);
        }
        $inside = fn (array $rect, float $x, float $z) => $x >= $rect['min-x'] && $x < $rect['max-x'] && $z >= $rect['min-z'] && $z < $rect['max-z'];
        foreach ($points as [$x, $z, $w]) {
            $owner = $now->ownerAt($x, $z);
            foreach ($rects as $r => $rect) {
                if (isset($score[$r][$owner]) && $inside($rect, $x, $z)) {
                    $score[$r][$owner] += $w / $total;
                }
            }
        }
        $xs = array_column($points, 0);
        $zs = array_column($points, 1);
        [$x0, $x1, $z0, $z1] = [min($xs) - 2048, max($xs) + 2048, min($zs) - 2048, max($zs) + 2048];
        $grid = 48;
        for ($i = 0; $i < $grid; $i++) {
            for ($j = 0; $j < $grid; $j++) {
                $x = $x0 + ($i + 0.5) * ($x1 - $x0) / $grid;
                $z = $z0 + ($j + 0.5) * ($z1 - $z0) / $grid;
                $owner = $now->ownerAt($x, $z);
                foreach ($rects as $r => $rect) {
                    if (isset($score[$r][$owner]) && $inside($rect, $x, $z)) {
                        $score[$r][$owner] += 0.5 / ($grid * $grid);
                    }
                }
            }
        }
        // and keep neighbours neighbours: a small penalty for how far an area is from the shard's land now
        $centre = function (callable $owns) use ($x0, $x1, $z0, $z1, $grid) {
            [$sx, $sz, $n] = [0.0, 0.0, 0];
            for ($i = 0; $i < $grid; $i++) {
                for ($j = 0; $j < $grid; $j++) {
                    $x = $x0 + ($i + 0.5) * ($x1 - $x0) / $grid;
                    $z = $z0 + ($j + 0.5) * ($z1 - $z0) / $grid;
                    if ($owns($x, $z)) {
                        [$sx, $sz, $n] = [$sx + $x, $sz + $z, $n + 1];
                    }
                }
            }

            return $n === 0 ? null : [$sx / $n, $sz / $n];
        };
        $span = max($x1 - $x0, $z1 - $z0);
        $shardCentres = [];
        foreach ($names as $name) {
            $shardCentres[$name] = $centre(fn ($x, $z) => $now->ownerAt($x, $z) === $name);
        }
        foreach ($rects as $r => $rect) {
            $rc = $centre(fn ($x, $z) => $inside($rect, $x, $z));
            foreach ($names as $name) {
                if ($rc !== null && $shardCentres[$name] !== null) {
                    $score[$r][$name] -= 0.2 * hypot($rc[0] - $shardCentres[$name][0], $rc[1] - $shardCentres[$name][1]) / $span;
                }
            }
        }
        $assigned = self::assign($score, $names);
        $regions = [];
        $shareAfter = array_fill_keys($names, 0.0);
        foreach ($rects as $r => $rect) {
            $regions[] = ['server' => $assigned[$r], 'min-x' => $rect['min-x'], 'min-z' => $rect['min-z'], 'max-x' => $rect['max-x'], 'max-z' => $rect['max-z']];
            $shareAfter[$assigned[$r]] += $rect['weight'];
        }
        $after = new ShardMap($regions);
        $moved = 0.0;
        foreach ($points as [$x, $z, $w]) {
            if ($now->ownerAt($x, $z) !== $after->ownerAt($x, $z)) {
                $moved += $w / $total;
            }
        }

        foreach ($names as $name) {
            $out['shards'][$name] = ['now' => round($shareNow[$name], 4), 'after' => round($shareAfter[$name], 4)];
        }
        $out['now'] = round(max($shareNow) / $fair, 2);
        $out['after'] = round(max($shareAfter) / $fair, 2);
        $out['worth'] = $out['now'] >= self::WORTH_IT && $out['after'] < $out['now'] - 0.1;
        $out['moved'] = round($moved, 4);
        $out['regions'] = $regions;
        $out['yaml'] = implode("\n", array_map(fn ($r) => '- ' . Yaml::dump($r, 0), $regions));

        return $out;
    }

    /**
     * The shard for each new area with the highest total score: every order is tried for up to 8
     * shards, beyond that the best pairs are taken first.
     *
     * @param  array<int, array<string, float>>  $score
     * @param  list<string>  $names
     * @return array<int, string>
     */
    public static function assign(array $score, array $names): array
    {
        $rects = array_keys($score);
        if (count($names) <= 8) {
            $best = null;
            $bestTotal = -1.0;
            $permute = function (array $left, array $chosen) use (&$permute, &$best, &$bestTotal, $rects, $score) {
                if ($left === []) {
                    $total = 0.0;
                    foreach ($chosen as $i => $name) {
                        $total += $score[$rects[$i]][$name];
                    }
                    if ($total > $bestTotal + 1e-12) {
                        $bestTotal = $total;
                        $best = $chosen;
                    }

                    return;
                }
                foreach ($left as $k => $name) {
                    $rest = $left;
                    unset($rest[$k]);
                    $permute($rest, [...$chosen, $name]);
                }
            };
            $permute($names, []);

            return array_combine($rects, $best);
        }
        $pairs = [];
        foreach ($score as $r => $row) {
            foreach ($row as $name => $value) {
                $pairs[] = [$value, $r, $name];
            }
        }
        usort($pairs, fn ($a, $b) => $b[0] <=> $a[0]);
        $assigned = [];
        $used = [];
        foreach ($pairs as [, $r, $name]) {
            if (!isset($assigned[$r]) && !isset($used[$name])) {
                $assigned[$r] = $name;
                $used[$name] = true;
            }
        }

        return $assigned;
    }
}
