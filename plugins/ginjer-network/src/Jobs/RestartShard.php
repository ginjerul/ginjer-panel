<?php

namespace Ginjer\Network\Jobs;

use App\Models\Server;
use Ginjer\Network\Services\Ops\Busy;
use Ginjer\Network\Services\Ops\ServerLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Restarts one shard (for sustained lag) and keeps the health check quiet while it does. */
class RestartShard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public function __construct(public int $serverId, public int $networkId) {}

    public function handle(ServerLifecycle $lifecycle): void
    {
        $server = Server::find($this->serverId);
        if ($server === null) {
            return;
        }
        Busy::start($this->networkId, 15);
        try {
            $lifecycle->restart($server);
        } finally {
            Busy::stop($this->networkId);
        }
    }
}
