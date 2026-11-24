<?php

namespace Ginjer\Network\Services\Health;

/**
 * Reads a server's log (and crash report) after it went down or froze, and says why in plain
 * words: out of memory, a frozen main thread (Paper's watchdog dump), an exception in a plugin, or
 * a Minecraft crash report. Stack frames are matched to installed plugins by their package names.
 */
final class CrashAnalyzer
{
    /** Packages of the server itself and the libraries it ships: never the culprit. */
    private const CORE = ['net.minecraft.', 'java.', 'javax.', 'jdk.', 'sun.', 'com.sun.', 'com.mojang.', 'io.papermc.', 'org.bukkit.', 'org.spigotmc.',
        'com.destroystokyo.', 'ca.spottedleaf.', 'it.unimi.', 'com.google.', 'org.apache.', 'io.netty.', 'dev.reef.', 'co.aikar.', 'net.kyori.',
        'org.slf4j.', 'kotlin.', 'kotlinx.', 'org.yaml.', 'org.jline.', 'net.minecrell.', 'com.velocitypowered.', 'org.joml.', 'net.fabricmc.', 'org.objectweb.'];

    /** Package words that say nothing about which plugin it is. */
    private const NOISE = ['com', 'org', 'net', 'me', 'io', 'dev', 'de', 'fr', 'nl', 'pl', 'ru', 'xyz', 'gg', 'github', 'plugin', 'plugins', 'bukkit', 'spigot', 'paper', 'mc', 'minecraft', 'api', 'main', 'core', 'common', 'lib', 'libs', 'util', 'utils', 'internal', 'impl', 'listener', 'listeners', 'command', 'commands', 'shaded'];

    /**
     * @param  list<string>  $plugins  plugin names (from the jars in plugins/)
     * @return array{cause: string, plugin: ?string, detail: ?string, advice: string}
     */
    public static function analyze(string $log, ?string $crashReport, array $plugins): array
    {
        $log = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', str_replace("\r", '', $log));
        $lines = explode("\n", $log);

        if (str_contains($log, 'java.lang.OutOfMemoryError') || ($crashReport && str_contains($crashReport, 'java.lang.OutOfMemoryError'))) {
            preg_match('/java\.lang\.OutOfMemoryError: ?([^\n]*)/', $log . "\n" . $crashReport, $m);

            return ['cause' => 'it ran out of memory', 'plugin' => null, 'detail' => trim('OutOfMemoryError ' . ($m[1] ?? '')),
                'advice' => 'Give it more memory (the History page can suggest how much), or look for something loading too much: a big farm, a pre-generation, a plugin cache.'];
        }

        // Paper's watchdog: the main thread stopped ticking
        $watchdog = self::indexOf($lines, 'The server has stopped responding!');
        if ($watchdog !== null) {
            $start = self::indexOf($lines, 'Server thread dump', $watchdog) ?? $watchdog;
            $frames = self::frames(array_slice($lines, $start, 80));
            $plugin = self::pluginFor($frames, $plugins);
            $top = self::firstOutside($frames);

            return ['cause' => 'its main thread froze', 'plugin' => $plugin, 'detail' => $top ? "stuck in $top" : null,
                'advice' => $plugin ? "Update or remove $plugin, or ask its author with the log." : 'The thread dump in the log shows where it was stuck; a spark profile helps if it happens again.'];
        }

        // a plugin named in an error
        $named = [
            '/Could not pass event (\w+) to ([\w.-]+)/' => fn ($m) => [$m[2], "while handling {$m[1]}"],
            '/Error occurred while enabling ([\w.-]+)/' => fn ($m) => [$m[1], 'while it was starting'],
            '/Plugin ([\w.-]+) v[\S]* generated an exception while executing task/' => fn ($m) => [$m[1], 'in one of its scheduled tasks'],
            '/Exception encountered when loading plugin: ([\w.-]+)/' => fn ($m) => [$m[1], 'while loading'],
            '/Unhandled exception executing command \'([^\']*)\' in plugin ([\w.-]+)/' => fn ($m) => [$m[2], "running /{$m[1]}"],
        ];
        for ($i = count($lines) - 1; $i >= max(0, count($lines) - 400); $i--) {
            foreach ($named as $pattern => $read) {
                if (preg_match($pattern, $lines[$i], $m)) {
                    [$plugin, $where] = $read($m);

                    return ['cause' => "$plugin threw an error $where", 'plugin' => $plugin, 'detail' => self::exceptionAfter($lines, $i),
                        'advice' => "Check for a newer $plugin, or switch it off on the Plugins page to see if that's it."];
                }
            }
        }

        if ($crashReport) {
            preg_match('/^Description: (.*)$/m', $crashReport, $d);
            $frames = self::frames(explode("\n", $crashReport));
            $plugin = self::pluginFor($frames, $plugins);

            return ['cause' => 'Minecraft crashed' . (isset($d[1]) ? ': ' . strtolower(trim($d[1])) : ''), 'plugin' => $plugin,
                'detail' => self::exceptionAfter(explode("\n", $crashReport), 0) ?? self::firstOutside($frames),
                'advice' => $plugin ? "The crash report points at $plugin." : 'The full report is in crash-reports/ on the server.'];
        }

        // the last error in the log
        for ($i = count($lines) - 1; $i >= max(0, count($lines) - 200); $i--) {
            if (preg_match('/(ERROR|SEVERE)\]: (.+)$/', $lines[$i], $m)) {
                $frames = self::frames(array_slice($lines, $i, 60));

                return ['cause' => 'it logged an error before stopping', 'plugin' => self::pluginFor($frames, $plugins), 'detail' => mb_strimwidth(trim($m[2]), 0, 300, '…'),
                    'advice' => 'Open its console for the lines around it.'];
            }
        }

        return ['cause' => 'nothing in its log says why', 'plugin' => null, 'detail' => null,
            'advice' => 'It may have been stopped from outside (out of memory on the machine, the node restarting, Wings).'];
    }

    /** @return list<string> "a.b.C.method" for every "at ..." line */
    public static function frames(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:\[[^\]]*\]:?\s*)?(?:\[[^\]]*\]:?\s*)?at ([\w$.\/]+)\(/', $line, $m)) {
                $out[] = preg_replace('#^[\w.-]+//#', '', $m[1]); // "app//com.x.Y" module prefixes
            }
        }

        return $out;
    }

    private static function firstOutside(array $frames): ?string
    {
        foreach ($frames as $frame) {
            if (!self::isCore($frame)) {
                return $frame;
            }
        }

        return $frames[0] ?? null;
    }

    private static function isCore(string $frame): bool
    {
        foreach (self::CORE as $prefix) {
            if (str_starts_with($frame, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** The plugin whose package shows up first in the frames, if any. */
    public static function pluginFor(array $frames, array $plugins): ?string
    {
        $normalized = [];
        foreach ($plugins as $plugin) {
            $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $plugin));
            if (strlen($key) >= 3) {
                $normalized[$key] = $plugin;
            }
        }
        foreach ($frames as $frame) {
            if (self::isCore($frame)) {
                continue;
            }
            $parts = array_slice(explode('.', strtolower($frame)), 0, -2); // drop Class.method
            $words = array_values(array_filter($parts, fn ($p) => strlen($p) >= 3 && !in_array($p, self::NOISE, true)));
            foreach ($words as $word) {
                foreach ($normalized as $key => $plugin) {
                    if ($word === $key || str_contains($key, $word) && strlen($word) >= 5 || str_contains($word, $key)) {
                        return $plugin;
                    }
                }
            }
            if ($words !== []) {
                return null; // the first outside frame matched nothing: don't guess from later ones
            }
        }

        return null;
    }

    private static function indexOf(array $lines, string $needle, int $from = 0): ?int
    {
        for ($i = $from; $i < count($lines); $i++) {
            if (str_contains($lines[$i], $needle)) {
                return $i;
            }
        }

        return null;
    }

    /** The first "SomeException: message" line at or after $i. */
    private static function exceptionAfter(array $lines, int $i): ?string
    {
        for ($j = $i; $j < min(count($lines), $i + 30); $j++) {
            if (preg_match('/((?:[\w$]+\.)+[\w$]*(?:Exception|Error)(?::[^\n]*)?)/', $lines[$j], $m)) {
                return mb_strimwidth(trim($m[1]), 0, 300, '…');
            }
        }

        return null;
    }
}
