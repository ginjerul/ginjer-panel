<?php

/**
 * Checks for the server optimizer's logic (no panel needed), on the config files in fixtures/.
 *
 *   php tests/optimizer-test.php <vendor/autoload.php with symfony/yaml and illuminate/collections> [nodes config.yml]
 */

require $argv[1] ?? 'vendor/autoload.php';
foreach (['Services/YamlScalarEditor.php', 'Services/Optimizer/PropertiesEditor.php', 'Services/Optimizer/Rule.php', 'Services/Optimizer/Context.php',
    'Services/Optimizer/JvmFlags.php', 'Services/Optimizer/Rules.php', 'Services/Optimizer/Optimizer.php'] as $f) {
    require __DIR__ . '/../src/' . $f;
}
if (!function_exists('collect')) {
    fwrite(STDERR, "The autoloader needs illuminate/collections too (a panel's vendor/autoload.php has it).\n");
    exit(2);
}

use Ginjer\Network\Services\Optimizer\Context;
use Ginjer\Network\Services\Optimizer\JvmFlags;
use Ginjer\Network\Services\Optimizer\Optimizer;
use Ginjer\Network\Services\Optimizer\Rule;
use Ginjer\Network\Services\Optimizer\Rules;
use Symfony\Component\Yaml\Yaml;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

$fx = __DIR__ . '/fixtures/';
$nodesConfig = isset($argv[2]) ? file_get_contents($argv[2]) : "flagBeaconEnabled: false\nflagBeaconUpdateLighting: false\nnametagUpdatePeriod: 80\nsharding:\n  publishIntervalMs: 250\n";
$files = [
    Rules::PROPERTIES => file_get_contents($fx . 'server.properties'),
    Rules::BUKKIT => file_get_contents($fx . 'bukkit.yml'),
    Rules::SPIGOT => file_get_contents($fx . 'spigot.yml'),
    Rules::PAPER => file_get_contents($fx . 'paper-world-defaults.yml'),
    Rules::REEF => file_get_contents($fx . 'reef.yml'),
    Rules::NODES => $nodesConfig,
];
$startup = 'java -Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true -jar {{SERVER_JARFILE}}';
$get = function (array $rows, string $id) {
    foreach ($rows as $r) {
        if ($r['id'] === $id) {
            return $r;
        }
    }

    return null;
};
$dig = function (array $data, string $key) {
    foreach (explode('.', $key) as $p) {
        $data = $data[$p] ?? null;
    }

    return $data;
};

// ---------------- rule list
$ids = array_map(fn ($r) => $r->id, Rules::all());
check('rule ids are unique', count($ids) === count(array_unique($ids)));
check('every rule has a reason and a value', array_reduce(Rules::all(), fn ($ok, $r) => $ok && strlen($r->reason) > 10 && $r->values !== [], true));

// ---------------- a fresh Reef + Nodes server, not sharded
$ctx = new Context(memoryMb: 8192, hasNodes: true, hasReef: true, behindProxy: false);
$rows = Optimizer::analyze($files, $startup, $ctx, Rule::BALANCED);
$missing = array_values(array_filter($rows, fn ($r) => $r['status'] === Optimizer::MISSING));
check('every setting is found in the files (names match Paper, Spigot, Bukkit, Reef, Nodes)', $missing === [], json_encode(array_column($missing, 'key')));
check('defaults show as changes', $get($rows, 'view-distance')['status'] === Optimizer::CHANGE && $get($rows, 'view-distance')['current'] === '10' && $get($rows, 'view-distance')['recommended'] === '8');
check('Reef defaults already fine', $get($rows, 'reef-dab')['status'] === Optimizer::OK && $get($rows, 'reef-async-saving')['status'] === Optimizer::OK);
check('Nodes: hopper move event is never disabled', $get($rows, 'hopper-move-event')['status'] === Optimizer::SKIP && str_contains($get($rows, 'hopper-move-event')['note'], 'protected town chests'));
check('not behind a proxy: compression left alone', $get($rows, 'network-compression')['status'] === Optimizer::SKIP);
check('not a leader: shard sync batching left alone', $get($rows, 'nodes-publish') === null || $get($rows, 'nodes-publish')['status'] === Optimizer::SKIP);
check('balanced leaves performance-only settings out', $get($rows, 'nerf-spawner-mobs') === null && $get($rows, 'spawn-monsters') === null);
check('JVM flags: current flags read from the startup command', $get($rows, 'jvm-flags')['current'] === '-Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false -Dterminal.ansi=true');

// ---------------- apply all balanced changes
$changes = array_column(array_filter($rows, fn ($r) => $r['status'] === Optimizer::CHANGE), 'id');
$result = Optimizer::apply($files, $startup, $ctx, Rule::BALANCED, $changes);
check('every chosen change was applied', count($result['applied']) === count($changes), json_encode(array_diff($changes, $result['applied'])));
$after = $files;
foreach ($result['files'] as $path => $content) {
    $after[$path] = $content;
}
foreach ([Rules::BUKKIT, Rules::SPIGOT, Rules::PAPER, Rules::REEF, Rules::NODES] as $path) {
    try {
        Yaml::parse($after[$path]);
        $okYaml = true;
    } catch (Throwable $e) {
        $okYaml = false;
    }
    check("$path is still valid YAML", $okYaml);
}
$paper = Yaml::parse($after[Rules::PAPER]);
check('Paper: redstone set', $paper['misc']['redstone-implementation'] === 'ALTERNATE_CURRENT');
check('Paper: save limits set', $paper['chunks']['entity-per-chunk-save-limit']['experience_orb'] === 16 && $paper['chunks']['entity-per-chunk-save-limit']['snowball'] === 8);
check('Paper: hopper move event still on', $paper['hopper']['disable-move-event'] === false);
check('Paper: untouched settings unchanged', $paper['tick-rates']['container-update'] === 1 && $paper['chunks']['delay-chunk-unloads-by'] === '10s');
$spigot = Yaml::parse($after[Rules::SPIGOT]);
check('Spigot: activation and merge set', $spigot['world-settings']['default']['entity-activation-range']['animals'] === 24
    && $spigot['world-settings']['default']['merge-radius']['item'] === 2.5 && $spigot['world-settings']['default']['merge-radius']['exp'] === 3.0);
check('Spigot: player tracking untouched (PvP)', $spigot['world-settings']['default']['entity-tracking-range']['players'] === 128);
check('server.properties: distances set, rest unchanged', str_contains($after[Rules::PROPERTIES], "view-distance=8") && str_contains($after[Rules::PROPERTIES], "simulation-distance=6")
    && str_contains($after[Rules::PROPERTIES], "sync-chunk-writes=true") && str_contains($after[Rules::PROPERTIES], "#Minecraft server properties"));
check('Bukkit: comments-free file keeps its layout', substr_count($after[Rules::BUKKIT], "\n") === substr_count($files[Rules::BUKKIT], "\n"));
check('Nodes config: every comment kept', substr_count($after[Rules::NODES], '#') === substr_count($files[Rules::NODES], '#'));
check('JVM: G1 flags in, -jar part kept', str_contains($result['startup'], '-XX:+UseG1GC') && str_ends_with($result['startup'], '-jar {{SERVER_JARFILE}}') && str_starts_with($result['startup'], 'java '));
check('JVM: other flags kept (Pelican console needs -Dterminal.*)', str_contains($result['startup'], '-Dterminal.jline=false') && str_contains($result['startup'], '-Dterminal.ansi=true'));
check('JVM: the old heap setting is replaced, not doubled', substr_count($result['startup'], 'MaxRAMPercentage') === 1 && substr_count($result['startup'], '-Xms') === 1);
check('JVM: heap capped below the container limit', str_contains($result['startup'], '-XX:MaxRAMPercentage=85.0') && !str_contains($result['startup'], '95.0'));

$again = Optimizer::analyze($after, $result['startup'], $ctx, Rule::BALANCED);
$left = array_filter($again, fn ($r) => $r['status'] === Optimizer::CHANGE);
check('applying again changes nothing (everything shows as done)', $left === [], json_encode(array_column($left, 'id')));

// only the chosen ones
$one = Optimizer::apply($files, $startup, $ctx, Rule::BALANCED, ['view-distance']);
check('only chosen settings change', array_keys($one['files']) === [Rules::PROPERTIES] && $one['startup'] === null && str_contains($one['files'][Rules::PROPERTIES], 'simulation-distance=10'));

// ---------------- performance
$perf = Optimizer::analyze($files, $startup, $ctx, Rule::PERFORMANCE);
check('performance goes further', $get($perf, 'view-distance')['recommended'] === '6' && $get($perf, 'nerf-spawner-mobs')['status'] === Optimizer::CHANGE);
check('performance includes balanced-only settings', $get($perf, 'chunk-gc')['recommended'] === '400' && $get($perf, 'redstone')['status'] === Optimizer::CHANGE);
check('gameplay changes are marked', $get($perf, 'nerf-spawner-mobs')['gameplay'] === true && $get($perf, 'arrow-despawn')['gameplay'] === false);

// ---------------- other kinds of servers
$plain = Optimizer::analyze($files, $startup, new Context(8192), Rule::BALANCED);
check('no Nodes: hopper move event can be disabled', $get($plain, 'hopper-move-event')['status'] === Optimizer::CHANGE);
check('no Reef or Nodes: their rules are skipped', $get($plain, 'reef-dab')['status'] === Optimizer::SKIP && $get($plain, 'nodes-beacon-lighting')['status'] === Optimizer::SKIP);
$shard = Optimizer::analyze($files, $startup, new Context(8192, true, true, behindProxy: true, shardRole: 'leader'), Rule::PERFORMANCE);
check('behind a proxy: compression off', $get($shard, 'network-compression')['status'] === Optimizer::CHANGE && $get($shard, 'network-compression')['recommended'] === '-1');
check('leader shard: sync batching offered', $get($shard, 'nodes-publish')['status'] === Optimizer::CHANGE);
$noFiles = Optimizer::analyze([Rules::PROPERTIES => null], $startup, $ctx, Rule::BALANCED);
check('missing files are reported, not errors', $get($noFiles, 'autosave-chunks')['status'] === Optimizer::MISSING && str_contains($get($noFiles, 'autosave-chunks')['note'], 'Start the server once'));
$odd = Optimizer::analyze($files, './start.sh', $ctx, Rule::BALANCED);
check('a non-java startup command is left alone', $get($odd, 'jvm-flags')['status'] === Optimizer::SKIP);
$noPerm = Optimizer::analyze($files, $startup, $ctx, Rule::BALANCED, canChangeStartup: false);
check('without the startup permission, JVM flags are skipped', $get($noPerm, 'jvm-flags')['status'] === Optimizer::SKIP);
check('bare "java -jar" gets flags too', JvmFlags::replace('java -jar server.jar', JvmFlags::for(4096)) === 'java ' . JvmFlags::for(4096) . ' -jar server.jar');
check('JVM: 16 GB uses the large-heap values', str_contains(JvmFlags::for(16384), 'G1HeapRegionSize=16M') && str_contains(JvmFlags::for(8192), 'G1HeapRegionSize=8M'));

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
