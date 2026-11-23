<?php

namespace Ginjer\Network\Jobs;

use App\Models\User;
use Filament\Notifications\Notification;
use Ginjer\Network\Models\NetworkBackup;
use Ginjer\Network\Services\Ops\Busy;
use Ginjer\Network\Services\Ops\NetworkBackups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class RestoreNetworkBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $backupId, public int $userId) {}

    public function handle(NetworkBackups $backups): void
    {
        $group = NetworkBackup::with('network')->findOrFail($this->backupId);
        $user = User::findOrFail($this->userId);
        Busy::start($group->network_id, 60);
        try {
            $problems = $backups->restore($group, $user);
            Notification::make()
                ->title($problems === [] ? "Restoring {$group->network->name} to \"{$group->name}\"" : "Restoring {$group->network->name} partly failed")
                ->body(($problems === [] ? 'Every server is restoring. Start the network when they\'re done (their state shows "restoring" until then).' : implode(' ', $problems)))
                ->color($problems === [] ? 'success' : 'danger')
                ->sendToDatabase($user);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title("Restoring {$group->network->name} failed")->body($exception->getMessage())->danger()->sendToDatabase($user);
        } finally {
            Busy::stop($group->network_id);
        }
    }
}
