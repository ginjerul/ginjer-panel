<?php

namespace Ginjer\Network\Services\Optimizer;

/**
 * Aikar's G1 flags (https://docs.papermc.io/paper/aikars-flags), with the larger young-generation
 * settings Aikar gives for heaps of 12 GB and up.
 *
 * The heap is sized with -XX:MaxRAMPercentage=85 rather than -Xmx, so it follows the memory set
 * for the server in the panel. 85% leaves room for Java's own memory outside the heap (threads,
 * code cache, direct buffers), so the container isn't killed for going over its limit.
 * -XX:+AlwaysPreTouch is left out: it only helps when the whole heap is reserved up front.
 */
final class JvmFlags
{
    public static function for(int $memoryMb): string
    {
        $big = $memoryMb >= 12288;

        return implode(' ', [
            '-Xms128M',
            '-XX:MaxRAMPercentage=85.0',
            '-XX:+UseG1GC',
            '-XX:+ParallelRefProcEnabled',
            '-XX:MaxGCPauseMillis=200',
            '-XX:+UnlockExperimentalVMOptions',
            '-XX:+DisableExplicitGC',
            '-XX:G1NewSizePercent=' . ($big ? 40 : 30),
            '-XX:G1MaxNewSizePercent=' . ($big ? 50 : 40),
            '-XX:G1HeapRegionSize=' . ($big ? '16M' : '8M'),
            '-XX:G1ReservePercent=' . ($big ? 15 : 20),
            '-XX:G1HeapWastePercent=5',
            '-XX:G1MixedGCCountTarget=4',
            '-XX:InitiatingHeapOccupancyPercent=' . ($big ? 20 : 15),
            '-XX:G1MixedGCLiveThresholdPercent=90',
            '-XX:G1RSetUpdatingPauseIntervalMillis=100',
            '-XX:SurvivorRatio=32',
            '-XX:+PerfDisableSharedMem',
            '-XX:MaxTenuringThreshold=1',
            '-Dusing.aikars.flags=https://mcflags.emc.gs',
            '-Daikars.new.flags=true',
        ]);
    }

    /**
     * The JVM flags in a startup command: everything between "java" and "-jar". Null if the
     * command isn't "java ... -jar ...".
     */
    public static function extract(string $startup): ?string
    {
        if (!preg_match('/^(\s*\S*java)\s+(.*?)\s*(-jar\s.*)$/s', $startup, $m)) {
            return null;
        }

        return trim($m[2]);
    }

    /**
     * The recommended flags plus every flag from $current the optimizer doesn't manage (such as
     * -Dterminal.jline=false, which Pelican's console needs). Heap size, -XX options and earlier
     * Aikar markers are replaced.
     */
    public static function merge(string $current, string $recommended): string
    {
        $kept = array_filter(preg_split('/\s+/', trim($current)) ?: [], fn ($flag) => $flag !== ''
            && !preg_match('/^-(Xm[sx]|Xmn|XX:|Dusing\.aikars|Daikars)/', $flag)
            && !str_contains($recommended, $flag));

        return trim($recommended . ' ' . implode(' ', $kept));
    }

    /** The startup command with its JVM flags replaced, or null if it can't be recognised. */
    public static function replace(string $startup, string $flags): ?string
    {
        if (!preg_match('/^(\s*\S*java)\s+(.*?)\s*(-jar\s.*)$/s', $startup, $m)) {
            return null;
        }

        return $m[1] . ' ' . $flags . ' ' . $m[3];
    }
}
