<?php

/**
 * Checks for the network tools that don't need a panel: crash analysis, the merged console,
 * who is online, player names, load sampling, shard ownership, region files, the rebalancer,
 * plugin versions and Discord.
 *
 *   php tests/network-tools-test.php <vendor/autoload.php>
 */

require $argv[1] ?? 'vendor/autoload.php';

use Ginjer\Network\Services\Health\CrashAnalyzer;
use Ginjer\Network\Services\NodesStatusService;
use Ginjer\Network\Services\Ops\NetworkConsole;
use Ginjer\Network\Services\Ops\PluginUpdates;
use Ginjer\Network\Services\Players\PlayerAdmin;
use Ginjer\Network\Services\Rebalance\Anvil;
use Ginjer\Network\Services\Rebalance\LoadSampler;
use Ginjer\Network\Services\Rebalance\Rebalancer;
use Ginjer\Network\Services\Rebalance\ShardMap;
use Ginjer\Network\Services\Ops\ScheduleRules;
use Illuminate\Support\Carbon;

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

$plugins = ['EssentialsX', 'Nodes', 'WorldGuard', 'spark', 'LuckPerms'];

// ---------------- crash analysis
$r = CrashAnalyzer::analyze("[10:00:00 INFO]: Done\n[10:05:00 ERROR]: Exception in server tick loop\njava.lang.OutOfMemoryError: Java heap space\n", null, $plugins);
check('crash: out of memory', $r['cause'] === 'it ran out of memory' && str_contains($r['detail'], 'Java heap space'), json_encode($r));

$watchdog = <<<'LOG'
[12:00:00 ERROR]: ------------------------------
[12:00:00 ERROR]: The server has stopped responding! This is (probably) not a Paper bug.
[12:00:00 ERROR]: ------------------------------
[12:00:00 ERROR]: Server thread dump (Look for plugins here before reporting to Paper!):
[12:00:00 ERROR]: Current Thread: Server thread
[12:00:00 ERROR]: 	PID: 30 | Suspended: false | Native: false | State: RUNNABLE
[12:00:00 ERROR]: 	Stack:
[12:00:00 ERROR]: 		java.base@21/java.util.HashMap.getNode(HashMap.java:568)
[12:00:00 ERROR]: 		at java.base/java.util.HashMap.get(HashMap.java:556)
[12:00:00 ERROR]: 		at com.sk89q.worldguard.protection.regions.RegionQuery.queryState(RegionQuery.java:300)
[12:00:00 ERROR]: 		at org.bukkit.plugin.SimplePluginManager.callEvent(SimplePluginManager.java:600)
[12:00:00 ERROR]: 		at net.minecraft.server.MinecraftServer.tickServer(MinecraftServer.java:1500)
LOG;
$r = CrashAnalyzer::analyze($watchdog, null, $plugins);
check('crash: a frozen main thread names the plugin in the dump', $r['cause'] === 'its main thread froze' && $r['plugin'] === 'WorldGuard', json_encode($r));

$event = "[13:00:00 ERROR]: Could not pass event PlayerMoveEvent to EssentialsX v2.20.1\njava.lang.NullPointerException: Cannot invoke \"String.length()\"\n\tat com.earth2me.essentials.Foo.bar(Foo.java:10)\n";
$r = CrashAnalyzer::analyze($event, null, $plugins);
check('crash: a plugin named in an error', $r['plugin'] === 'EssentialsX' && str_contains($r['cause'], 'PlayerMoveEvent') && str_contains((string) $r['detail'], 'NullPointerException'), json_encode($r));

$report = "---- Minecraft Crash Report ----\nDescription: Ticking entity\n\njava.lang.IllegalStateException: bad state\n\tat net.minecraft.world.entity.Entity.tick(Entity.java:1)\n\tat phonon.nodes.war.FlagWar.tick(FlagWar.kt:42)\n";
$r = CrashAnalyzer::analyze("[14:00:00 INFO]: stopping\n", $report, $plugins);
check('crash: a crash report, matched to a plugin by package', str_starts_with($r['cause'], 'Minecraft crashed: ticking entity') && $r['plugin'] === 'Nodes', json_encode($r));

$r = CrashAnalyzer::analyze("[15:00:00 INFO]: Saving chunks\n", null, $plugins);
check('crash: nothing found is said plainly', $r['cause'] === 'nothing in its log says why' && $r['plugin'] === null);
check('crash: frames are read with module prefixes', CrashAnalyzer::frames(['	at app//com.example.Foo.bar(Foo.java:1)']) === ['com.example.Foo.bar']);
check('crash: an unknown package is not guessed', CrashAnalyzer::pluginFor(['com.unknown.thing.Main.run'], $plugins) === null);

// ---------------- the merged console
$lines = NetworkConsole::merge([
    'west' => "\e[32m[10:00:01 INFO]: west one\e[0m\n[10:00:03 ERROR]: boom\njava.lang.Exception: x\n\tat a.b.C(C.java:1)\n",
    'east' => "[10:00:02 INFO]: east one\n[10:00:03 INFO]: east two\n",
], 100);
check('console: merged by time', array_column($lines, 'text') === ['[10:00:01 INFO]: west one', '[10:00:02 INFO]: east one', '[10:00:03 ERROR]: boom', 'java.lang.Exception: x', "\tat a.b.C(C.java:1)", '[10:00:03 INFO]: east two'], json_encode(array_column($lines, 'text')));
check('console: stack traces stay under their line, labelled', $lines[3]['server'] === 'west' && $lines[4]['server'] === 'west');
check('console: colour codes removed', !str_contains(json_encode($lines), '\u001b'));
check('console: only the newest lines', count(NetworkConsole::merge(['a' => implode("\n", array_map(fn ($i) => sprintf('[10:00:%02d INFO]: %d', $i, $i), range(0, 59)))], 10)) === 10);

// ---------------- who is online
$now = 1_700_000_000_000;
$players = NodesStatusService::parsePresence([
    'west' => json_encode(['presence' => ['u1' => 'Steve', 'u2' => 'alex'], 'staff' => ['u1' => 1], 'ping' => ['u1' => 42], 'where' => ['u1' => 'world 10 64 -20'], 'time' => $now - 5000]),
    'east' => json_encode(['presence' => ['u2' => 'alex'], 'time' => $now - 1000]),
    'gone' => json_encode(['presence' => ['u3' => 'Old'], 'time' => $now - 600_000]),
], $now);
check('players: from every shard that reports, sorted by name', array_column($players, 'name') === ['alex', 'Steve'], json_encode($players));
check('players: someone moving shards shows once, on the newest', $players[0]['shard'] === 'east');
check('players: ping, position and staff', $players[1]['ping'] === 42 && $players[1]['where'] === 'world 10 64 -20' && $players[1]['staff'] && $players[0]['ping'] === null);
check('players: names are checked before commands', PlayerAdmin::validName('Steve_99') && !PlayerAdmin::validName('a b') && !PlayerAdmin::validName('x;stop') && !PlayerAdmin::validName(str_repeat('a', 17)));
check('players: reasons are one safe line', PlayerAdmin::clean("bye\nop me §c") === 'bye op me  c' || PlayerAdmin::clean("bye\nop me §c") === 'bye op me c', PlayerAdmin::clean("bye\nop me §c"));

// ---------------- load sampling
$cells = LoadSampler::cells([
    ['shard' => 'west', 'where' => 'world 10 64 -20'],
    ['shard' => 'west', 'where' => 'world 127 64 -128'],
    ['shard' => 'east', 'where' => 'world -1 64 -129'],
    ['shard' => 'east', 'where' => 'world_nether 0 64 0'],
    ['shard' => 'east', 'where' => null],
], 'world', ['west' => 50.0, 'east' => null]);
check('load: 128-block squares, rounding down for negatives', $cells === ['0:-1' => 4.0, '-1:-2' => 1.0], json_encode($cells));

// ---------------- shard ownership (same rules as Reef)
$map = new ShardMap([
    ['server' => 'circle', 'center-x' => 0, 'center-z' => 0, 'radius' => 100],
    ['server' => 'west', 'min-x' => -1000, 'min-z' => -1000, 'max-x' => 0, 'max-z' => 1000],
    ['server' => 'east', 'min-x' => 0, 'min-z' => -1000, 'max-x' => 1000, 'max-z' => 1000],
    ['server' => 'tri', 'points' => [[2000, 0], [3000, 0], [2000, 1000]]],
]);
check('owner: the first region that contains it wins', $map->ownerAt(-5, 5) === 'circle' && $map->ownerAt(-500, 5) === 'west');
check('owner: max edges belong to the next region', $map->ownerAt(-0.0001, 500) === 'west' && $map->ownerAt(0, 500) === 'east' && $map->ownerAt(1000, 500) === null);
check('owner: polygons', $map->ownerAt(2100, 100) === 'tri' && $map->ownerAt(2900, 900) === null);
check('owner: a chunk belongs to the owner of its centre', $map->chunkOwner(-1, 30) === 'west' && $map->chunkOwner(0, 30) === 'east' && $map->chunkOwner(0, 0) === 'circle');

// ---------------- region files
$payload = fn (string $data, int $type = 2) => pack('N', strlen($data) + 1) . chr($type) . $data;
$file = Anvil::write([0 => ['payload' => $payload('chunk zero'), 'time' => 111], 33 => ['payload' => $payload(str_repeat('x', 5000)), 'time' => 222], 1023 => ['payload' => $payload('last', 130), 'time' => 333]]);
$read = Anvil::read($file);
check('region file: written and read back', array_keys($read) === [0, 33, 1023] && $read[33]['payload'] === $payload(str_repeat('x', 5000)) && $read[0]['time'] === 111);
check('region file: sector aligned with an 8 KiB header', strlen($file) % 4096 === 0 && strlen($file) === 8192 + 4096 * 4);
check('region file: external chunks are spotted', $read[1023]['external'] && !$read[0]['external']);
check('region file: an empty or broken file has no chunks', Anvil::read('') === [] && Anvil::read(str_repeat("\xff", 8192)) === []);
check('region file: chunk index and file name', Anvil::index(-1, -1) === 1023 && Anvil::index(33, 1) === 33 && Anvil::regionOf('r.-1.2.mca') === [-1, 2]);

// ---------------- rebalancer
$points = [];
for ($i = 0; $i < 400; $i++) {
    $points[] = [-3000 + ($i % 20) * 100, ($i % 7) * 100, 5.0];   // busy west
}
for ($i = 0; $i < 40; $i++) {
    $points[] = [500 + ($i % 10) * 300, ($i % 5) * 200, 5.0];      // quiet east
}
$current = [['server' => 'west', 'min-x' => -29999984, 'min-z' => -29999984, 'max-x' => 0, 'max-z' => 29999984],
    ['server' => 'east', 'min-x' => 0, 'min-z' => -29999984, 'max-x' => 29999984, 'max-z' => 29999984]];
$plan = Rebalancer::propose($points, ['west', 'east'], $current);
check('rebalance: a lopsided network is worth fixing', $plan['enough'] && $plan['worth'] && $plan['now'] > 1.8 && $plan['after'] < 1.2, json_encode(array_diff_key($plan, ['points' => 1, 'regions' => 1, 'yaml' => 1])));
check('rebalance: shares add up', abs(array_sum(array_column($plan['shards'], 'after')) - 1) < 0.01 && abs(array_sum(array_column($plan['shards'], 'now')) - 1) < 0.01);
check('rebalance: every shard keeps an area, under its own name', collect_names($plan['regions']) === ['east', 'west']);
$westNow = new ShardMap($plan['regions']);
check('rebalance: west keeps the far west', $westNow->ownerAt(-20000, 0) === 'west' && $westNow->ownerAt(20000, 0) === 'east');
check('rebalance: the new borders are valid regions', $plan['yaml'] !== '' && str_contains($plan['yaml'], '- { server: '));
$even = Rebalancer::propose(array_map(fn ($p) => [$p[0], $p[1], 20.0], array_merge(array_slice($points, 0, 40), array_slice($points, 400))), ['west', 'east'], $current);
check('rebalance: an even network isn\'t worth it', $even['enough'] && !$even['worth'], json_encode([$even['now'], $even['after']]));
// three shards west to east, nearly all players in the west one: the new areas keep that order
$three = [['server' => 'a', 'min-x' => -29999984, 'min-z' => -29999984, 'max-x' => 2672, 'max-z' => 29999984],
    ['server' => 'b', 'min-x' => 2672, 'min-z' => -29999984, 'max-x' => 5360, 'max-z' => 29999984],
    ['server' => 'c', 'min-x' => 5360, 'min-z' => -29999984, 'max-x' => 29999984, 'max-z' => 29999984]];
$hot = [];
for ($i = 0; $i < 300; $i++) {
    $hot[] = [-400 + ($i % 30) * 60, ($i % 10) * 80, 10.0];
}
$hot[] = [3000, 0, 20.0];
$hot[] = [6000, 0, 10.0];
$plan3 = Rebalancer::propose($hot, ['a', 'b', 'c'], $three);
$order = array_column(array_map(fn ($r) => ['s' => $r['server'], 'x' => $r['min-x']], $plan3['regions']), 's', 'x');
ksort($order);
check('rebalance: areas keep their west-to-east order, so little land moves', array_values($order) === ['a', 'b', 'c'], json_encode($plan3['regions']));
check('rebalance: assignment picks the best total', Rebalancer::assign([0 => ['a' => 0.9, 'b' => 0.8], 1 => ['a' => 0.85, 'b' => 0.1]], ['a', 'b']) === [0 => 'b', 1 => 'a']);
check('rebalance: too little data says so', !Rebalancer::propose([[0, 0, 5.0]], ['west', 'east'], $current)['enough']);

function collect_names(array $regions): array
{
    $names = array_column($regions, 'server');
    sort($names);

    return $names;
}

// ---------------- plugin versions
check('versions: newer by numbers', PluginUpdates::newer('2.20.1', '2.21.0') === true && PluginUpdates::newer('v1.10', '1.9') === false && PluginUpdates::newer('1.0', '1.0') === false);
check('versions: unknown when there are no numbers', PluginUpdates::newer('alpha', 'beta') === null && PluginUpdates::newer('same', 'same') === false);
$tmp = tempnam(sys_get_temp_dir(), 'jar');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
$zip->addFromString('plugin.yml', "name: 'CoolPlugin'\nversion: \"1.4.2\" # comment\nmain: a.b.C\n");
$zip->close();
check('versions: name and version from plugin.yml', PluginUpdates::readJar((string) file_get_contents($tmp)) === ['name' => 'CoolPlugin', 'version' => '1.4.2']);
@unlink($tmp);
check('versions: not a jar', PluginUpdates::readJar('nope') === ['name' => '', 'version' => '']);

// ---------------- schedules

$sat = Carbon::parse('2026-11-28 18:00:20'); // a Saturday
check('schedule: at its time', ScheduleRules::due('18:00', null, [], null, $sat) && !ScheduleRules::due('18:01', null, [], null, $sat));
check('schedule: once per minute', !ScheduleRules::due('18:00', null, [], Carbon::parse('2026-11-28 18:00:02'), $sat));
check('schedule: only on its days', ScheduleRules::due('18:00', null, [6, 7], null, $sat) && !ScheduleRules::due('18:00', null, [1, 2], null, $sat));
check('schedule: every N minutes', ScheduleRules::due(null, 30, [], null, $sat) && ScheduleRules::due(null, 30, [], Carbon::parse('2026-11-28 17:30:59'), $sat)
    && !ScheduleRules::due(null, 30, [], Carbon::parse('2026-11-28 17:31:00'), $sat));
check('schedule: a bad time never runs', !ScheduleRules::due('25:00', null, [], null, $sat) && !ScheduleRules::due(null, null, [], null, $sat));
check('schedule: described in words', ScheduleRules::describe('18:00', null, [6]) === 'At 18:00 on Sat' && ScheduleRules::describe(null, 15, []) === 'Every 15 minutes' && ScheduleRules::describe('09:30', null, []) === 'At 09:30 every day');

// ---------------- the public status page
$page = (new ReflectionClass(\Ginjer\Network\Services\Status\StatusPage::class))->newInstanceWithoutConstructor();
$html = $page->html(['name' => '<Ginjer>', 'maintenance' => false, 'reachable' => true, 'players' => 7,
    'shards' => [['name' => 'west', 'up' => true, 'players' => 5], ['name' => 'east', 'up' => false, 'players' => 0]],
    'uptime' => ['day' => 99.5, 'week' => null], 'incidents' => [['shard' => 'east', 'from' => '2026-11-26T10:00:00+00:00', 'to' => '2026-11-26T10:12:00+00:00']], 'updated' => '2026-11-26T12:00:00+00:00']);
check('status page: names are escaped', str_contains($html, '&lt;Ginjer&gt;') && !str_contains($html, '<Ginjer>'));
check('status page: says some shards are down, with players and uptime', str_contains($html, 'Some shards are down') && str_contains($html, '5 online') && str_contains($html, '99.5%') && str_contains($html, '–'));
check('status page: an outage with how long it lasted', str_contains($html, 'east was down') && str_contains($html, '12 minutes'));
check('status page: works on a phone and in dark mode', str_contains($html, 'width=device-width') && str_contains($html, 'prefers-color-scheme:dark'));

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
