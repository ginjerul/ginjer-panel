<?php

/**
 * End-to-end checks inside a real Pelican panel, with the Wings daemon faked.
 *
 * Run from the panel's root folder, with the plugin installed (php artisan p:plugin:install ginjer-network):
 *
 *   php plugins/ginjer-network/tests/panel-test.php [path/to/nodes/config.yml]
 *
 * It creates test rows (a node, an egg, servers, a network) and deletes them again at the end.
 */

use App\Models\Allocation;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\EditNetwork;
use Ginjer\Network\Filament\Admin\Resources\Networks\Pages\ListNetworks;
use Ginjer\Network\Filament\Admin\Resources\Networks\RelationManagers\MembersRelationManager;
use Ginjer\Network\Jobs\SendPowerAction;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\NetworkPowerService;
use Ginjer\Network\Services\NodesStatusService;
use Ginjer\Network\Services\ShardConfigService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Yaml\Yaml;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

$nodesConfig = isset($argv[1]) ? file_get_contents($argv[1]) : "sharding:\n  enabled: false\n  role: leader\n  name: main\n  redis:\n    host: 127.0.0.1\n    port: 6379\n    password: \"\"\n    database: 0\n  keyPrefix: nodes\n";
$reefConfig = "optimizations:\n  check-stuck-in-wall-interval: 10\nsharding:\n  enabled: false\n  this-server: server1\n  world: world\n  regions:\n  - {server: server1, min-x: -100000, min-z: -100000, max-x: 0, max-z: 100000}\n";

DB::beginTransaction();
try {
    // ---------------- test rows
    $user = User::forceCreate([
        'uuid' => Uuid::uuid4()->toString(), 'username' => 'gn_test_' . Str::random(6), 'email' => Str::random(12) . '@example.com',
        'password' => bcrypt('password'), 'language' => 'en', 'oauth' => [], 'external_id' => null, 'is_managed_externally' => false,
    ]);
    $user->syncRoles(Role::getRootAdmin());

    $node = Node::forceCreate([
        'uuid' => Uuid::uuid4()->toString(), 'public' => true, 'name' => 'gn-test-node', 'fqdn' => 'wings.test', 'scheme' => 'http',
        'behind_proxy' => false, 'memory' => 1024, 'memory_overallocate' => 0, 'disk' => 10240, 'disk_overallocate' => 0, 'cpu' => 100,
        'cpu_overallocate' => 0, 'upload_size' => 100, 'daemon_token_id' => Str::random(Node::DAEMON_TOKEN_ID_LENGTH),
        'daemon_token' => Str::random(Node::DAEMON_TOKEN_LENGTH), 'daemon_listen' => 8080, 'daemon_connect' => 8080, 'daemon_sftp' => 2022,
        'daemon_base' => '/var/lib/panel/volumes', 'maintenance_mode' => false,
    ]);
    $egg = Egg::forceCreate([
        'uuid' => Uuid::uuid4()->toString(), 'author' => 'test@example.com', 'docker_images' => ['a' => 'a'], 'config_logs' => '{}',
        'config_startup' => '{}', 'config_stop' => '{}', 'config_files' => '{}', 'name' => 'gn-test-egg', 'description' => 'test',
        'startup_commands' => ['Default' => 'java -jar test.jar'],
    ]);

    $makeServer = function (string $name, int $port) use ($user, $node, $egg): Server {
        $allocation = Allocation::forceCreate(['ip' => '10.0.0.' . ($port % 200), 'port' => $port, 'node_id' => $node->id]);
        $server = Server::forceCreate([
            'owner_id' => $user->id, 'node_id' => $node->id, 'allocation_id' => $allocation->id, 'egg_id' => $egg->id,
            'uuid' => Uuid::uuid4()->toString(), 'uuid_short' => Str::lower(Str::random(8)), 'name' => $name, 'description' => '',
            'skip_scripts' => 0, 'status' => null, 'memory' => 512, 'swap' => 0, 'disk' => 512, 'io' => 500, 'cpu' => 0,
            'oom_killer' => false, 'startup' => 'java -jar server.jar', 'image' => 'foo/bar:latest', 'backup_limit' => 0,
        ]);
        $allocation->forceFill(['server_id' => $server->id])->save();

        return $server;
    };
    $redis = $makeServer('gn-redis', 26379);
    $west = $makeServer('gn-west', 25565);
    $east = $makeServer('gn-east', 25566);
    $proxy = $makeServer('gn-proxy', 25577);

    $network = Network::create([
        'name' => 'gn-test-network',
        'world' => 'world',
        'regions' => "- {server: west, min-x: -20000, min-z: -10000, max-x: 0, max-z: 10000}\n- {server: east, center-x: 10000, center-z: 0, radius: 9000}\n",
        'shared_player_data_folder' => '/reef-shared/playerdata',
        'border_protection' => 16,
        'handoff_timeout_seconds' => 5,
        'redis_host' => '127.0.0.1',
        'redis_port' => 1, // nothing listens here: the status must fail cleanly
        'redis_password' => 's3cret',
        'redis_database' => 0,
        'key_prefix' => 'nodes',
    ]);
    $m = fn (Server $s, MemberRole $role, ?string $shard = null, bool $leader = false) => NetworkMember::create(['network_id' => $network->id, 'server_id' => $s->id, 'role' => $role, 'shard_name' => $shard, 'nodes_leader' => $leader]);
    $m($redis, MemberRole::Redis);
    $westMember = $m($west, MemberRole::Shard, 'west', true);
    $eastMember = $m($east, MemberRole::Shard, 'east');
    $m($proxy, MemberRole::Proxy, 'ignored', true);
    $network->refresh();

    // ---------------- model rules
    check('password is stored encrypted', DB::table('ginjer_networks')->where('id', $network->id)->value('redis_password') !== 's3cret' && $network->redis_password === 's3cret');
    check('non-shards lose shard name and leader flag', NetworkMember::where('server_id', $proxy->id)->first()->shard_name === null && !NetworkMember::where('server_id', $proxy->id)->first()->nodes_leader);
    $eastMember->update(['nodes_leader' => true]);
    check('making a second leader unsets the first', !$westMember->fresh()->nodes_leader && $eastMember->fresh()->nodes_leader);
    $westMember->fresh()->update(['nodes_leader' => true]);
    check('making the first leader again unsets the second', $westMember->fresh()->nodes_leader && !$eastMember->fresh()->nodes_leader);
    $network->refresh();
    check('leader() finds the leader', $network->leader()?->shard_name === 'west');
    check('regions parse', count($network->parsedRegions()) === 2);

    // ---------------- power order
    $stages = app(NetworkPowerService::class)->startStages($network)->map(fn ($stage) => $stage->map(fn ($mem) => $mem->server->name)->all())->all();
    check('start order: redis, leader, followers, proxy', $stages === [['gn-redis'], ['gn-west'], ['gn-east'], ['gn-proxy']], json_encode($stages));

    config(['ginjer-network.start_stage_delay' => 10]);
    Queue::fake();
    $count = app(NetworkPowerService::class)->run($network, 'restart');
    $jobs = collect(Queue::pushed(SendPowerAction::class))->map(fn (SendPowerAction $job) => [
        Server::find($job->serverId)->name, $job->action, $job->delay ? now()->diffInSeconds($job->delay, true) : 0,
    ])->sortBy(fn ($j) => $j[2])->values();
    check('restart sends to all 4 servers', $count === 4 && $jobs->count() === 8, $jobs->toJson());
    $stops = $jobs->where(1, 'stop')->values();
    $starts = $jobs->where(1, 'start')->values();
    check('restart stops proxy first and redis last', $stops->pluck(0)->all() === ['gn-proxy', 'gn-east', 'gn-west', 'gn-redis'], $stops->toJson());
    check('restart starts redis first and proxy last', $starts->pluck(0)->all() === ['gn-redis', 'gn-west', 'gn-east', 'gn-proxy'], $starts->toJson());
    check('every start comes after every stop', $starts->min(2) > $stops->max(2), $jobs->toJson());

    // ---------------- the job talks to Wings
    // One fake for the whole run (Laravel checks stubs in order, so a second fake would never be reached).
    $handler = fn (Request $request) => Http::response('', 204);
    Http::fake(function (Request $request) use (&$handler) {
        return $handler($request);
    });
    (new SendPowerAction($west->id, 'start'))->handle(app(\App\Repositories\Daemon\DaemonServerRepository::class));
    $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), "/api/servers/{$west->uuid}/power") && $r['action'] === 'start');
    check('power job posts the action to Wings', count($sent) === 1);

    // ---------------- config push
    check('a good network has no problems', app(ShardConfigService::class)->problems($network) === [], json_encode(app(ShardConfigService::class)->problems($network)));

    $written = [];
    // real Wings signs every response with its token id; Pelican checks it on errors
    $wings = ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$node->daemon_token_id})"];
    $handler = function (Request $request) use (&$written, $reefConfig, $nodesConfig, $east, $wings) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $file = $query['file'] ?? '';
        if (str_contains($request->url(), '/files/contents')) {
            // east has never started: no reef.yml yet
            if (str_contains($request->url(), $east->uuid) && $file === 'reef.yml') {
                return Http::response(['error' => 'not found'], 404, $wings);
            }

            return Http::response($file === 'reef.yml' ? $reefConfig : $nodesConfig, 200, $wings);
        }
        if (str_contains($request->url(), '/files/write')) {
            $written[$request->url()] = $request->body();

            return Http::response('', 204, $wings);
        }

        return Http::response('', 404, $wings);
    };
    $results = app(ShardConfigService::class)->push($network->fresh('members.server'));
    $byServer = collect($results)->keyBy('server');
    check('push reports both shards', $byServer->count() === 2, json_encode($results));
    check('push skips a shard without reef.yml with a hint', str_contains($byServer['gn-east']['message'] ?? '', 'start this shard once'), json_encode($results));

    $file = fn (Server $s, string $name) => collect($written)->first(fn ($body, $url) => str_contains($url, $s->uuid) && str_contains(urldecode($url), "file=$name"));
    $westReef = Yaml::parse($file($west, 'reef.yml') ?? '');
    check('west reef.yml: this-server is west', ($westReef['sharding']['this-server'] ?? null) === 'west', json_encode($westReef));
    check('west reef.yml: regions replaced', ($westReef['sharding']['regions'] ?? null) === $network->parsedRegions());
    check('west reef.yml: other settings kept', ($westReef['optimizations']['check-stuck-in-wall-interval'] ?? null) === 10);
    check('west reef.yml: shared folder and enabled', $westReef['sharding']['enabled'] === true && $westReef['sharding']['shared-player-data-folder'] === '/reef-shared/playerdata');
    $westNodes = Yaml::parse($file($west, 'plugins/nodes/config.yml') ?? '');
    $eastNodes = Yaml::parse($file($east, 'plugins/nodes/config.yml') ?? '');
    check('west nodes config: leader', ($westNodes['sharding']['role'] ?? null) === 'leader' && $westNodes['sharding']['name'] === 'west' && $westNodes['sharding']['enabled'] === true);
    check('east nodes config: follower (written even without reef.yml)', ($eastNodes['sharding']['role'] ?? null) === 'follower' && $eastNodes['sharding']['name'] === 'east');
    check('nodes config: redis password set', ($westNodes['sharding']['redis']['password'] ?? null) === 's3cret');
    check('nothing written to redis or proxy', collect($written)->keys()->filter(fn ($url) => str_contains($url, $redis->uuid) || str_contains($url, $proxy->uuid))->isEmpty());

    // problems block a push
    $network->update(['regions' => "- {server: west, min-x: 0, min-z: 0, max-x: 10, max-z: 10}\n- {server: north, min-x: 10, min-z: 0, max-x: 20, max-z: 10}\n"]);
    $problems = app(ShardConfigService::class)->problems($network->fresh('members.server'));
    check('unknown shard and shard without area block a push', count($problems) === 2, json_encode($problems));

    // ---------------- status without Redis
    $status = app(NodesStatusService::class)->status($network);
    check('status reports Redis down instead of failing', $status['reachable'] === false && $status['error'] !== null, json_encode($status));

    // ---------------- Filament pages render
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    test_as($user);
    $list = Livewire::test(ListNetworks::class);
    check('networks list renders and shows the network', str_contains($list->html(), 'gn-test-network'));
    $edit = Livewire::test(EditNetwork::class, ['record' => $network->getKey()]);
    $html = $edit->html();
    check('edit page renders with its actions', str_contains($html, 'Push config to shards') && str_contains($html, 'Status'));
    $members = Livewire::test(MembersRelationManager::class, ['ownerRecord' => $network, 'pageClass' => EditNetwork::class]);
    check('servers list shows all four servers', collect(['gn-redis', 'gn-west', 'gn-east', 'gn-proxy'])->every(fn ($n) => str_contains($members->html(), $n)));
    check('resource url resolves', str_contains(NetworkResource::getUrl('edit', ['record' => $network]), '/networks/'));

    Queue::fake();
    $edit->call('mountAction', 'power_start');
    $edit->call('callMountedAction');
    check('start button queues 4 power jobs', count(Queue::pushed(SendPowerAction::class)) === 4);
} catch (Throwable $exception) {
    check('no exception', false, get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    DB::rollBack();
}

function test_as(User $user): void
{
    auth()->login($user);
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
