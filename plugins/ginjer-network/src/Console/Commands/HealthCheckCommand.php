<?php

namespace Ginjer\Network\Console\Commands;

use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Health\NetworkHealth;
use Illuminate\Console\Command;
use Throwable;

/** Runs every minute (see the plugin's service provider): checks every network's shards. */
class HealthCheckCommand extends Command
{
    protected $signature = 'p:ginjer:health';

    protected $description = 'Check every Ginjer network\'s shards and alert admins about problems';

    public function handle(NetworkHealth $health): int
    {
        foreach (Network::with('members.server')->get() as $network) {
            try {
                $result = $health->check($network);
                $this->line("{$network->name}: " . count($result['alerts']) . ' alert(s), ' . count($result['restart']) . ' restart(s)');
            } catch (Throwable $exception) {
                report($exception);
                $this->error("{$network->name}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
