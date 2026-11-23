<?php

namespace Ginjer\Network\Jobs;

use App\Enums\NodeJwtScope;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Nodes\NodeJWTService;
use App\Services\Servers\BuildModificationService;
use App\Services\Servers\ServerCreationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\ShardConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carries out a split plan from SplitPlanner:
 *  1. creates a server for every new shard (a clone of the original: same egg, startup and variables)
 *  2. zips the original's files once and lets every new shard download and unzip them
 *  3. gives the original its planned RAM
 *  4. creates the network and pushes the Reef and Nodes settings to every shard
 *
 * The original must be stopped, so the copied world is consistent. Progress and the result go to
 * the admin's notifications.
 */
class RunSplit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 7200;

    public int $tries = 1;

    /** Top-level files and folders that are never copied to new shards. */
    public const SKIP = ['logs', 'crash-reports', 'debug', 'cache', '.cache'];

    /**
     * @param  array<string, mixed>  $plan  from SplitPlanner::plan(), with the admin's edits
     * @param  array<string, mixed>  $network  name, redis_host, redis_port, redis_password, redis_server_id, proxy_server_id
     */
    public function __construct(public array $plan, public array $network, public int $userId) {}

    public function handle(
        ServerCreationService $creation,
        DaemonServerRepository $daemon,
        DaemonFileRepository $files,
        NodeJWTService $jwt,
        BuildModificationService $build,
        ShardConfigService $config,
    ): void {
        $user = User::findOrFail($this->userId);
        $source = Server::with('node', 'allocation')->findOrFail($this->plan['source_id']);
        $done = [];
        $created = [];
        $archive = null;

        try {
            if (($this->plan['errors'] ?? []) !== [] || collect($this->plan['shards'])->contains(fn ($s) => !$s['source'] && empty($s['node_id']))) {
                throw new \RuntimeException('The plan has problems (' . implode(' ', $this->plan['errors'] ?? ['a shard has no node']) . ') Make a new plan.');
            }
            $state = $daemon->setServer($source)->getState();
            if ($state === null || !in_array($state->value, ['offline', 'missing'], true)) {
                throw new \RuntimeException("Stop {$source->name} first, so its world is copied in a consistent state (it is " . ($state?->value ?? 'not answering') . ').');
            }

            // 1. new servers
            $environment = $source->variables()->get()->mapWithKeys(fn ($v) => [$v->env_variable => $v->server_value ?? $v->default_value])->all();
            foreach ($this->plan['shards'] as $shard) {
                if ($shard['source']) {
                    continue;
                }
                $created[$shard['name']] = $creation->handle([
                    'name' => Str::limit($source->name . ' ' . $shard['name'], 191, ''),
                    'description' => "Reef shard \"{$shard['name']}\" split from {$source->name}.",
                    'owner_id' => $source->owner_id,
                    'egg_id' => $source->egg_id,
                    'startup' => $source->startup,
                    'image' => $source->image,
                    'environment' => $environment,
                    'node_id' => $shard['node_id'],
                    'allocation_id' => $shard['allocation_id'],
                    'memory' => $shard['memory'],
                    'swap' => $source->swap,
                    'disk' => $source->disk,
                    'io' => $source->io,
                    'cpu' => $source->cpu,
                    'threads' => $source->threads,
                    'oom_killer' => $source->oom_killer,
                    'database_limit' => 0,
                    'allocation_limit' => 0,
                    'backup_limit' => $source->backup_limit,
                    'skip_scripts' => true, // everything comes from the original
                    'start_on_completion' => false,
                ]);
            }
            $done[] = 'created ' . count($created) . ' server(s): ' . implode(', ', array_map(fn (Server $s) => $s->name, $created));
            $this->waitUntilInstalled($created);

            // Redis and the proxy, when the wizard asked for them (they install normally)
            $redisSettings = [
                'redis_host' => $this->network['redis_host'] ?? '127.0.0.1',
                'redis_port' => (int) ($this->network['redis_port'] ?? 6379),
                'redis_password' => $this->network['redis_password'] ?? null,
            ];
            $extra = [];
            if (!empty($this->network['create_redis_egg_id'])) {
                $password = Str::random(32);
                $extra['redis'] = $this->createService($creation, $source, (int) $this->network['create_redis_egg_id'], $source->name . ' redis', 512, ['SERVER_PASSWORD' => $password]);
                $allocation = $extra['redis']->allocation;
                $redisSettings = [
                    // the panel and the shards reach Redis on its node's Docker gateway
                    'redis_host' => in_array($allocation->ip, ['0.0.0.0', '::', ''], true) ? (string) config('ginjer-network.docker_gateway', '172.18.0.1') : $allocation->ip,
                    'redis_port' => $allocation->port,
                    'redis_password' => $password,
                ];
                $done[] = "created {$extra['redis']->name} with a generated password";
            }
            if (!empty($this->network['create_proxy_egg_id'])) {
                $proxyMemory = (int) (ceil(max(1024, 512 + 2 * (int) ($this->plan['players'] ?? 100)) / 512) * 512);
                $extra['proxy'] = $this->createService($creation, $source, (int) $this->network['create_proxy_egg_id'], $source->name . ' proxy', $proxyMemory, []);
                $done[] = "created {$extra['proxy']->name}";
            }

            // 2. copy the original's files
            $world = (string) $this->plan['world'];
            $entries = $files->setServer($source)->getDirectory('/');
            $names = collect($entries)
                ->pluck('name')
                ->reject(fn ($name) => in_array($name, self::SKIP, true)
                    || $name === "{$world}_nether" || $name === "{$world}_the_end" // the Nether and the End stay on the leader
                    || str_starts_with($name, 'ginjer-split-'))
                ->values()
                ->all();
            $stamp = now()->format('Ymd-His');
            $result = $files->setServer($source)->compressFiles('/', $names, "ginjer-split-$stamp", 'tar.gz');
            $archive = $result['name'] ?? $result['attributes']['name'] ?? "ginjer-split-$stamp.tar.gz";
            $done[] = "packed {$source->name} into $archive";

            $token = $jwt->setExpiresAt(CarbonImmutable::now()->addHours(2))
                ->setScopes(NodeJwtScope::FileDownload)
                ->setUser($user)
                ->setClaims(['file_path' => '/' . $archive, 'server_uuid' => $source->uuid])
                ->handle($source->node, $user->id . $source->uuid . $stamp);
            $url = $source->node->getConnectionAddress() . '/download/file?token=' . $token->toString();

            foreach ($created as $name => $server) {
                $this->pull($server, $url, $archive);
                $files->setServer($server)->decompressFile('/', $archive);
                $files->setServer($server)->deleteFiles('/', [$archive]);
                $done[] = "copied the files to {$server->name}";
            }
            $files->setServer($source)->deleteFiles('/', [$archive]);
            $archive = null;

            // 3. the original's RAM
            $sourcePlan = collect($this->plan['shards'])->firstWhere('source', true);
            if ($sourcePlan && (int) $sourcePlan['memory'] !== (int) $source->memory) {
                $build->handle($source, [
                    'memory' => (int) $sourcePlan['memory'],
                    // BuildModificationService resets these unless they're passed
                    'database_limit' => $source->database_limit,
                    'allocation_limit' => $source->allocation_limit,
                    'backup_limit' => $source->backup_limit,
                ]);
                $done[] = "gave {$source->name} {$sourcePlan['memory']} MB";
            }

            // 4. the network
            $network = Network::create([
                'name' => $this->network['name'],
                'description' => "Split from {$source->name}.",
                'world' => $world,
                'regions' => collect($this->plan['shards'])->map(fn ($s) => '- {server: ' . $s['name'] . ', min-x: ' . $s['region']['min-x'] . ', min-z: ' . $s['region']['min-z'] . ', max-x: ' . $s['region']['max-x'] . ', max-z: ' . $s['region']['max-z'] . '}')->implode("\n") . "\n",
            ] + $redisSettings);
            foreach ($this->plan['shards'] as $shard) {
                NetworkMember::create([
                    'network_id' => $network->id,
                    'server_id' => $shard['source'] ? $source->id : $created[$shard['name']]->id,
                    'role' => MemberRole::Shard,
                    'shard_name' => $shard['name'],
                    'nodes_leader' => $shard['leader'],
                ]);
            }
            foreach (['redis' => MemberRole::Redis, 'proxy' => MemberRole::Proxy] as $key => $role) {
                $serverId = isset($extra[$key]) ? $extra[$key]->id : (int) ($this->network[$key . '_server_id'] ?? 0);
                if ($serverId > 0) {
                    NetworkMember::create(['network_id' => $network->id, 'server_id' => $serverId, 'role' => $role]);
                }
            }
            $done[] = "created the network {$network->name}";

            // a new proxy and Redis: start them so Velocity writes its files, then configure it
            if ($extra !== []) {
                $this->waitUntilInstalled($extra, 10);
                $lifecycle = app(\Ginjer\Network\Services\Ops\ServerLifecycle::class);
                foreach ($extra as $server) {
                    $lifecycle->start($server);
                }
                if (isset($extra['proxy']) && $this->waitForFiles($files, $extra['proxy'], ['velocity.toml', 'forwarding.secret'])) {
                    $done[] = 'started Redis and the proxy';
                }
            }

            $pushed = $config->push($network->load('members.server'));
            if (isset($extra['proxy'])) {
                app(\Ginjer\Network\Services\Ops\ServerLifecycle::class)->restart($extra['proxy']->fresh());
                $done[] = 'configured the proxy (players join at ' . $extra['proxy']->allocation->address . ')';
            }
            $skipped = collect($pushed)->where('complete', false);

            Notification::make()
                ->title("Split {$source->name} into " . count($this->plan['shards']) . ' shards')
                ->body('Done: ' . implode('; ', $done) . '.' . ($skipped->isEmpty() ? '' : ' Config notes: ' . $skipped->map(fn ($r) => "{$r['server']}: {$r['message']}")->implode(' ')))
                ->color($skipped->isEmpty() ? 'success' : 'warning')
                ->actions([Action::make('open')->label('Open network')->url(NetworkResource::getUrl('edit', ['record' => $network], panel: 'admin'))])
                ->sendToDatabase($user);
        } catch (Throwable $exception) {
            if ($archive !== null) {
                rescue(fn () => $files->setServer($source)->deleteFiles('/', [$archive]), report: false);
            }
            report($exception);

            Notification::make()
                ->title("Splitting {$source->name} failed")
                ->body($this->explain($exception) . ($done === [] ? ' Nothing was changed.' : ' Already done: ' . implode('; ', $done) . '. Delete the new servers if you want to start over.'))
                ->danger()
                ->sendToDatabase($user);
        }
    }

    /** Wings downloads the archive into the new shard. Can take a while for a big world, so this waits up to an hour. */
    private function pull(Server $server, string $url, string $archive): void
    {
        Http::daemon($server->node)
            ->timeout(3600)
            ->post("/api/servers/{$server->uuid}/files/pull", [
                'url' => $url,
                'root' => '/',
                'file_name' => $archive,
                'foreground' => true,
            ])
            ->throw();
    }

    /**
     * Creates Redis or the proxy on the original's node from an installed egg, with the egg's
     * defaults plus $environment, on a free port.
     *
     * @param  array<string, string>  $environment
     */
    private function createService(ServerCreationService $creation, Server $source, int $eggId, string $name, int $memory, array $environment): Server
    {
        $egg = \App\Models\Egg::with('variables')->findOrFail($eggId);
        $env = [];
        foreach ($egg->variables as $variable) {
            $env[$variable->env_variable] = $environment[$variable->env_variable] ?? $variable->default_value ?? '';
            if ($env[$variable->env_variable] === '' && $variable->env_variable === 'USER_AGENT') {
                $env['USER_AGENT'] = 'ginjer-network'; // the Velocity egg requires one
            }
        }
        $allocation = \App\Models\Allocation::query()
            ->where('node_id', $source->node_id)
            ->whereNull('server_id')
            ->orderByRaw('CASE WHEN ip = ? THEN 0 ELSE 1 END', [$source->allocation?->ip ?? ''])
            ->orderBy('port')
            ->first();
        if ($allocation === null) {
            throw new \RuntimeException("No free port on {$source->node->name} for $name. Add allocations to the node.");
        }

        return $creation->handle([
            'name' => Str::limit($name, 191, ''),
            'description' => "Created by the Ginjer Network split of {$source->name}.",
            'owner_id' => $source->owner_id,
            'egg_id' => $egg->id,
            'environment' => $env,
            'node_id' => $source->node_id,
            'allocation_id' => $allocation->id,
            'memory' => $memory,
            'swap' => 0,
            'disk' => 5120,
            'io' => 500,
            'cpu' => 0,
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => $source->backup_limit,
            'start_on_completion' => false,
        ]);
    }

    /** @param  list<string>  $paths */
    private function waitForFiles(DaemonFileRepository $files, Server $server, array $paths, int $seconds = 120): bool
    {
        for ($waited = 0; $waited <= $seconds; $waited += 3) {
            $missing = array_filter($paths, function ($path) use ($files, $server) {
                try {
                    $files->setServer($server)->getContent($path);

                    return false;
                } catch (\Throwable) {
                    return true;
                }
            });
            if ($missing === []) {
                return true;
            }
            \Ginjer\Network\Services\Ops\ServerLifecycle::sleep(3);
        }

        return false;
    }

    /** @param  array<string, Server>  $servers */
    private function waitUntilInstalled(array $servers, int $minutes = 5): void
    {
        $deadline = now()->addMinutes($minutes);
        foreach ($servers as $server) {
            while (!$server->refresh()->isInstalled() && now()->lt($deadline)) {
                \Ginjer\Network\Services\Ops\ServerLifecycle::sleep(2);
            }
            if (!$server->isInstalled()) {
                throw new \RuntimeException("{$server->name} didn't finish setting up within $minutes minutes.");
            }
        }
    }

    private function explain(Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'internal network location')) {
            return 'Wings refused to download the copy because the node\'s address is a private one. '
                . 'Add that address under api.remote_download.allowlist in the Wings config.yml of the nodes with new shards, restart Wings, and try again. (' . $message . ')';
        }

        return $message;
    }
}
