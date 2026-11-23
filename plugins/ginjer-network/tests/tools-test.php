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

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
