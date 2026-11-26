<?php

namespace Ginjer\Network\Console\Commands;

use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\NetworkSchedule;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Ops\Console;
use Ginjer\Network\Services\Ops\ScheduleRules;
use Illuminate\Console\Command;
use Throwable;

/** Runs every minute: sends the scheduled commands that are due. */
class SchedulesCommand extends Command
{
    protected $signature = 'p:ginjer:schedules';

    protected $description = 'Run Ginjer networks\' scheduled commands';

    public function handle(): int
    {
        foreach (NetworkSchedule::with('network.members.server')->where('enabled', true)->get() as $schedule) {
            if ($schedule->network === null || !ScheduleRules::due($schedule->at_time, $schedule->every_minutes, $schedule->dayList(), $schedule->last_run_at, now())) {
                continue;
            }
            $schedule->update(['last_run_at' => now()]);
            $roles = match ($schedule->targets) {
                'proxy' => [MemberRole::Proxy],
                'all' => [MemberRole::Shard, MemberRole::Proxy],
                default => [MemberRole::Shard],
            };
            $failed = [];
            $sent = 0;
            foreach ($schedule->network->members->filter(fn ($m) => in_array($m->role, $roles, true)) as $member) {
                try {
                    Console::run($member->server, $schedule->commandLines());
                    $sent++;
                } catch (Throwable) {
                    $failed[] = $member->shard_name ?: $member->server->name;
                }
            }
            // only problems go to the activity log: a schedule every few minutes would flood it
            if ($sent === 0 && $failed !== []) {
                NetworkLog::record($schedule->network, 'schedule', "Scheduled \"{$schedule->name}\" didn't run", 'No server was running: ' . implode(', ', $failed) . '.', 'warning', null);
            }
        }

        return self::SUCCESS;
    }
}
