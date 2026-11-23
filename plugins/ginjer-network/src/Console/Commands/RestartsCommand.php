<?php

namespace Ginjer\Network\Console\Commands;

use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Jobs\RollingUpdate;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Ops\RestartSchedule;
use Illuminate\Console\Command;
use Throwable;

/** Runs every minute: warns players before a network's daily restart, then restarts it shard by shard. */
class RestartsCommand extends Command
{
    protected $signature = 'p:ginjer:restarts';

    protected $description = 'Warn players and run Ginjer networks\' daily rolling restarts';

    public function handle(): int
    {
        foreach (Network::with('members.server')->whereNotNull('restart_daily_at')->get() as $network) {
            $due = RestartSchedule::due($network->restart_daily_at, now(), $network->last_daily_restart);
            if ($due === null) {
                continue;
            }
            [$what, $minutes] = $due;
            if ($what === 'warn') {
                $message = $minutes === 1
                    ? 'This server restarts in 1 minute. You can rejoin right after.'
                    : "This server restarts in $minutes minutes. You can rejoin right after.";
                foreach ($network->membersWithRole(MemberRole::Shard) as $shard) {
                    rescue(fn () => $shard->server->send("say $message"), report: false); // offline shards just don't hear it
                }
                $this->line("{$network->name}: warned ($minutes min)");

                continue;
            }
            $network->update(['last_daily_restart' => now()->toDateString()]);
            $admin = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', \App\Models\Role::ROOT_ADMIN))->value('id');
            if ($admin) {
                RollingUpdate::dispatch($network->id, [], (int) $admin);
                $this->line("{$network->name}: daily rolling restart started");
            }
        }

        return self::SUCCESS;
    }
}
