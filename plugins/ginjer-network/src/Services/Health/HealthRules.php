<?php

namespace Ginjer\Network\Services\Health;

/**
 * Decides, once a minute, what's worth telling an admin about a network's shards. Pure: it gets
 * what was observed and what it remembered from the last minute, and returns alerts, shards to
 * restart and what to remember, so it can be tested without waiting.
 *
 * Each problem is reported once when it starts and once when it's over:
 *  - a shard that was running stops (Wings' crash detection usually restarts it on its own)
 *  - a running shard doesn't show up in Redis for 2 minutes (Nodes isn't syncing it)
 *  - a shard's TPS stays below the threshold for the configured minutes; optionally it's restarted
 * Nothing is reported while the network is busy with an update, restart or backup.
 */
final class HealthRules
{
    public const SILENT_MINUTES = 2;

    /**
     * @param  array<string, array{state: ?string, reporting: bool, tps: ?float, mspt: ?float}>  $seen  shard name => what was observed
     * @param  array<string, array<string, mixed>>  $memory  shard name => what the last check remembered
     * @param  array{lag_tps: int, lag_minutes: int, restart_on_lag: bool}  $settings
     * @return array{alerts: list<array{level: string, title: string, body: string}>, restart: list<string>, lagging: list<string>, memory: array<string, array<string, mixed>>}
     */
    public static function evaluate(array $seen, array $memory, array $settings, bool $busy): array
    {
        $alerts = [];
        $restart = [];
        $lagging = []; // shards whose lag was just reported: worth a spark profile
        $next = [];

        foreach ($seen as $name => $now) {
            $m = ($memory[$name] ?? []) + ['running' => null, 'down' => false, 'silent' => 0, 'silentAlerted' => false, 'lag' => 0, 'lagAlerted' => false];
            $running = $now['state'] === 'running';

            if ($busy) {
                // planned restarts: forget counters, remember the new state quietly
                $next[$name] = ['running' => $running, 'down' => false, 'silent' => 0, 'silentAlerted' => false, 'lag' => 0, 'lagAlerted' => false];

                continue;
            }

            if (!$running) {
                if ($m['running'] === true && !$m['down']) {
                    $alerts[] = ['level' => 'danger', 'shard' => $name, 'kind' => 'down', 'title' => "$name is down", 'body' => "Wings reports it as " . ($now['state'] ?? 'not answering') . '. Players there are disconnected until it\'s back.'];
                    $m['down'] = true;
                }
                $next[$name] = ['running' => false, 'silent' => 0, 'silentAlerted' => false, 'lag' => 0, 'lagAlerted' => false] + $m;

                continue;
            }

            if ($m['down']) {
                $alerts[] = ['level' => 'success', 'shard' => $name, 'kind' => 'back', 'title' => "$name is back", 'body' => 'It\'s running again.'];
                $m['down'] = false;
            }

            // Nodes heartbeat
            if (!$now['reporting']) {
                $m['silent']++;
                if ($m['silent'] >= self::SILENT_MINUTES && !$m['silentAlerted']) {
                    $alerts[] = ['level' => 'warning', 'shard' => $name, 'kind' => 'silent', 'title' => "$name isn't syncing with Nodes", 'body' => "It's running but hasn't reported to Redis for {$m['silent']} minutes, so towns and claims there may be out of date. Check that Nodes started and can reach Redis."];
                    $m['silentAlerted'] = true;
                }
            } else {
                if ($m['silentAlerted']) {
                    $alerts[] = ['level' => 'success', 'shard' => $name, 'kind' => 'syncing', 'title' => "$name is syncing again", 'body' => 'Nodes on it reports to Redis again.'];
                }
                $m['silent'] = 0;
                $m['silentAlerted'] = false;
            }

            // lag
            if ($now['tps'] !== null && $now['tps'] < $settings['lag_tps']) {
                $m['lag']++;
                if ($m['lag'] >= $settings['lag_minutes'] && !$m['lagAlerted']) {
                    $detail = sprintf('%.1f TPS%s for %d minutes (threshold %d).', $now['tps'], $now['mspt'] !== null ? sprintf(', %.0f ms per tick', $now['mspt']) : '', $m['lag'], $settings['lag_tps']);
                    if ($settings['restart_on_lag']) {
                        $restart[] = $name;
                        $alerts[] = ['level' => 'warning', 'shard' => $name, 'kind' => 'lag_restart', 'title' => "Restarting $name: it's lagging", 'body' => $detail . ' Restarting it, as set in the network\'s health settings.'];
                        $m['lag'] = 0; // a fresh start
                    } else {
                        $alerts[] = ['level' => 'warning', 'shard' => $name, 'kind' => 'lag', 'title' => "$name is lagging", 'body' => $detail . ' If spark is installed, a profile is being recorded; its link follows in a minute.'];
                        $lagging[] = $name;
                        $m['lagAlerted'] = true;
                    }
                }
            } elseif ($now['tps'] !== null) {
                if ($m['lagAlerted']) {
                    $alerts[] = ['level' => 'success', 'shard' => $name, 'kind' => 'lag_over', 'title' => "$name stopped lagging", 'body' => sprintf('Back to %.1f TPS.', $now['tps'])];
                }
                $m['lag'] = 0;
                $m['lagAlerted'] = false;
            }

            $m['running'] = true;
            $next[$name] = $m;
        }

        return ['alerts' => $alerts, 'restart' => $restart, 'lagging' => $lagging, 'memory' => $next];
    }
}
