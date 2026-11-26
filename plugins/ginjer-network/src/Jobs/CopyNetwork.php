<?php

namespace Ginjer\Network\Jobs;

use App\Enums\NodeJwtScope;
use App\Models\Allocation;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Nodes\NodeJWTService;
use App\Services\Servers\ServerCreationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\Activity\NetworkLog;
use Ginjer\Network\Services\Ops\ServerLifecycle;
use Ginjer\Network\Services\ShardConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * A private test copy of a network: a new server for each of its servers (proxy, shards, Redis)
 * with a copy of its files as they are now, joined in a new network with its own Redis, so
 * updates and plugin changes can be tried there first. Nothing on the real network changes and
 * the copy is left stopped.
 */
class CopyNetwork implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    public const SKIP = ['logs', 'crash-reports', 'debug', 'cache', '.cache'];

    /** @param  array{name: string, memory_percent: int, rehearse?: string}  $options  rehearse: new borders to rebalance the copy to once it's made */
    public function __construct(public int $networkId, public array $options, public int $userId) {}

    public function handle(ServerCreationService $creation, DaemonFileRepository $files, NodeJWTService $jwt, ShardConfigService $config): void
    {
        $source = Network::with('members.server.node', 'members.server.allocation')->findOrFail($this->networkId);
        $user = User::findOrFail($this->userId);
        $percent = max(10, min(100, (int) ($this->options['memory_percent'] ?? 50)));
        $created = [];
        $done = [];

        try {
            // everything saved to disk first, so the copy is consistent enough to start
            foreach ($source->membersWithRole(MemberRole::Shard) as $shard) {
                rescue(fn () => $shard->server->send(array_values(array_filter([$shard->nodes_leader ? 'nodesadmin save' : null, 'save-all flush']))), report: false);
            }
            ServerLifecycle::sleep(10);

            $copies = [];
            foreach ($source->members as $member) {
                $original = $member->server;
                $copy = $this->createLike($creation, $original, $percent, $this->options['name']);
                $created[] = $copy;
                $copies[$member->id] = $copy;
            }
            $this->waitUntilInstalled($created);
            $done[] = 'created ' . count($created) . ' server(s)';

            foreach ($source->members as $member) {
                $this->copyFiles($files, $jwt, $user, $member->server, $copies[$member->id]);
            }
            $done[] = 'copied their files';

            $redis = $source->membersWithRole(MemberRole::Redis)->first();
            $redisCopy = $redis ? $copies[$redis->id]->load('allocation') : null;
            $attributes = collect($source->getAttributes())->except([
                'id', 'created_at', 'updated_at', 'database_id', 'discord_webhook', 'last_backup_at', 'last_daily_restart',
            ])->all();
            $network = new Network();
            $network->forceFill($attributes);
            $network->fill([
                'name' => $this->options['name'],
                'description' => "Test copy of {$source->name}, made " . now()->toDateString() . '.',
                'test_of_network_id' => $source->id,
                'health_alerts' => false,
                'restart_on_lag' => false,
                'backup_every_hours' => null,
                'restart_daily_at' => null,
                'maintenance' => false,
                'discord_level' => 'off',
            ]);
            if ($redisCopy) {
                $ip = $redisCopy->allocation->ip;
                $network->redis_host = in_array($ip, ['0.0.0.0', '::', ''], true) ? (string) config('ginjer-network.docker_gateway', '172.18.0.1') : $ip;
                $network->redis_port = $redisCopy->allocation->port;
            }
            $network->save();
            foreach ($source->members as $member) {
                NetworkMember::create(['network_id' => $network->id, 'server_id' => $copies[$member->id]->id, 'role' => $member->role,
                    'shard_name' => $member->shard_name, 'nodes_leader' => $member->nodes_leader]);
            }
            $pushed = collect($config->push($network->load('members.server')));
            $done[] = 'pointed the copies at each other and their own Redis';

            // the copy must never write to the real network's database
            $warnings = [];
            if ($source->database_id && ($real = \Ginjer\Network\Services\Ops\NetworkDatabase::database($source))) {
                try {
                    $databases = app(\Ginjer\Network\Services\Ops\NetworkDatabase::class);
                    $databases->create($network, $real->database_host_id);
                    $databases->setUpLuckPerms($network->refresh());
                    $done[] = 'gave it its own empty database (LuckPerms points at it)';
                } catch (Throwable $exception) {
                    $warnings[] = "Couldn't make it its own database ({$exception->getMessage()}): LuckPerms on the copy still points at the real one. Change that before starting it.";
                }
                $warnings[] = 'Plugins you pointed at the network database by hand still use the real one on the copy: change them before starting it.';
            }
            if (filled($source->shared_player_data_folder)) {
                $warnings[] = 'The shared player-data folder is a mount the copies don\'t have: give them their own mount (never the real network\'s), or inventories won\'t follow players across borders in the test.';
            }

            if (filled($this->options['rehearse'] ?? null)) {
                RebalanceNetwork::dispatch($network->id, $this->options['rehearse'], $user->id);
                $done[] = 'started rebalancing the copy to the proposed borders (a second notification follows)';
            }
            NetworkLog::record($source, 'copy', "Test copy \"{$network->name}\" made", implode('; ', $done), 'info', $user->id);
            Notification::make()
                ->title("Test copy of {$source->name} is ready")
                ->body('Done: ' . implode('; ', $done) . '. It\'s stopped: start it from its network page. ' . implode(' ', $warnings) . ($pushed->where('ok', false)->isEmpty() ? '' : ' Config push problems: ' . $pushed->where('ok', false)->map(fn ($r) => "{$r['server']}: {$r['message']}")->implode(' ')))
                ->color($warnings === [] ? 'success' : 'warning')
                ->persistent()
                ->actions([Action::make('open')->label('Open the copy')->url(NetworkResource::getUrl('edit', ['record' => $network], panel: 'admin'))])
                ->sendToDatabase($user);
        } catch (Throwable $exception) {
            report($exception);
            NetworkLog::record($source, 'copy', 'Test copy failed', $exception->getMessage(), 'warning', $user->id);
            Notification::make()
                ->title("Copying {$source->name} failed")
                ->body($exception->getMessage() . ($created === [] ? ' Nothing was created.' : ' Created so far (delete them if you don\'t need them): ' . implode(', ', array_map(fn (Server $s) => $s->name, $created)) . '.'))
                ->danger()
                ->sendToDatabase($user);
        }
    }

    private function createLike(ServerCreationService $creation, Server $original, int $percent, string $prefix): Server
    {
        $allocation = Allocation::query()->where('node_id', $original->node_id)->whereNull('server_id')
            ->orderByRaw('CASE WHEN ip = ? THEN 0 ELSE 1 END', [$original->allocation?->ip ?? ''])->orderBy('port')->first()
            ?? throw new \RuntimeException("No free port on {$original->node->name} for the copy of {$original->name}. Add allocations to the node.");
        $environment = $original->variables()->get()->mapWithKeys(fn ($v) => [$v->env_variable => $v->server_value ?? $v->default_value])->all();

        return $creation->handle([
            'name' => Str::limit("$prefix: {$original->name}", 191, ''),
            'description' => "Test copy of {$original->name}.",
            'owner_id' => $original->owner_id,
            'egg_id' => $original->egg_id,
            'startup' => $original->startup,
            'image' => $original->image,
            'environment' => $environment,
            'node_id' => $original->node_id,
            'allocation_id' => $allocation->id,
            'memory' => (int) max(512, ceil($original->memory * $percent / 100 / 256) * 256),
            'swap' => $original->swap,
            'disk' => $original->disk,
            'io' => $original->io,
            'cpu' => $original->cpu,
            'threads' => $original->threads,
            'oom_killer' => $original->oom_killer,
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => 0,
            'skip_scripts' => true,
            'start_on_completion' => false,
        ]);
    }

    private function copyFiles(DaemonFileRepository $files, NodeJWTService $jwt, User $user, Server $from, Server $to): void
    {
        $names = collect($files->setServer($from)->getDirectory('/'))->pluck('name')
            ->reject(fn ($name) => in_array($name, self::SKIP, true) || str_starts_with((string) $name, 'ginjer-copy-'))
            ->values()->all();
        if ($names === []) {
            return;
        }
        $stamp = now()->format('Ymd-His') . '-' . Str::lower(Str::random(4));
        $result = $files->setServer($from)->compressFiles('/', $names, "ginjer-copy-$stamp", 'tar.gz');
        $archive = $result['name'] ?? $result['attributes']['name'] ?? "ginjer-copy-$stamp.tar.gz";
        try {
            $token = $jwt->setExpiresAt(CarbonImmutable::now()->addHours(2))
                ->setScopes(NodeJwtScope::FileDownload)
                ->setUser($user)
                ->setClaims(['file_path' => '/' . $archive, 'server_uuid' => $from->uuid])
                ->handle($from->node, $user->id . $from->uuid . $stamp);
            Http::daemon($to->node)->timeout(3600)->post("/api/servers/{$to->uuid}/files/pull", [
                'url' => $from->node->getConnectionAddress() . '/download/file?token=' . $token->toString(),
                'root' => '/',
                'file_name' => $archive,
                'foreground' => true,
            ])->throw();
            $files->setServer($to)->decompressFile('/', $archive);
            $files->setServer($to)->deleteFiles('/', [$archive]);
        } finally {
            rescue(fn () => $files->setServer($from)->deleteFiles('/', [$archive]), report: false);
        }
    }

    /** @param  list<Server>  $servers */
    private function waitUntilInstalled(array $servers, int $minutes = 5): void
    {
        $deadline = now()->addMinutes($minutes);
        foreach ($servers as $server) {
            while (!$server->refresh()->isInstalled() && now()->lt($deadline)) {
                ServerLifecycle::sleep(2);
            }
            if (!$server->isInstalled()) {
                throw new \RuntimeException("{$server->name} didn't finish setting up within $minutes minutes.");
            }
        }
    }
}
