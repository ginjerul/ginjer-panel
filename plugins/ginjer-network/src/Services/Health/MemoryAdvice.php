<?php

namespace Ginjer\Network\Services\Health;

/**
 * Says when a shard needs more memory. Java grows its heap towards its limit even when it's fine,
 * so "nearly full" alone means nothing; the sign of real pressure is being nearly full while the
 * shard is also lagging (the garbage collector runs constantly and steals ticks).
 */
final class MemoryAdvice
{
    public const MIN_SAMPLES = 20;     // minutes of data in the last hour

    public const FULL = 0.95;          // of the container's limit

    /**
     * @param  list<array{memory_mb: ?int, memory_limit_mb: ?int, tps: ?float, mspt: ?float}>  $samples  the last hour
     * @return ?int the memory to give the server, in MB, or null when it's fine
     */
    public static function suggest(array $samples, int $serverMemoryMb): ?int
    {
        $usable = array_values(array_filter($samples, fn ($s) => $s['memory_mb'] !== null && $s['memory_limit_mb']));
        if (count($usable) < self::MIN_SAMPLES || $serverMemoryMb <= 0) {
            return null;
        }
        $struggling = array_filter($usable, fn ($s) => $s['memory_mb'] / $s['memory_limit_mb'] >= self::FULL
            && (($s['tps'] !== null && $s['tps'] < 19.5) || ($s['mspt'] !== null && $s['mspt'] > 45)));
        if (count($struggling) < count($usable) / 2) {
            return null;
        }

        // a quarter more, at least 1 GB, in 512 MB steps
        return (int) (ceil(max($serverMemoryMb * 1.25, $serverMemoryMb + 1024) / 512) * 512);
    }
}
