<?php

namespace Ginjer\Network\Jobs;

use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Services\Ops\ServerLifecycle;
use Ginjer\Network\Services\Ops\ServerTools;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Waits for a spark profile to finish uploading and sends the admin its link (spark writes it to the log). */
class ProfileWithSpark implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public function __construct(public int $serverId, public int $userId, public int $seconds, public ?string $previousLink) {}

    public function handle(ServerTools $tools): void
    {
        $server = Server::findOrFail($this->serverId);
        $user = User::findOrFail($this->userId);
        ServerLifecycle::sleep($this->seconds + 10);

        $link = null;
        for ($try = 0; $try < 6 && ($link === null || $link === $this->previousLink); $try++) {
            if ($try > 0) {
                ServerLifecycle::sleep(10);
            }
            $link = rescue(fn () => $tools->sparkLink($server), null, false);
        }

        if ($link !== null && $link !== $this->previousLink) {
            Notification::make()
                ->title("spark profile of {$server->name} is ready")
                ->body('It shows what the server spent its time on during those ' . $this->seconds . ' seconds.')
                ->success()
                ->actions([Action::make('open')->label('Open the profile')->url($link, shouldOpenInNewTab: true)])
                ->sendToDatabase($user);
        } else {
            Notification::make()
                ->title("No spark profile link from {$server->name}")
                ->body('spark didn\'t write a link to the log. Check the console: the profile may still be uploading, or spark may have failed to start.')
                ->warning()
                ->sendToDatabase($user);
        }
    }
}
