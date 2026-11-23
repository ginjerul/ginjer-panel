<?php

namespace Ginjer\Network\Console\Commands;

use Ginjer\Network\Jobs\TakeNetworkBackup;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkBackup;
use Illuminate\Console\Command;

/** Runs every 15 minutes: starts scheduled network backups and marks finished ones done or failed. */
class BackupsCommand extends Command
{
    protected $signature = 'p:ginjer:backups';

    protected $description = 'Start scheduled Ginjer network backups and track running ones';

    public function handle(): int
    {
        foreach (NetworkBackup::where('status', 'running')->get() as $group) {
            $group->refreshStatus();
        }
        foreach (Network::whereNotNull('backup_every_hours')->get() as $network) {
            if ($network->last_backup_at === null || $network->last_backup_at->lte(now()->subHours($network->backup_every_hours))) {
                TakeNetworkBackup::dispatch($network->id);
                $this->line("{$network->name}: backup started");
            }
        }

        return self::SUCCESS;
    }
}
