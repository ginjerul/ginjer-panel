<?php

namespace Ginjer\Network\Jobs;

use App\Models\Server;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Ops\Busy;
use Ginjer\Network\Services\Ops\ServerLifecycle;
use Ginjer\Network\Services\RegionList;
use Ginjer\Network\Services\Rebalance\ChunkMover;
use Ginjer\Network\Services\Rebalance\ShardMap;
use Ginjer\Network\Services\ShardConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Moves the shard borders: stops the proxy and the shards, copies the land that changes hands
 * from its old shard's world files to its new shard's, saves the new borders, pushes them to
 * every shard and starts the network again.
 *
 * If anything fails before the new borders are saved, the old borders stay. The chunks already
 * copied only refreshed land the receiving shard doesn't own under the old borders, so nothing
 * is lost either way.
 */
class RebalanceNetwork implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    /** @param  string  $regions  the new borders; ignored when undoing (the borders from before the last rebalance come back) */
    public function __construct(public int $networkId, public string $regions, public int $userId, public bool $undo = false) {}

    public function handle(ServerLifecycle $lifecycle, ChunkMover $mover, ShardConfigService $config): void
    {
        $network = Network::with('members.server')->findOrFail($this->networkId);
        $user = User::findOrFail($this->userId);
        $shards = $network->membersWithRole(MemberRole::Shard)->mapWithKeys(fn (NetworkMember $m) => [$m->shard_name => $m->server])->all();
        $proxy = $network->membersWithRole(MemberRole::Proxy)->first()?->server;
        $world = $network->world ?: 'world';
        $wasRunning = array_values(array_filter([$proxy, ...array_values($shards)], fn (?Server $s) => $s && $lifecycle->state($s) === 'running'));
        $saved = false;
        Busy::start($network->id, 180);

        $what = $this->undo ? 'Undoing the rebalance' : 'Rebalancing';
        try {
            $last = $network->last_rebalance;
            if ($this->undo && empty($last['before'])) {
                throw new \RuntimeException('There is no rebalance to undo.');
            }
            $this->regions = $this->undo ? (string) $last['before'] : $this->regions;
            $problems = RegionList::problems($this->regions, array_keys($shards));
            if ($problems !== []) {
                throw new \RuntimeException('The new borders aren\'t valid: ' . implode(' ', $problems));
            }
            $before = new ShardMap(RegionList::parse((string) $network->regions));
            $after = new ShardMap(RegionList::parse($this->regions));

            app(\Ginjer\Network\Services\Ops\NetworkBackups::class)->safetyBackup($network, $this->undo ? 'undoing the rebalance' : 'rebalancing');

            // 1. everyone off, after a warning: the proxy first, then the shards
            foreach (array_values($shards) as $server) {
                rescue(fn () => $server->send('say Restarting to rebalance the shards. Back in a few minutes!'), report: false);
            }
            ServerLifecycle::sleep(10);
            $stuck = $proxy ? $lifecycle->stopAll([$proxy]) : [];
            $stuck = [...$stuck, ...$lifecycle->stopAll(array_values($shards), max(60, (int) $network->rolling_stop_timeout))];
            if ($stuck !== []) {
                throw new \RuntimeException(implode(', ', array_map(fn ($s) => $s->name, $stuck)) . ' didn\'t stop, so no world files were touched.');
            }

            // 2. copy the land that changes hands (or put the old world files back)
            $oldRegions = (string) $network->regions;
            if ($this->undo) {
                $restored = $mover->undo($shards, $last['files'] ?? []);
                $network->update(['regions' => $this->regions, 'last_rebalance' => null]);
                $summary = "Put back $restored world file(s) and the borders from before.";
            } else {
                $plan = $mover->plan($shards, $world, $before, $after);
                $copied = $mover->apply($shards, $world, $plan['moves']);
                $network->update(['regions' => $this->regions, 'last_rebalance' => [
                    'at' => now()->toIso8601String(), 'before' => $oldRegions, 'after' => $this->regions, 'files' => $mover->written,
                ]]);
                $summary = "Moved $copied chunk(s) in {$plan['files']} region file(s).";
            }

            // 3. the new borders, on every shard
            $saved = true;
            $results = collect($config->push($network->refresh()->load('members.server')));
            $failedPush = $results->where('ok', false)->pluck('server')->all();

            $summary .= ($failedPush === [] ? '' : ' The config push failed on ' . implode(', ', $failedPush) . ': push it again before starting those.');
            NetworkLog::record($network, 'rebalance', $this->undo ? 'Rebalance undone' : 'Shard borders rebalanced', $summary, $failedPush === [] ? 'success' : 'warning', $user->id);
            Notification::make()
                ->title($this->undo ? "{$network->name}: rebalance undone" : "{$network->name} rebalanced")
                ->body($summary . ' The network is starting again.')
                ->color($failedPush === [] ? 'success' : 'warning')
                ->actions([Action::make('open')->label('Open network')->url(NetworkResource::getUrl('edit', ['record' => $network], panel: 'admin'))])
                ->sendToDatabase($user);
        } catch (Throwable $exception) {
            report($exception);
            NetworkLog::record($network, 'rebalance', "$what failed", $exception->getMessage() . ($saved ? '' : ' The old borders are still in place.'), 'danger', $user->id);
            Notification::make()
                ->title("$what of {$network->name} failed")
                ->body($exception->getMessage() . ($saved ? '' : ' The old borders are still in place; the servers that were running are starting again.'))
                ->danger()
                ->sendToDatabase($user);
        } finally {
            // 4. back up in order: shards (leader first), then the proxy
            $order = collect($wasRunning)->sortBy(fn (Server $s) => $s->is($proxy) ? 2 : ($network->leader()?->server_id === $s->id ? 0 : 1))->values()->all();
            foreach ($order as $server) {
                rescue(fn () => $lifecycle->start($server, max(60, (int) $network->rolling_start_timeout)), report: false);
            }
            Busy::stop($network->id);
        }
    }
}
