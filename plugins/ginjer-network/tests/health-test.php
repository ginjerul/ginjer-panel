<?php

/**
 * Checks for the health alert rules (no panel needed).
 *
 *   php tests/health-test.php
 */

require __DIR__ . '/../src/Services/Health/HealthRules.php';

use Ginjer\Network\Services\Health\HealthRules;

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

$settings = ['lag_tps' => 15, 'lag_minutes' => 3, 'restart_on_lag' => false];
$ok = ['state' => 'running', 'reporting' => true, 'tps' => 19.9, 'mspt' => 12.0];
$slow = ['state' => 'running', 'reporting' => true, 'tps' => 9.5, 'mspt' => 105.0];
$down = ['state' => 'offline', 'reporting' => false, 'tps' => null, 'mspt' => null];
$silent = ['state' => 'running', 'reporting' => false, 'tps' => null, 'mspt' => null];

/** Runs a sequence of minutes for one shard and returns every alert title in order, plus restarts. */
function run(array $minutes, array $settings, array $busy = []): array
{
    $memory = [];
    $titles = [];
    $restarts = 0;
    foreach ($minutes as $i => $seen) {
        $r = HealthRules::evaluate(['west' => $seen], $memory, $settings, in_array($i, $busy, true));
        $memory = $r['memory'];
        foreach ($r['alerts'] as $a) {
            $titles[] = $a['level'] . ': ' . $a['title'];
        }
        $restarts += count($r['restart']);
    }

    return [$titles, $restarts];
}

[$t] = run([$ok, $ok, $ok], $settings);
check('a healthy shard raises nothing', $t === [], json_encode($t));

[$t] = run([$ok, $down, $down, $down, $ok, $ok], $settings);
check('down: one alert, then one when it\'s back', $t === ['danger: west is down', 'success: west is back'], json_encode($t));

[$t] = run([$down, $down], $settings);
check('a shard that was never seen running isn\'t reported as down', $t === [], json_encode($t));

[$t] = run([$ok, $silent, $silent, $silent, $ok], $settings);
check('not reporting: alert after 2 minutes, once, then recovery', $t === ['warning: west isn\'t syncing with Nodes', 'success: west is syncing again'], json_encode($t));

[$t] = run([$ok, $slow, $slow, $ok, $slow, $slow], $settings);
check('short lag spikes (under 3 minutes) raise nothing', $t === [], json_encode($t));

[$t, $r] = run([$ok, $slow, $slow, $slow, $slow, $slow, $ok], $settings);
check('sustained lag: one alert, then one when it recovers', $t === ['warning: west is lagging', 'success: west stopped lagging'], json_encode($t));
check('no restart unless enabled', $r === 0);

[$t, $r] = run([$ok, $slow, $slow, $slow, $slow, $slow, $slow], ['restart_on_lag' => true] + $settings);
check('with restarts on: restarted after 3 minutes, and again after 3 more', $r === 2 && count($t) === 2 && str_starts_with($t[0], 'warning: Restarting west'), json_encode([$t, $r]));

[$t] = run([$ok, $down, $down, $ok], $settings, busy: [1, 2, 3]);
check('nothing while the network is busy (updates, backups)', $t === [], json_encode($t));
[$t] = run([$ok, $down, $ok, $down], $settings, busy: [1, 2]);
check('after a busy period, real problems are reported again', $t === ['danger: west is down'], json_encode($t));

$both = HealthRules::evaluate(['west' => $down, 'east' => $ok], ['west' => ['running' => true], 'east' => ['running' => true]], $settings, false);
check('shards are judged separately', count($both['alerts']) === 1 && str_contains($both['alerts'][0]['title'], 'west'));
$lag = HealthRules::evaluate(['west' => $slow], ['west' => ['running' => true, 'lag' => 2]], $settings, false);
check('the alert says how slow', str_contains($lag['alerts'][0]['body'] ?? '', '9.5 TPS, 105 ms per tick for 3 minutes'), json_encode($lag['alerts']));

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
