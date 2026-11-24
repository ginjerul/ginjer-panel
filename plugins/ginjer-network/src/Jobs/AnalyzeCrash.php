<?php

namespace Ginjer\Network\Jobs;

use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Health\CrashAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** After a shard went down or froze: reads its log and crash report and says why. */
class AnalyzeCrash implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $serverId, public int $networkId, public string $shard, public string $kind = 'down') {}

    public function handle(DaemonFileRepository $files): void
    {
        $server = Server::find($this->serverId);
        $network = Network::find($this->networkId);
        if ($server === null || $network === null || Cache::has("ginjer-network:crash:{$server->id}")) {
            return; // one analysis per crash
        }
        $result = self::investigate($files, $server);
        if ($this->kind === 'silent' && !in_array($result['cause'], ['its main thread froze', 'it ran out of memory'], true)) {
            return; // not syncing but not frozen: a Redis or Nodes problem, already alerted
        }
        Cache::put("ginjer-network:crash:{$server->id}", true, now()->addMinutes(10));
        $title = "{$this->shard} " . ($this->kind === 'silent' ? 'stopped responding' : 'went down') . ": {$result['cause']}";
        $body = implode("\n", array_filter([
            $result['plugin'] ? "Likely cause: {$result['plugin']}" : null,
            $result['detail'],
            $result['advice'],
        ]));
        NetworkLog::record($network, 'crash', $title, $body, 'danger', null);

        if ($network->health_alerts) {
            foreach (User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::ROOT_ADMIN))->get() as $admin) {
                rescue(fn () => Notification::make()
                    ->title("{$network->name}: $title")
                    ->body($body)
                    ->danger()
                    ->actions([Action::make('open')->label('Activity')->url(NetworkResource::getUrl('activity', ['record' => $network], panel: 'admin'))])
                    ->sendToDatabase($admin), report: false);
            }
        }
    }

    /** @return array{cause: string, plugin: ?string, detail: ?string, advice: string} */
    public static function investigate(DaemonFileRepository $files, Server $server): array
    {
        $files->setServer($server);
        $plugins = collect(rescue(fn () => $files->getDirectory('plugins'), [], false))
            ->filter(fn ($e) => ($e['file'] ?? false) && preg_match('/\.jar$/i', $e['name'] ?? ''))
            ->map(fn ($e) => preg_replace('/([-_ ]v?\d.*)?\.jar$/i', '', $e['name']))
            ->values()->all();

        // a crash report from the last 15 minutes
        $report = null;
        $reports = collect(rescue(fn () => $files->getDirectory('crash-reports'), [], false))
            ->filter(fn ($e) => ($e['file'] ?? false) && isset($e['modified']) && rescue(fn () => Carbon::parse($e['modified'])->gt(now()->subMinutes(15)), false, false))
            ->sortByDesc('modified');
        if ($newest = $reports->first()) {
            $report = rescue(fn () => (string) $files->getContent('crash-reports/' . $newest['name'], 4 * 1024 * 1024), null, false);
        }

        $log = rescue(fn () => (string) $files->getContent('logs/latest.log', 16 * 1024 * 1024), '', false);
        $result = CrashAnalyzer::analyze(self::tail($log), $report, $plugins);
        if ($result['plugin'] === null && $result['cause'] === 'nothing in its log says why') {
            // restarted already: the crash is in the newest archived log
            $archived = collect(rescue(fn () => $files->getDirectory('logs'), [], false))
                ->filter(fn ($e) => str_ends_with($e['name'] ?? '', '.log.gz'))
                ->sortByDesc('modified')->first();
            if ($archived) {
                $gz = rescue(fn () => (string) $files->getContent('logs/' . $archived['name'], 16 * 1024 * 1024), '', false);
                $old = $gz === '' ? false : @gzdecode($gz);
                if (is_string($old) && $old !== '') {
                    $result = CrashAnalyzer::analyze(self::tail($old), $report, $plugins);
                }
            }
        }

        return $result;
    }

    /** The last 2000 lines: what led up to it. */
    private static function tail(string $log): string
    {
        $lines = explode("\n", $log);

        return implode("\n", array_slice($lines, -2000));
    }
}
