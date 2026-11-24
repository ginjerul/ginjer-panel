<?php

namespace Ginjer\Network\Jobs;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Ops\PluginUpdates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Checks a network's plugins for updates (reads every jar, asks Modrinth, Hangar and SpigotMC). */
class CheckPluginUpdates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $networkId, public ?int $userId = null) {}

    public static function key(int $networkId): string
    {
        return "ginjer-network:plugin-updates:$networkId";
    }

    public function handle(PluginUpdates $updates): void
    {
        $network = Network::findOrFail($this->networkId);
        Cache::put(self::key($network->id) . ':running', true, now()->addMinutes(15));
        try {
            $rows = $updates->check($network);
            Cache::put(self::key($network->id), ['checked' => now()->toIso8601String(), 'rows' => $rows], now()->addDays(7));
            $count = collect($rows)->where('status', 'update')->count();
            $differ = collect($rows)->where('same', false)->count();
            if ($user = $this->userId ? User::find($this->userId) : null) {
                Notification::make()
                    ->title("{$network->name}: " . ($count === 0 ? 'every plugin is up to date' : "$count plugin update(s)"))
                    ->body($differ > 0 ? "$differ plugin(s) aren't the same on every shard." : null)
                    ->color($count + $differ === 0 ? 'success' : 'warning')
                    ->actions([Action::make('open')->label('Open')->url(NetworkResource::getUrl('plugin-updates', ['record' => $network], panel: 'admin'))])
                    ->sendToDatabase($user);
            }
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            Cache::forget(self::key($network->id) . ':running');
        }
    }
}
