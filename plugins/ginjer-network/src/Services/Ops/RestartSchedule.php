<?php

namespace Ginjer\Network\Services\Ops;

use Carbon\CarbonInterface;

/** When a network's daily restart and its warnings are due. Checked once a minute. */
final class RestartSchedule
{
    /** Minutes before the restart that players are warned. */
    public const WARNINGS = [10, 5, 1];

    /**
     * @param  ?string  $at  "HH:MM" in the panel's time zone
     * @return array{0: 'warn'|'restart', 1: int}|null what to do this minute ("warn" with the minutes left), or nothing
     */
    public static function due(?string $at, CarbonInterface $now, ?CarbonInterface $lastRestart): ?array
    {
        if ($at === null || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $at, $m)) {
            return null;
        }
        $time = $now->copy()->setTime((int) $m[1], (int) $m[2]);
        if ($lastRestart !== null && $lastRestart->isSameDay($time)) {
            return null; // already done today
        }
        $minutesLeft = (int) round($now->copy()->startOfMinute()->diffInMinutes($time, false));
        if ($minutesLeft <= 0 && $minutesLeft > -5) {
            return ['restart', 0]; // a few minutes of slack if the scheduler skipped a minute
        }

        return in_array($minutesLeft, self::WARNINGS, true) ? ['warn', $minutesLeft] : null;
    }
}
