<?php

namespace Ginjer\Network\Services\Ops;

use Illuminate\Support\Facades\Cache;

/** Marks a network as busy with planned restarts (updates, backups), so health checks don't raise alarms. */
final class Busy
{
    public static function start(int $networkId, int $minutes = 120): void
    {
        Cache::put("ginjer-network:busy:$networkId", true, now()->addMinutes($minutes));
    }

    public static function stop(int $networkId): void
    {
        Cache::forget("ginjer-network:busy:$networkId");
    }

    public static function is(int $networkId): bool
    {
        return (bool) Cache::get("ginjer-network:busy:$networkId", false);
    }
}
