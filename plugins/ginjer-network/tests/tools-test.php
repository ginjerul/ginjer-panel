<?php

/**
 * Checks for the pre-generation area and Chunky commands (no panel needed).
 *
 *   php tests/tools-test.php <vendor/autoload.php>
 */

require $argv[1] ?? 'vendor/autoload.php';

use Ginjer\Network\Services\Ops\ServerTools;

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Ginjer\\Network\\'))) . '.php';
    if (str_starts_with($class, 'Ginjer\\Network\\') && is_file($file)) {
        require $file;
    }
});

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

check('a plain server: a square around 0, 0', ServerTools::area(null, null, 5000) === ['min-x' => -5000, 'min-z' => -5000, 'max-x' => 5000, 'max-z' => 5000]);

$regions = [
    ['server' => 'west', 'min-x' => -29999984, 'min-z' => -29999984, 'max-x' => 2672, 'max-z' => 29999984],
    ['server' => 'east', 'min-x' => 2672, 'min-z' => -29999984, 'max-x' => 29999984, 'max-z' => 29999984],
    ['server' => 'island', 'points' => [[6000, 6000], [7000, 6000], [7000, 7500]]],
    ['server' => 'ring', 'center-x' => -3000, 'center-z' => 0, 'radius-x' => 500, 'radius-z' => 800],
];
check('a shard: only the part of the square it owns', ServerTools::area($regions, 'west', 5000) === ['min-x' => -5000, 'min-z' => -5000, 'max-x' => 2672, 'max-z' => 5000], json_encode(ServerTools::area($regions, 'west', 5000)));
check('the other shard gets the rest', ServerTools::area($regions, 'east', 5000) === ['min-x' => 2672, 'min-z' => -5000, 'max-x' => 5000, 'max-z' => 5000]);
check('a polygon area uses its bounding box', ServerTools::area($regions, 'island', 10000) === ['min-x' => 6000, 'min-z' => 6000, 'max-x' => 7000, 'max-z' => 7500]);
check('an ellipse area uses its bounding box', ServerTools::area($regions, 'ring', 10000) === ['min-x' => -3500, 'min-z' => -800, 'max-x' => -2500, 'max-z' => 800]);
check('a shard outside the radius has nothing to generate', ServerTools::area($regions, 'island', 5000) === null);
check('an unknown shard has nothing to generate', ServerTools::area($regions, 'north', 5000) === null);
$two = [['server' => 'a', 'min-x' => 0, 'min-z' => 0, 'max-x' => 100, 'max-z' => 100], ['server' => 'a', 'min-x' => 500, 'min-z' => -200, 'max-x' => 600, 'max-z' => 50]];
check('several areas of one shard: the box around all of them', ServerTools::area($two, 'a', 5000) === ['min-x' => 0, 'min-z' => -200, 'max-x' => 600, 'max-z' => 100]);

$cmds = ServerTools::chunkyCommands('world', ['min-x' => -5000, 'min-z' => -5000, 'max-x' => 2672, 'max-z' => 5000]);
check('Chunky: world, rectangle, centre, radii, start', $cmds === ['chunky world world', 'chunky shape rectangle', 'chunky center -1164 0', 'chunky radius 3836 5000', 'chunky start'], json_encode($cmds));
check('Chunky: a square around 0, 0', ServerTools::chunkyCommands('world_nether', ServerTools::area(null, null, 3000))[2] === 'chunky center 0 0');

// ---------------- daily restart timing
use Carbon\Carbon;
use Ginjer\Network\Services\Ops\RestartSchedule;

$at = fn (string $time) => Carbon::parse("2026-11-23 $time");
$plan = [];
foreach (['03:49', '03:50', '03:51', '03:55', '03:58', '03:59', '04:00', '04:01'] as $t) {
    $plan[$t] = RestartSchedule::due('04:00', $at($t), null);
}
check('restart: warnings at 10, 5 and 1 minutes, then the restart', $plan === [
    '03:49' => null, '03:50' => ['warn', 10], '03:51' => null, '03:55' => ['warn', 5], '03:58' => null,
    '03:59' => ['warn', 1], '04:00' => ['restart', 0], '04:01' => ['restart', 0]], json_encode($plan));
check('restart: only once a day', RestartSchedule::due('04:00', $at('04:01'), Carbon::parse('2026-11-23 04:00')) === null);
check('restart: again the next day', RestartSchedule::due('04:00', Carbon::parse('2026-11-24 04:00'), Carbon::parse('2026-11-23 04:00')) === ['restart', 0]);
check('restart: not hours later if the panel was down', RestartSchedule::due('04:00', $at('09:00'), null) === null);
check('restart: off, or a bad time, does nothing', RestartSchedule::due(null, $at('04:00'), null) === null && RestartSchedule::due('25:00', $at('04:00'), null) === null);
check('restart: just after midnight', RestartSchedule::due('00:05', Carbon::parse('2026-11-23 23:55'), null) === null && RestartSchedule::due('00:05', Carbon::parse('2026-11-24 00:00'), null) === ['warn', 5]);

// ---------------- lag hotspots
use Ginjer\Network\Services\Ops\Hotspots;

$world = json_encode(['territories' => ['7' => ['chunks' => [10, -3, 11, -3]], '8' => ['chunks' => [0, 0]]]]);
$towns = json_encode(['towns' => ['Ironforge' => ['territories' => [7], 'annexed' => []], 'Lonely' => ['territories' => [], 'annexed' => [8]]],
    'nations' => ['Dwarves' => ['towns' => ['Ironforge']]]]);
$owners = Hotspots::owners($world, $towns);
check('hotspots: chunks map to their town and nation', ($owners['10,-3'] ?? null) === ['Ironforge', 'Dwarves'] && ($owners['11,-3'] ?? null) === ['Ironforge', 'Dwarves']);
check('hotspots: annexed land counts, a town without a nation has none', ($owners['0,0'] ?? null) === ['Lonely', null]);
check('hotspots: no Nodes data, no owners', Hotspots::owners(null, $towns) === []);

$report = json_encode(['generated' => 1764000000000, 'totals' => ['minecraft:item' => 50, 'minecraft:zombie' => 300], 'chunks' => [
    ['world' => 'world', 'x' => 3, 'z' => 3, 'entities' => 20, 'types' => ['minecraft:item' => 20], 'block_entities' => 5, 'block_types' => ['minecraft:chest' => 5]],
    ['world' => 'world', 'x' => 10, 'z' => -3, 'entities' => 300, 'types' => ['minecraft:item' => 30, 'minecraft:zombie' => 270], 'block_entities' => 150, 'block_types' => ['minecraft:hopper' => 150]],
]]);
$parsed = Hotspots::parse($report, $owners);
check('hotspots: worst chunk first (entities + block entities)', $parsed['chunks'][0]['x'] === 10 && $parsed['chunks'][0]['load'] === 450);
check('hotspots: block coordinates at the chunk centre, with the owner', $parsed['chunks'][0]['block_x'] === 168 && $parsed['chunks'][0]['block_z'] === -40 && $parsed['chunks'][0]['town'] === 'Ironforge');
check('hotspots: types sorted, biggest first', array_key_first($parsed['chunks'][0]['types']) === 'minecraft:zombie' && array_key_first($parsed['totals']) === 'minecraft:zombie');
check('hotspots: wilderness has no town', $parsed['chunks'][1]['town'] === null);
check('hotspots: short summary', Hotspots::summary(['minecraft:zombie' => 270, 'minecraft:item' => 30, 'minecraft:arrow' => 3, 'minecraft:cow' => 1]) === 'zombie ×270, item ×30, arrow ×3, +1 more');
try {
    Hotspots::parse('not json');
    check('hotspots: a broken report is an error, not an empty list', false);
} catch (InvalidArgumentException) {
    check('hotspots: a broken report is an error, not an empty list', true);
}

// ---------------- pre-generation progress
$area = ['min-x' => -1024, 'min-z' => -1024, 'max-x' => 1024, 'max-z' => 1024]; // regions -2..1 each way: 16
$p = Hotspots::pregenProgress(['r.-2.-2.mca', 'r.-1.-1.mca', 'r.0.0.mca', 'r.1.1.mca', 'r.5.5.mca', 'level.dat', 'r.0.0.mca.tmp'], $area);
check('pregen: counts only region files inside the area', $p === ['have' => 4, 'need' => 16, 'percent' => 25], json_encode($p));
$q = Hotspots::pregenProgress([], ['min-x' => 0, 'min-z' => 0, 'max-x' => 100, 'max-z' => 100]);
check('pregen: a small area is one region file', $q['need'] === 1 && $q['percent'] === 0);
$edge = Hotspots::pregenProgress(['r.-1.0.mca', 'r.0.0.mca'], ['min-x' => -512, 'min-z' => 0, 'max-x' => 512, 'max-z' => 512]);
check('pregen: region borders at multiples of 512 (negatives included)', $edge === ['have' => 2, 'need' => 2, 'percent' => 100], json_encode($edge));

// ---------------- rolling restart order
use Ginjer\Network\Services\Ops\RollingPlan;

$shards = [['id' => 1, 'name' => 'europe', 'leader' => true], ['id' => 2, 'name' => 'americas', 'leader' => false],
    ['id' => 3, 'name' => 'asia', 'leader' => false], ['id' => 4, 'name' => 'oceania', 'leader' => false], ['id' => 5, 'name' => 'africa', 'leader' => false]];
check('rolling: default is followers one at a time, leader last', RollingPlan::batches($shards, 'followers_first', 1) === [[5], [2], [3], [4], [1]], json_encode(RollingPlan::batches($shards, 'followers_first', 1)));
check('rolling: batches of 2, leader still alone', RollingPlan::batches($shards, 'followers_first', 2) === [[5, 2], [3, 4], [1]]);
check('rolling: leader first', RollingPlan::batches($shards, 'leader_first', 3) === [[1], [5, 2, 3], [4]]);
check('rolling: by name, the leader splits a batch', RollingPlan::batches($shards, 'by_name', 3) === [[5, 2, 3], [1], [4]], json_encode(RollingPlan::batches($shards, 'by_name', 3)));
check('rolling: the proxy goes last, alone', RollingPlan::batches($shards, 'followers_first', 10, 99) === [[5, 2, 3, 4], [1], [99]]);
check('rolling: an unknown order falls back to followers first', RollingPlan::batches($shards, 'nonsense', 1)[4] === [1]);
check('rolling: batch size 0 is treated as 1', count(RollingPlan::batches($shards, 'followers_first', 0)) === 5);
check('rolling: a network without a leader still works', RollingPlan::batches([['id' => 7, 'name' => 'a', 'leader' => false]], 'followers_first', 1) === [[7]]);

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
