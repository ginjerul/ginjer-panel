<?php

/**
 * End-to-end checks for "Split a server into shards", inside a real panel with Wings faked.
 *
 *   cd <panel> && GINJER_EGG=<repo>/eggs/egg-reef-shard.yaml php plugins/ginjer-network/tests/split-panel-test.php <world.json> <towns.json> [nodes config.yml]
 *
 * Everything runs in a database transaction that is rolled back at the end.
 */

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Jobs\RunSplit;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\RegionList;
use Ginjer\Network\Services\Split\SplitPlanner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Yaml\Yaml;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// notifications are queued; run them at once so the checks can see them
config(['queue.default' => 'sync']);

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

$worldJson = file_get_contents($argv[1]);
$townsJson = file_get_contents($argv[2]);
$nodesConfig = isset($argv[3]) ? file_get_contents($argv[3]) : "sharding:\n  enabled: false\n  role: leader\n  name: main\n  redis:\n    host: 127.0.0.1\n    port: 6379\n    password: \"\"\n    database: 0\n  keyPrefix: nodes\n";
$reefConfig = "optimizations:\n  check-stuck-in-wall-interval: 10\nsharding:\n  enabled: false\n";

DB::beginTransaction();
try {
    $user = User::forceCreate(['uuid' => Uuid::uuid4()->toString(), 'username' => 'split_' . Str::random(6), 'email' => Str::random(10) . '@example.com',
        'password' => bcrypt('x'), 'language' => 'en', 'oauth' => [], 'external_id' => null, 'is_managed_externally' => false]);
    $user->syncRoles(Role::getRootAdmin());
    $makeNode = fn (string $name, int $memory) => Node::forceCreate(['uuid' => Uuid::uuid4()->toString(), 'public' => true, 'name' => $name, 'fqdn' => "$name.test", 'scheme' => 'http',
        'behind_proxy' => false, 'memory' => $memory, 'memory_overallocate' => 0, 'disk' => 500000, 'disk_overallocate' => 0, 'cpu' => 0, 'cpu_overallocate' => 0,
        'upload_size' => 100, 'daemon_token_id' => Str::random(Node::DAEMON_TOKEN_ID_LENGTH), 'daemon_token' => Str::random(Node::DAEMON_TOKEN_LENGTH),
        'daemon_listen' => 8080, 'daemon_connect' => 8080, 'daemon_sftp' => 2022, 'daemon_base' => '/var/lib/pelican/volumes', 'maintenance_mode' => false]);
    $node = $makeNode('split-node', 32768);
    $small = $makeNode('small-node', 4096);
    foreach ([[$node, range(25565, 25570)], [$small, [30000]]] as [$n, $ports]) {
        foreach ($ports as $port) {
            Allocation::forceCreate(['ip' => '10.1.0.1', 'port' => $port, 'node_id' => $n->id]);
        }
    }
    $egg = app(App\Services\Eggs\Sharing\EggImporterService::class)->fromContent(file_get_contents(getenv('GINJER_EGG') ?: __DIR__ . '/../../../eggs/egg-reef-shard.yaml'));

    $sourceAllocation = Allocation::where('node_id', $node->id)->where('port', 25565)->first();
    $source = Server::forceCreate(['owner_id' => $user->id, 'node_id' => $node->id, 'allocation_id' => $sourceAllocation->id, 'egg_id' => $egg->id,
        'uuid' => Uuid::uuid4()->toString(), 'uuid_short' => Str::lower(Str::random(8)), 'name' => 'Ginjer Main', 'description' => '',
        'skip_scripts' => 0, 'status' => null, 'memory' => 6144, 'swap' => 0, 'disk' => 30000, 'io' => 500, 'cpu' => 0,
        'oom_killer' => false, 'startup' => 'java -jar {{SERVER_JARFILE}}', 'image' => 'ghcr.io/pelican-eggs/yolks:java_21',
        'backup_limit' => 3, 'database_limit' => 2, 'allocation_limit' => 1]);
    $sourceAllocation->forceFill(['server_id' => $source->id])->save();
    $velocitySecret = $egg->variables()->where('env_variable', 'VELOCITY_SECRET')->first();
    DB::table('server_variables')->insert(['server_id' => $source->id, 'variable_id' => $velocitySecret->id, 'variable_value' => 'the-secret']);

    // ---------------- fake Wings
    $calls = [];
    $state = 'offline';
    $Wings = fn (Node $n) => ['User-Agent' => "Pelican Wings/v1.0.0 (id:{$n->daemon_token_id})"];
    Http::fake(function (Request $request) use (&$calls, &$state, $worldJson, $townsJson, $nodesConfig, $reefConfig, $node, $Wings) {
        $url = $request->url();
        $path = parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $headers = $Wings($node); // all test servers' tokens checked against their own node; both nodes accept this in the fake
        $calls[] = [$request->method(), $path, $query, $request->data()];
        $ok = fn ($body = '', $status = 200) => Http::response($body, $status, $headers);

        return match (true) {
            str_ends_with($path, '/files/contents') => match ($query['file'] ?? '') {
                'plugins/nodes/world.json' => $ok($worldJson),
                'plugins/nodes/towns.json' => $ok($townsJson),
                'reef.yml' => $ok($reefConfig),
                'plugins/nodes/config.yml' => $ok($nodesConfig),
                default => $ok(['error' => 'not found'], 404),
            },
            str_ends_with($path, '/files/list-directory') => $ok(array_map(fn ($n) => ['name' => $n], ['world', 'world_nether', 'world_the_end', 'plugins', 'logs', 'server.jar', 'reef.yml', 'server.properties', 'cache'])),
            str_ends_with($path, '/files/compress') => $ok(['name' => 'ginjer-split-test.tar.gz', 'size' => 123]),
            preg_match('#^/api/servers/[0-9a-f-]{36}$#', $path) === 1 && $request->method() === 'GET' => $ok(['state' => $state]),
            default => $ok('', 204),
        };
    });
    // Wings reports a skip-scripts install as finished almost at once; do the same here
    Server::created(function (Server $server) {
        $server->forceFill(['status' => null])->saveQuietly();
    });

    // ---------------- the plan
    $planner = app(SplitPlanner::class);
    $plan = $planner->plan($source->fresh(['node', 'allocation']), 300, null, [$node->id]);
    $shards = collect($plan['shards']);
    check('plan: 300 players -> 3 shards', count($plan['shards']) === 3, json_encode($plan['shards']));
    check('plan: balanced by Nodes data', $plan['stats']['from_nodes'] && $plan['stats']['towns'] > 0);
    check('plan: no errors, no warnings', $plan['errors'] === [] && $plan['warnings'] === [], json_encode([$plan['errors'], $plan['warnings']]));
    check('plan: exactly one source, and it is the leader', $shards->where('source', true)->count() === 1 && $shards->firstWhere('source', true)['leader'] === true);
    $src = $shards->firstWhere('source', true);
    check('plan: the source keeps spawn (0, 0)', $src['region']['min-x'] <= 0 && 0 < $src['region']['max-x'] && $src['region']['min-z'] <= 0 && 0 < $src['region']['max-z']);
    check('plan: source keeps its own port', $src['allocation_id'] === $sourceAllocation->id);
    $new = $shards->where('source', false);
    check('plan: new shards get distinct free ports on the node', $new->pluck('allocation_id')->filter()->unique()->count() === 2 && $new->every(fn ($s) => $s['node_id'] === $node->id));
    check('plan: leader has more RAM than an equal follower would', $src['memory'] >= 2048 + 48 * $src['players'] + 1536);
    check('plan: RAM in 512 MB steps', $shards->every(fn ($s) => $s['memory'] % 512 === 0));
    $names = $shards->pluck('name')->all();
    $yaml = $shards->map(fn ($s) => '- {server: ' . $s['name'] . ', min-x: ' . $s['region']['min-x'] . ', min-z: ' . $s['region']['min-z'] . ', max-x: ' . $s['region']['max-x'] . ', max-z: ' . $s['region']['max-z'] . '}')->implode("\n");
    check('plan: regions pass Reef\'s rules', RegionList::problems($yaml, $names) === [], implode('; ', RegionList::problems($yaml, $names)));

    $tooSmall = $planner->plan($source->fresh(['node', 'allocation']), 300, 3, [$small->id]);
    check('plan: a node without room is an error, not a silent overcommit', count($tooSmall['errors']) >= 1, json_encode($tooSmall['errors']));
    $few = $planner->plan($source->fresh(['node', 'allocation']), 60, null, [$node->id]);
    check('plan: few players -> 2 shards and a warning', count($few['shards']) === 2 && str_contains(implode(' ', $few['warnings']), 'one server is usually simpler'));
    check('plan: two shards get direction names', in_array($few['shards'][0]['name'], ['west', 'east', 'north', 'south'], true), json_encode(array_column($few['shards'], 'name')));

    // ---------------- running it: the source must be stopped
    $state = 'running';
    $before = Server::count();
    app()->call([new RunSplit($plan, ['name' => 'Split Net', 'redis_host' => '10.0.0.9', 'redis_port' => 6379, 'redis_password' => 'pw'], $user->id), 'handle']);
    check('running source: nothing created', Server::count() === $before && Network::where('name', 'Split Net')->doesntExist());
    $note = DB::table('notifications')->where('notifiable_id', $user->id)->latest()->first();
    check('running source: the admin is told to stop it', $note && str_contains($note->data, 'Stop Ginjer Main first'), (string) $note?->data);

    // ---------------- running it for real
    $state = 'offline';
    $calls = [];
    app()->call([new RunSplit($plan, ['name' => 'Split Net', 'redis_host' => '10.0.0.9', 'redis_port' => 6379, 'redis_password' => 'pw'], $user->id), 'handle']);
    $network = Network::with('members.server')->where('name', 'Split Net')->first();
    $notes = DB::table('notifications')->where('notifiable_id', $user->id)->pluck('data');
    check('split: finished with a success notification', $notes->count() === 2 && $notes->contains(fn ($d) => str_contains($d, 'into 3 shards') && str_contains($d, '"color":"success"')), $notes->implode(' | '));
    check('split: network created', $network !== null);
    if ($network) {
        $members = $network->members;
        check('split: 3 shard members, one leader (the source)', $members->where('role', MemberRole::Shard)->count() === 3
            && $members->where('nodes_leader', true)->count() === 1 && $members->firstWhere('nodes_leader', true)->server_id === $source->id);
        $newServers = $members->where('server_id', '!=', $source->id)->map->server;
        check('split: 2 new servers, cloned from the source', $newServers->count() === 2 && $newServers->every(fn ($s) => $s->egg_id === $source->egg_id && $s->image === $source->image && $s->startup === $source->startup));
        check('split: new servers have the planned RAM and ports', $newServers->every(function ($s) use ($plan, $members) {
            $name = $members->firstWhere('server_id', $s->id)->shard_name;
            $p = collect($plan['shards'])->firstWhere('name', $name);

            return $s->memory === $p['memory'] && $s->allocation_id === $p['allocation_id'];
        }));
        check('split: egg variables copied (Velocity secret)', $newServers->every(fn ($s) => DB::table('server_variables')->where('server_id', $s->id)->where('variable_id', $velocitySecret->id)->value('variable_value') === 'the-secret'));
        check('split: install scripts skipped', $newServers->every(fn ($s) => (bool) $s->skip_scripts));
        $fresh = $source->fresh();
        check('split: source got its planned RAM', $fresh->memory === $src['memory'], "$fresh->memory vs {$src['memory']}");
        check('split: source keeps its database, allocation and backup limits', $fresh->database_limit === 2 && $fresh->allocation_limit === 1 && $fresh->backup_limit === 3,
            json_encode([$fresh->database_limit, $fresh->allocation_limit, $fresh->backup_limit]));
        check('split: network has the Redis settings', $network->redis_host === '10.0.0.9' && $network->redis_password === 'pw');
        check('split: network regions are the plan\'s', count($network->parsedRegions()) === 3);
    }

    $compress = collect($calls)->first(fn ($c) => str_ends_with($c[1], '/files/compress'));
    $copied = $compress[3]['files'] ?? [];
    check('copy: world, plugins and configs are packed', !array_diff(['world', 'plugins', 'server.jar', 'reef.yml', 'server.properties'], $copied), json_encode($copied));
    check('copy: Nether, End, logs and cache stay behind', !array_intersect(['world_nether', 'world_the_end', 'logs', 'cache'], $copied), json_encode($copied));
    $pulls = collect($calls)->filter(fn ($c) => str_ends_with($c[1], '/files/pull'));
    check('copy: both new shards download the archive', $pulls->count() === 2);
    check('copy: through a signed link on the source node', $pulls->every(fn ($c) => str_starts_with($c[3]['url'] ?? '', 'http://split-node.test:8080/download/file?token=') && ($c[3]['foreground'] ?? false) === true));
    check('copy: each new shard unpacks and deletes it', collect($calls)->filter(fn ($c) => str_ends_with($c[1], '/files/decompress'))->count() === 2
        && collect($calls)->filter(fn ($c) => str_ends_with($c[1], '/files/delete'))->count() === 3);
    $writes = collect($calls)->filter(fn ($c) => str_ends_with($c[1], '/files/write'))->map(fn ($c) => $c[2]['file'] ?? '');
    check('config: reef.yml and Nodes config written on all 3 shards', $writes->filter(fn ($f) => $f === 'reef.yml')->count() === 3 && $writes->filter(fn ($f) => $f === 'plugins/nodes/config.yml')->count() === 3, $writes->toJson());
} catch (Throwable $exception) {
    check('no exception', false, get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    DB::rollBack();
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
