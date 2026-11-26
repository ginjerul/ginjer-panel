<?php

namespace Ginjer\Network\Services\Ops;

use Carbon\CarbonInterface;

/** When a scheduled command runs: at a time of day, or every N minutes, on the chosen weekdays. */
final class ScheduleRules
{
    /** @param  list<int>  $days  ISO weekdays (1 = Monday); empty is every day */
    public static function due(?string $atTime, ?int $everyMinutes, array $days, ?CarbonInterface $lastRun, CarbonInterface $now): bool
    {
        $now = $now->copy()->startOfMinute();
        if ($days !== [] && !in_array($now->dayOfWeekIso, $days, true)) {
            return false;
        }
        if ($lastRun !== null && $lastRun->copy()->startOfMinute()->equalTo($now)) {
            return false; // already ran this minute
        }
        if ($everyMinutes !== null && $everyMinutes > 0) {
            return $lastRun === null || $lastRun->copy()->startOfMinute()->addMinutes($everyMinutes)->lessThanOrEqualTo($now);
        }
        if ($atTime !== null && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $atTime)) {
            return $now->format('H:i') === $atTime;
        }

        return false;
    }

    /** "Daily at 18:00", "Every 30 minutes on Mon, Fri". */
    public static function describe(?string $atTime, ?int $everyMinutes, array $days): string
    {
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $when = $everyMinutes ? "Every $everyMinutes minutes" : "At $atTime";
        if ($days === [] || count($days) === 7) {
            return $when . ($everyMinutes ? '' : ' every day');
        }

        return $when . ' on ' . implode(', ', array_map(fn ($d) => $names[$d] ?? $d, $days));
    }
}
