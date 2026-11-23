<?php

namespace Ginjer\Network\Jobs;

use App\Models\User;
use Filament\Notifications\Notification;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Ops\Busy;
use Ginjer\Network\Services\Ops\NetworkBackups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class TakeNetworkBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $networkId, public ?int $userId = null) {}

    public function handle(NetworkBackups $backups): void
    {
        $network = Network::findOrFail($this->networkId);
        $user = $this->userId ? User::find($this->userId) : null;
        Busy::start($network->id, 10);
        try {
            $result = $backups->take($network);
            $user && Notification::make()
                ->title("Backing up {$network->name}")
                ->body('Started a backup of ' . count($result['backup']->backups) . ' server(s).' . ($result['problems'] === [] ? '' : ' ' . implode(' ', $result['problems'])))
                ->color($result['problems'] === [] ? 'success' : 'warning')
                ->sendToDatabase($user);
        } catch (Throwable $exception) {
            report($exception);
            $user && Notification::make()->title("Backing up {$network->name} failed")->body($exception->getMessage())->danger()->sendToDatabase($user);
        } finally {
            Busy::stop($network->id);
        }
    }
}
