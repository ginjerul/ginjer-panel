<?php

namespace Ginjer\Network\Jobs;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sends start, stop, restart or kill to one server. Queued so a network can start in stages. */
class SendPowerAction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public function __construct(public int $serverId, public string $action) {}

    public function handle(DaemonServerRepository $repository): void
    {
        $server = Server::find($this->serverId);
        if ($server === null || !$server->isInstalled() || $server->isSuspended()) {
            return;
        }

        try {
            $repository->setServer($server)->power($this->action);
        } catch (Throwable $exception) {
            Log::warning("Ginjer Network: couldn't {$this->action} server {$server->name}: {$exception->getMessage()}");
        }
    }
}
