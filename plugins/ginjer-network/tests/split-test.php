<?php

/**
 * Checks for the split planner's maths (no panel needed).
 *
 *   php tests/split-test.php <vendor/autoload.php with symfony/yaml> [world.json towns.json]
 */

require $argv[1] ?? 'vendor/autoload.php';
foreach (['Services/RegionList.php', 'Services/Split/WorldWeights.php', 'Services/Split/RegionSplitter.php', 'Services/Split/ResourcePlanner.php'] as $f) {
    require __DIR__ . '/../src/' . $f;
}

use Ginjer\Network\Services\RegionList;
use Ginjer\Network\Services\Split\RegionSplitter;
use Ginjer\Network\Services\Split\ResourcePlanner;
use Ginjer\Network\Services\Split\WorldWeights;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

/** Every probe point must be in exactly one region (Reef: min inclusive, max exclusive). */
function coverageProblems(array $regions): int
{
    $bad = 0;
    $probes = [-29999984, -1000000, -20001, -4096, -17, -16, -1, 0, 15, 16, 1000, 3936, 5360, 77777, 29999983];
    foreach ($probes as $x) {
        foreach ($probes as $z) {
            $owners = count(array_filter($regions, fn ($r) => $r['min-x'] <= $x && $x < $r['max-x'] && $r['min-z'] <= $z && $z < $r['max-z']));
            $bad += $owners === 1 ? 0 : 1;
        }
    }

    return $bad;
}

function yamlOf(array $regions, array $names): string
{
    return implode("\n", array_map(fn ($r, $n) => "- {server: $n, min-x: {$r['min-x']}, min-z: {$r['min-z']}, max-x: {$r['max-x']}, max-z: {$r['max-z']}}", $regions, $names));
}

// ---------------- synthetic: two equal towns far apart
$points = [];
for ($i = 0; $i < 100; $i++) {
    $points[] = [-5000 + $i, 0, 1.0];
    $points[] = [5000 + $i, 0, 1.0];
}
$two = RegionSplitter::split($points, 2);
check('two towns: cut between them', $two[0]['max-x'] > -4900 && $two[0]['max-x'] < 5000, json_encode($two));
check('two towns: 50/50', abs($two[0]['weight'] - 0.5) < 0.01);
check('two towns: full coverage', coverageProblems($two) === 0);

// heavy town on one side: cut moves toward it
$points = [[-5000, 0, 10.0], [-4990, 0, 10.0], [5000, 0, 1.0], [5010, 0, 1.0], [6000, 0, 1.0]];
$skew = RegionSplitter::split($points, 2);
check('weighted: big town gets its own shard', $skew[0]['max-x'] > -4990 && $skew[0]['max-x'] <= 5000 && $skew[0]['weight'] > 0.8, json_encode($skew));

// no data: even split around spawn
$empty = RegionSplitter::split([], 4);
check('no data: 4 regions, full coverage', count($empty) === 4 && coverageProblems($empty) === 0, json_encode($empty));
check('no data: balanced', max(array_column($empty, 'weight')) - min(array_column($empty, 'weight')) < 0.05);

// ---------------- real Nodes data
$world = $argv[2] ?? null;
$towns = $argv[3] ?? null;
if ($world) {
    $w = WorldWeights::fromNodesFiles(file_get_contents($world), $towns ? file_get_contents($towns) : null);
    check('real data loads', count($w['points']) > 0 && $w['territories'] > 0, json_encode(array_diff_key($w, ['points' => 1])));
    foreach (range(2, 8) as $n) {
        $regions = RegionSplitter::split($w['points'], $n);
        $weights = array_column($regions, 'weight');
        $names = array_map(fn ($i) => "s$i", range(1, $n));
        check("real data, $n shards: $n regions", count($regions) === $n);
        check("real data, $n shards: every spot has exactly one owner", coverageProblems($regions) === 0);
        check("real data, $n shards: balanced within 5%", max($weights) - min($weights) < 0.05, json_encode($weights));
        check("real data, $n shards: cuts on chunk borders", array_reduce($regions, fn ($ok, $r) => $ok && $r['min-x'] % 16 === 0 && $r['max-x'] % 16 === 0 && $r['min-z'] % 16 === 0 && $r['max-z'] % 16 === 0, true));
        check("real data, $n shards: Reef accepts the list", RegionList::problems(yamlOf($regions, $names), $names) === [], implode('; ', RegionList::problems(yamlOf($regions, $names), $names)));
    }
}

// ---------------- RAM rules
check('RAM: small shard gets the minimum', ResourcePlanner::memoryFor(5, false) === ResourcePlanner::MIN_MB);
check('RAM: 100 players', ResourcePlanner::memoryFor(100, false) === 7168, (string) ResourcePlanner::memoryFor(100, false));
check('RAM: leader gets more', ResourcePlanner::memoryFor(100, true) === 7168 + 1536);
check('RAM: capped', ResourcePlanner::memoryFor(10000, false) === ResourcePlanner::MAX_MB);
check('RAM: multiple of 512', ResourcePlanner::memoryFor(37, false) % 512 === 0);
check('shard count: 250 players -> 3', ResourcePlanner::shardCountFor(250) === 3);
check('shard count: at least 2, at most 8', ResourcePlanner::shardCountFor(10) === 2 && ResourcePlanner::shardCountFor(5000) === 8);

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
