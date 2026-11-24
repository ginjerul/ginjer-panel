<?php

namespace Ginjer\Network\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Ops\Artifacts;
use Ginjer\Network\Services\Ops\Busy;
use Ginjer\Network\Services\Ops\RollingPlan;
use Ginjer\Network\Services\Ops\ServerLifecycle;
use Ginjer\Network\Services\Ops\WingsUploader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Updates Reef, Nodes and GinjerMap on every shard of a network and restarts the shards one at a
 * time, so the network never goes down as a whole:
 *  1. each file is downloaded once by the panel
 *  2. it's uploaded to every shard under a temporary name (nothing running is touched; if any
 *     upload fails, nothing is restarted)
 *  3. followers first and the leader last: stop, swap the new file in, start, wait until running
 *     and settled, then the next shard. A shard that doesn't come back stops the rollout.
 * With nothing selected it's a plain rolling restart. A shard that was offline gets the new files
 * but stays offline.
 */
class RollingUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public const STAGED = '.ginjer-new';

    /** @param  array{reef?: bool, nodes?: bool, ginjermap?: bool}  $what */
    public function __construct(public int $networkId, public array $what, public int $userId) {}

    public function handle(Artifacts $artifacts, WingsUploader $uploader, DaemonFileRepository $files, ServerLifecycle $lifecycle): void
    {
        $network = Network::with('members.server.node', 'members.server.egg')->findOrFail($this->networkId);
        $user = User::findOrFail($this->userId);
        $local = [];
        $done = [];
        Busy::start($network->id); // planned restarts: no health alarms

        try {
            // 1. downloads
            if ($this->what['reef'] ?? false) {
                $local['reef'] = $artifacts->download($network->reef_jar_url ?: 'https://github.com/ginjerul/reef/releases/download/dev-main/reef.jar', $network->github_token);
            }
            foreach (['nodes' => 'nodes.jar', 'ginjermap' => 'ginjermap.jar'] as $key => $asset) {
                if ($this->what[$key] ?? false) {
                    if (blank($network->nodes_repo)) {
                        throw new \RuntimeException('Set the Nodes repository in the network\'s update settings first.');
                    }
                    $local[$key] = $artifacts->githubAsset($network->nodes_repo, $network->nodes_release ?: 'latest', $asset, $network->github_token);
                }
            }
            if ($local !== []) {
                $done[] = 'downloaded ' . implode(', ', array_keys($local));
            }

            // 2. upload to every shard under a temporary name
            $shards = $network->membersWithRole(MemberRole::Shard)->sortBy(fn (NetworkMember $m) => $m->nodes_leader ? 1 : 0)->values();
            $plan = [];
            foreach ($shards as $shard) {
                $server = $shard->server;
                $plan[$server->id] = [];
                foreach ($local as $key => $path) {
                    [$directory, $target, $exists] = $this->target($files, $server, $key);
                    $uploader->upload($server, $user, $path, $directory, $target . self::STAGED);
                    $plan[$server->id][] = [$directory, $target, $exists];
                }
            }
            if ($local !== []) {
                $done[] = 'uploaded to ' . $shards->count() . ' shard(s)';
            }

            // 3. batch by batch, in the network's rolling restart order
            $servers = $network->members->mapWithKeys(fn (NetworkMember $m) => [$m->server_id => $m->server]);
            $batches = RollingPlan::batches(
                $shards->map(fn (NetworkMember $m) => ['id' => $m->server_id, 'name' => (string) $m->shard_name, 'leader' => $m->nodes_leader])->all(),
                $network->rolling_order ?: 'followers_first',
                max(1, (int) $network->rolling_batch),
                $network->rolling_include_proxy ? $network->membersWithRole(MemberRole::Proxy)->first()?->server_id : null,
            );
            $failed = [];
            foreach ($batches as $batch) {
                $members = array_map(fn (int $id) => $servers[$id], $batch);
                $running = array_values(array_filter($members, fn (Server $s) => $lifecycle->state($s) === 'running'));

                if ($running !== [] && $network->rolling_warn_seconds > 0) {
                    $seconds = $network->rolling_warn_seconds;
                    foreach ($running as $server) {
                        rescue(fn () => $server->send("say This area restarts in $seconds seconds. You can rejoin right after."), report: false);
                    }
                    ServerLifecycle::sleep($seconds);
                }

                $stuck = $lifecycle->stopAll($members, max(10, (int) $network->rolling_stop_timeout));
                foreach ($stuck as $server) {
                    $failed[] = "{$server->name} didn't stop";
                }
                foreach ($members as $server) {
                    if (in_array($server, $stuck, true)) {
                        continue;
                    }
                    foreach ($plan[$server->id] ?? [] as [$directory, $target, $exists]) {
                        if ($exists) {
                            $files->setServer($server)->deleteFiles($directory, [$target]);
                        }
                        $files->setServer($server)->renameFiles($directory, [['from' => $target . self::STAGED, 'to' => $target]]);
                    }
                }

                $toStart = array_values(array_filter($running, fn (Server $s) => !in_array($s, $stuck, true)));
                foreach ($lifecycle->startAll($toStart, max(10, (int) $network->rolling_start_timeout)) as $server) {
                    $failed[] = "{$server->name} didn't come back up";
                }
                foreach ($members as $server) {
                    $done[] = $server->name . (in_array($server, $running, true) ? ' restarted' : ' updated (was offline, left offline)');
                }

                if ($failed !== [] && $network->rolling_on_failure !== 'continue') {
                    throw new \RuntimeException(implode('; ', $failed) . '. Stopped here: the servers after it are untouched. Check its console.');
                }
                if ($toStart !== []) {
                    ServerLifecycle::sleep(max(0, $network->restart_settle_seconds));
                }
            }

            NetworkLog::record($network, $local === [] ? 'restart' : 'update', ($local === [] ? 'Rolling restart' : 'Rolling update') . ' finished' . ($failed === [] ? '' : ' with problems'),
                ($failed === [] ? '' : 'Problems: ' . implode('; ', $failed) . '. ') . implode('; ', $done) . '.', $failed === [] ? 'success' : 'warning', $user->id);
            Notification::make()
                ->title(($local === [] ? 'Rolling restart' : 'Rolling update') . " of {$network->name} finished" . ($failed === [] ? '' : ' with problems'))
                ->body(($failed === [] ? '' : 'Problems: ' . implode('; ', $failed) . '. ') . implode('; ', $done) . '.')
                ->color($failed === [] ? 'success' : 'warning')
                ->actions([Action::make('open')->label('Open network')->url(NetworkResource::getUrl('edit', ['record' => $network], panel: 'admin'))])
                ->sendToDatabase($user);
        } catch (Throwable $exception) {
            report($exception);
            NetworkLog::record($network, 'update.failed', ($local === [] ? 'Rolling restart' : 'Rolling update') . ' stopped', $exception->getMessage(), 'danger', $user->id);
            Notification::make()
                ->title(($local === [] ? 'Rolling restart' : 'Rolling update') . " of {$network->name} stopped")
                ->body($exception->getMessage() . ($done === [] ? '' : ' Done before that: ' . implode('; ', $done) . '.'))
                ->danger()
                ->sendToDatabase($user);
        } finally {
            Busy::stop($network->id);
            foreach ($local as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Where an update goes on a server: the Reef jar the server runs, or the existing Nodes or
     * GinjerMap jar in plugins/ (keeping its name, and its .disabled if it's switched off).
     *
     * @return array{0: string, 1: string, 2: bool} directory, file name, whether it exists
     */
    private function target(DaemonFileRepository $files, Server $server, string $key): array
    {
        if ($key === 'reef') {
            $jar = $server->variables()->get()->firstWhere('env_variable', 'SERVER_JARFILE');
            $name = ($jar?->server_value ?: $jar?->default_value) ?: 'server.jar';
            $exists = collect($files->setServer($server)->getDirectory('/'))->contains(fn ($e) => ($e['name'] ?? '') === $name);

            return ['/', $name, $exists];
        }

        $pattern = $key === 'nodes' ? '/^nodes(?![-_]?map)[^\/]*\.jar(\.disabled)?$/i' : '/^ginjermap[^\/]*\.jar(\.disabled)?$/i';
        $existing = collect($files->setServer($server)->getDirectory('plugins'))
            ->filter(fn ($e) => ($e['file'] ?? true) && preg_match($pattern, $e['name'] ?? ''))
            ->pluck('name')
            ->first();

        return ['/plugins', $existing ?? "$key.jar", $existing !== null];
    }
}
