<?php

/**
 * End-to-end checks for the network operations (plugins on every shard, rolling updates, health,
 * network backups) inside a real panel, against a fake Wings that keeps state: power actions
 * change a server's state, and files can be listed, uploaded, renamed and deleted.
 *
 *   cd <panel> && php plugins/ginjer-network/tests/ops-panel-test.php
 *
 * Runs in a database transaction that is rolled back at the end.
 */

use App\Models\Allocation;
use App\Models\Backup;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Jobs\RollingUpdate;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkBackup;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Services\Health\NetworkHealth;
use Ginjer\Network\Services\NetworkPlugins;
use Ginjer\Network\Services\Ops\Busy;
use Ginjer\Network\Services\Ops\NetworkBackups;
use Ginjer\Network\Services\Ops\ServerLifecycle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['queue.default' => 'sync', 'cache.default' => 'array', 'backups.throttles.limit' => 100]); // the test takes several backups in a row
ServerLifecycle::$sleep = fn (int $s) => null; // never really wait

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " -- $detail") . "\n";
    $failures += $ok ? 0 : 1;
}

// ---------------- a fake Wings with state
$fs = [];      // uuid => [path => content]
$state = [];   // uuid => running|offline
$log = [];     // what happened, in order: "power west stop", "upload west plugins/nodes-0.1.jar.ginjer-new", ...
$names = [];   // uuid => short name for the log
$failUpload = false;
Http::fake(function (Request $request) use (&$fs, &$state, &$log, &$names, &$failUpload) {
    $url = $request->url();
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $ok = fn ($body = '', $code = 200) => Http::response($body, $code, ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . ($GLOBALS['tokenId'] ?? '') . ')']);

    // downloads from GitHub
    if (str_contains($url, 'github.com/ginjerul/reef/releases/download/dev-main/reef.jar')) {
        return Http::response('NEW-REEF', 200);
    }
    if (str_starts_with($url, 'https://api.github.com/repos/ginjerul/ginjer-nodes/releases/tags/dev-master')) {
        if (($request->header('Authorization')[0] ?? '') !== 'Bearer ghp_test') {
            return Http::response(['message' => 'Not Found'], 404);
        }

        return Http::response(['assets' => [
            ['name' => 'nodes.jar', 'url' => 'https://api.github.com/repos/ginjerul/ginjer-nodes/releases/assets/1'],
            ['name' => 'ginjermap.jar', 'url' => 'https://api.github.com/repos/ginjerul/ginjer-nodes/releases/assets/2'],
        ]], 200);
    }
    if (str_starts_with($url, 'https://api.github.com/repos/ginjerul/ginjer-nodes/releases/assets/')) {
        return Http::response(str_ends_with($url, '/1') ? 'NEW-NODES' : 'NEW-MAP', 200);
    }

    // Modrinth
    if (str_starts_with($url, 'https://api.modrinth.com/v2/project/chunky/version')) {
        return Http::response(($q['loaders'] ?? '') === '["paper"]' ? [
            ['version_number' => '1.4.40', 'files' => [['primary' => true, 'filename' => 'Chunky-Bukkit-1.4.40.jar', 'url' => 'https://cdn.modrinth.com/data/fALzjamp/versions/x/Chunky-Bukkit-1.4.40.jar']]],
            ['version_number' => '1.4.36', 'files' => [['primary' => true, 'filename' => 'Chunky-Bukkit-1.4.36.jar', 'url' => 'https://cdn.modrinth.com/old.jar']]],
        ] : [], 200);
    }

    // Wings
    if ($path === '/upload/file') {
        if ($failUpload) {
            return $ok(['error' => 'disk full'], 500);
        }
        $claims = json_decode(base64_decode(strtr(explode('.', $q['token'])[1], '-_', '+/')), true);
        $uuid = $claims['server_uuid'];
        preg_match('/filename="([^"]+)"/', $request->body(), $m);
        $body = $request->body();
        $content = trim(substr($body, strpos($body, "\r\n\r\n") + 4, strrpos($body, "\r\n--") - strpos($body, "\r\n\r\n") - 4));
        $file = trim(($q['directory'] ?? '/') . '/' . $m[1], '/');
        $fs[$uuid][$file] = $content;
        $log[] = "upload {$names[$uuid]} $file";

        return $ok('', 200);
    }
    if (!preg_match('#^/api/servers/([0-9a-f-]{36})(/.*)?$#', $path, $m)) {
        return $ok('', 204);
    }
    $uuid = $m[1];
    $rest = $m[2] ?? '';
    $data = $request->data();
    switch (true) {
        case $rest === '' && $request->method() === 'GET':
            return $ok(['state' => $state[$uuid] ?? 'offline', 'utilization' => ['memory_bytes' => 3 * 1073741824, 'memory_limit_bytes' => 8 * 1073741824, 'cpu_absolute' => 87.5, 'uptime' => 3600000]]);
        case $rest === '/power':
            $log[] = "power {$names[$uuid]} {$data['action']}";
            $state[$uuid] = in_array($data['action'], ['start', 'restart'], true) ? 'running' : 'offline';

            return $ok('', 204);
        case $rest === '/commands':
            $log[] = "command {$names[$uuid]} " . implode(' + ', $data['commands']);

            return $ok('', 204);
        case $rest === '/files/list-directory':
            $dir = trim($q['directory'] ?? '/', '/');
            $out = [];
            foreach (array_keys($fs[$uuid] ?? []) as $file) {
                if (dirname($file) === ($dir === '' ? '.' : $dir)) {
                    $out[] = ['name' => basename($file), 'file' => true, 'directory' => false, 'size' => strlen($fs[$uuid][$file])];
                }
            }

            return $ok($out);
        case $rest === '/files/contents':
            $file = trim($q['file'], '/');

            return isset($fs[$uuid][$file]) ? $ok($fs[$uuid][$file]) : $ok(['error' => 'not found'], 404);
        case $rest === '/files/pull':
            $fs[$uuid][trim($data['root'], '/') . '/' . $data['file_name']] = 'downloaded from ' . $data['url'];
            $log[] = "pull {$names[$uuid]} {$data['url']}";

            return $ok('', 204);
        case $rest === '/files/rename':
            foreach ($data['files'] as $r) {
                $from = trim($data['root'] . '/' . $r['from'], '/');
                $to = trim($data['root'] . '/' . $r['to'], '/');
                $fs[$uuid][$to] = $fs[$uuid][$from];
                unset($fs[$uuid][$from]);
                $log[] = "rename {$names[$uuid]} $from -> $to";
            }

            return $ok('', 204);
        case $rest === '/files/delete':
            foreach ($data['files'] as $f) {
                unset($fs[$uuid][trim($data['root'] . '/' . $f, '/')]);
                $log[] = "delete {$names[$uuid]} " . trim($data['root'] . '/' . $f, '/');
            }

            return $ok('', 204);
        case $rest === '/backup':
            $log[] = "backup {$names[$uuid]}";

            return $ok('', 204);
        case str_ends_with($rest, '/restore'):
            $log[] = "restore {$names[$uuid]} truncate=" . var_export($data['truncate_directory'], true);

            return $ok('', 204);
        case $rest === '/sync':
            return $ok('', 204);
    }

    return $ok('', 204);
});

DB::beginTransaction();
try {
    $user = User::forceCreate(['uuid' => Uuid::uuid4()->toString(), 'username' => 'ops_' . Str::random(6), 'email' => Str::random(10) . '@example.com',
        'password' => bcrypt('x'), 'language' => 'en', 'oauth' => [], 'external_id' => null, 'is_managed_externally' => false]);
    $user->syncRoles(Role::getRootAdmin());
    $node = Node::forceCreate(['uuid' => Uuid::uuid4()->toString(), 'public' => true, 'name' => 'ops-node', 'fqdn' => 'wings.test', 'scheme' => 'http',
        'behind_proxy' => false, 'memory' => 65536, 'memory_overallocate' => 0, 'disk' => 500000, 'disk_overallocate' => 0, 'cpu' => 0, 'cpu_overallocate' => 0,
        'upload_size' => 100, 'daemon_token_id' => Str::random(Node::DAEMON_TOKEN_ID_LENGTH), 'daemon_token' => Str::random(Node::DAEMON_TOKEN_LENGTH),
        'daemon_listen' => 8080, 'daemon_connect' => 8080, 'daemon_sftp' => 2022, 'daemon_base' => '/var/lib/pelican/volumes', 'maintenance_mode' => false]);
    $GLOBALS['tokenId'] = $node->daemon_token_id;
    $egg = Egg::forceCreate(['uuid' => Uuid::uuid4()->toString(), 'author' => 'test@example.com', 'docker_images' => ['a' => 'a'], 'config_logs' => '{}',
        'config_startup' => '{}', 'config_stop' => '{}', 'config_files' => '{}', 'name' => 'ops-egg', 'description' => 'test', 'startup_commands' => ['Default' => 'java -jar server.jar']]);

    $make = function (string $name, int $port, string $initial) use ($user, $node, $egg, &$state, &$names, &$fs): Server {
        $a = Allocation::forceCreate(['ip' => '10.2.0.1', 'port' => $port, 'node_id' => $node->id]);
        $s = Server::forceCreate(['owner_id' => $user->id, 'node_id' => $node->id, 'allocation_id' => $a->id, 'egg_id' => $egg->id,
            'uuid' => Uuid::uuid4()->toString(), 'uuid_short' => Str::lower(Str::random(8)), 'name' => $name, 'description' => '',
            'skip_scripts' => 0, 'status' => null, 'memory' => 4096, 'swap' => 0, 'disk' => 10000, 'io' => 500, 'cpu' => 0,
            'oom_killer' => false, 'startup' => 'java -jar server.jar', 'image' => 'x', 'backup_limit' => 10]);
        $a->forceFill(['server_id' => $s->id])->save();
        $state[$s->uuid] = $initial;
        $names[$s->uuid] = $name;
        $fs[$s->uuid] = [];

        return $s;
    };
    $redis = $make('redis', 26379, 'running');
    $west = $make('west', 25565, 'running');   // leader
    $east = $make('east', 25566, 'running');
    $north = $make('north', 25567, 'offline'); // a shard that's off
    $proxy = $make('proxy', 25577, 'running');
    foreach ([$west, $east, $north] as $s) {
        $fs[$s->uuid] = ['server.jar' => 'OLD-REEF', 'plugins/nodes-0.1.1.jar' => 'OLD-NODES', 'plugins/ginjermap-0.1.1.jar' => 'OLD-MAP', 'plugins/Essentials.jar' => 'E', 'plugins/spark.jar' => 'S'];
    }
    $fs[$east->uuid]['plugins/spark.jar.disabled'] = $fs[$east->uuid]['plugins/spark.jar'];
    unset($fs[$east->uuid]['plugins/spark.jar']);

    $network = Network::create(['name' => 'ops-net', 'world' => 'world', 'regions' => '', 'redis_host' => '127.0.0.1', 'redis_port' => 1, 'key_prefix' => 'nodes',
        'nodes_repo' => 'ginjerul/ginjer-nodes', 'nodes_release' => 'dev-master', 'github_token' => 'ghp_test', 'restart_settle_seconds' => 0, 'lag_tps' => 15, 'lag_minutes' => 3]);
    foreach ([[$redis, MemberRole::Redis, null, false], [$west, MemberRole::Shard, 'west', true], [$east, MemberRole::Shard, 'east', false],
        [$north, MemberRole::Shard, 'north', false], [$proxy, MemberRole::Proxy, null, false]] as [$s, $role, $shard, $leader]) {
        NetworkMember::create(['network_id' => $network->id, 'server_id' => $s->id, 'role' => $role, 'shard_name' => $shard, 'nodes_leader' => $leader]);
    }
    $network->refresh();

    // ---------------- plugins on every shard
    $plugins = collect(app(NetworkPlugins::class)->list($network)['plugins'])->keyBy('file');
    check('plugins: one row per plugin across shards', $plugins->count() === 4, $plugins->keys()->toJson());
    check('plugins: a plugin off on one shard shows as mixed', $plugins['spark.jar']['mixed'] && $plugins['spark.jar']['on'] === 2 && $plugins['spark.jar']['off'] === 1);
    $r = app(NetworkPlugins::class)->set($network, 'spark.jar', false);
    check('plugins: switching off changes only the shards that had it on', $r['changed'] === 2 && $r['failed'] === []);
    check('plugins: now off everywhere', collect([$west, $east, $north])->every(fn ($s) => isset($fs[$s->uuid]['plugins/spark.jar.disabled']) && !isset($fs[$s->uuid]['plugins/spark.jar'])));
    check('plugins: proxy and Redis untouched', !collect($log)->contains(fn ($l) => str_contains($l, ' proxy ') || str_contains($l, ' redis ')));

    // ---------------- rolling update
    $log = [];
    app()->call([new RollingUpdate($network->id, ['reef' => true, 'nodes' => true, 'ginjermap' => true], $user->id), 'handle']);
    $note = DB::table('notifications')->where('notifiable_id', $user->id)->latest()->value('data');
    check('update: finished', str_contains((string) $note, 'Rolling update of ops-net finished'), (string) $note);
    $uploads = collect($log)->filter(fn ($l) => str_starts_with($l, 'upload'))->values();
    check('update: 3 files uploaded to each of 3 shards under a temporary name', $uploads->count() === 9 && $uploads->every(fn ($l) => str_ends_with($l, '.ginjer-new')), $uploads->toJson());
    $firstPower = collect($log)->search(fn ($l) => str_starts_with($l, 'power'));
    $lastUpload = $uploads->isEmpty() ? -1 : collect($log)->search($uploads->last());
    check('update: every upload finishes before any shard is restarted', $firstPower > $lastUpload);
    $powers = collect($log)->filter(fn ($l) => str_starts_with($l, 'power'))->values()->all();
    check('update: one shard at a time, followers first, leader last; the offline shard isn\'t started',
        $powers === ['power east stop', 'power east start', 'power west stop', 'power west start'], json_encode($powers));
    $eastStop = array_search('power east stop', $log);
    $eastSwap = array_search('rename east server.jar.ginjer-new -> server.jar', $log);
    $eastStart = array_search('power east start', $log);
    check('update: files swapped while the shard is stopped', $eastStop < $eastSwap && $eastSwap < $eastStart, json_encode($log));
    check('update: new files in place, names kept', collect([$west, $east, $north])->every(fn ($s) => ($fs[$s->uuid]['server.jar'] ?? '') === 'NEW-REEF'
        && ($fs[$s->uuid]['plugins/nodes-0.1.1.jar'] ?? '') === 'NEW-NODES' && ($fs[$s->uuid]['plugins/ginjermap-0.1.1.jar'] ?? '') === 'NEW-MAP'
        && !collect(array_keys($fs[$s->uuid]))->contains(fn ($f) => str_ends_with($f, '.ginjer-new'))));
    check('update: the offline shard got the files and stays offline', $state[$north->uuid] === 'offline' && $fs[$north->uuid]['server.jar'] === 'NEW-REEF');
    check('update: not busy afterwards', !Busy::is($network->id));

    // a failing upload restarts nothing
    $log = [];
    $failUpload = true;
    app()->call([new RollingUpdate($network->id, ['reef' => true], $user->id), 'handle']);
    $failUpload = false;
    $note = DB::table('notifications')->where('notifiable_id', $user->id)->orderByDesc('created_at')->get()->pluck('data')->first(fn ($d) => str_contains($d, 'stopped'));
    check('update: a failed upload stops it before any restart', !collect($log)->contains(fn ($l) => str_starts_with($l, 'power')) && $note !== null, json_encode($log));

    // a private repository without a token is explained
    $network->update(['github_token' => null]);
    app()->call([new RollingUpdate($network->id, ['nodes' => true], $user->id), 'handle']);
    $note = DB::table('notifications')->where('notifiable_id', $user->id)->get()->pluck('data')->first(fn ($d) => str_contains($d, 'needs a GitHub token'));
    check('update: a missing token for a private repo is explained', $note !== null);
    $network->update(['github_token' => 'ghp_test']);

    // plain rolling restart
    $log = [];
    app()->call([new RollingUpdate($network->id, [], $user->id), 'handle']);
    check('rolling restart: restarts running shards in order, uploads nothing',
        collect($log)->filter(fn ($l) => str_starts_with($l, 'power'))->values()->all() === ['power east stop', 'power east start', 'power west stop', 'power west start']
        && !collect($log)->contains(fn ($l) => str_starts_with($l, 'upload')));

    // ---------------- health
    $health = app(NetworkHealth::class);
    $shards = $health->shards($network);
    check('health: Wings resources per shard', $shards['west']['memory_mb'] === 3072 && $shards['west']['memory_limit_mb'] === 8192 && $shards['west']['cpu'] === 87.5);
    check('health: Redis down means not reporting', $shards['west']['reporting'] === false);
    $before = DB::table('notifications')->where('notifiable_id', $user->id)->count();
    $health->check($network);       // remembers everything as running
    $state[$east->uuid] = 'offline';
    $health->check($network);       // east went down
    $alerts = DB::table('notifications')->where('notifiable_id', $user->id)->get()->pluck('data')->slice($before);
    check('health: root admins are told a shard went down', $alerts->contains(fn ($d) => str_contains($d, 'ops-net: east is down')), $alerts->toJson());
    Busy::start($network->id);
    $state[$west->uuid] = 'offline';
    $count = DB::table('notifications')->where('notifiable_id', $user->id)->count();
    $health->check($network);
    check('health: quiet while the network is busy', DB::table('notifications')->where('notifiable_id', $user->id)->count() === $count);
    $metrics = \Ginjer\Network\Models\ShardMetric::where('network_id', $network->id)->get();
    check('history: every check stores one row per shard', $metrics->count() === 9 && $metrics->pluck('shard')->unique()->sort()->values()->all() === ['east', 'north', 'west'], (string) $metrics->count());
    check('history: with Wings\' memory and CPU', $metrics->firstWhere('shard', 'west')->memory_mb === 3072 && $metrics->firstWhere('shard', 'west')->cpu === 87.5);
    \Ginjer\Network\Models\ShardMetric::create(['network_id' => $network->id, 'shard' => 'west', 'recorded_at' => now()->subDays(8)]);
    $health->check($network);
    check('history: rows older than a week are dropped', \Ginjer\Network\Models\ShardMetric::where('network_id', $network->id)->where('recorded_at', '<', now()->subDays(7))->doesntExist());
    Busy::stop($network->id);
    $state[$west->uuid] = 'running';
    $state[$east->uuid] = 'running';

    // ---------------- backups
    $log = [];
    $network->update(['backup_keep' => 2]);
    $result = app(NetworkBackups::class)->take($network->fresh());
    $group = $result['backup'];
    check('backup: every member backed up', count($group->backups) === 5 && collect($log)->filter(fn ($l) => str_starts_with($l, 'backup'))->count() === 5, json_encode([$group->backups, $result['problems']]));
    check('backup: leader saves Nodes and the world first, followers save their world',
        in_array('command west nodesadmin save + save-all flush', $log, true) && in_array('command east save-all flush', $log, true), json_encode($log));
    check('backup: offline shards aren\'t asked to save', !collect($log)->contains(fn ($l) => str_starts_with($l, 'command north')));
    check('backup: saves happen before backups', array_search('command west nodesadmin save + save-all flush', $log) < array_search('backup west', $log));
    check('backup: Redis being unreachable is noted, not fatal', $group->status === 'running' && str_contains(implode(' ', $result['problems']), 'Redis'));
    Backup::whereIn('uuid', array_values($group->backups))->update(['completed_at' => now(), 'is_successful' => true, 'bytes' => 1048576]);
    $group->refreshStatus();
    check('backup: done once every server backup finished', $group->fresh()->status === 'done');

    app(NetworkBackups::class)->take($network->fresh());
    app(NetworkBackups::class)->take($network->fresh());
    check('backup: only the last 2 network backups are kept', NetworkBackup::where('network_id', $network->id)->count() === 2 && NetworkBackup::find($group->id) === null);
    check('backup: pruning deletes the server backups too', Backup::whereIn('uuid', array_values($group->backups))->count() === 0);

    $latest = NetworkBackup::where('network_id', $network->id)->latest('id')->first();
    try {
        app(NetworkBackups::class)->restore($latest, $user);
        check('restore: refuses a backup that isn\'t finished', false);
    } catch (RuntimeException $e) {
        check('restore: refuses a backup that isn\'t finished', str_contains($e->getMessage(), 'hasn\'t finished'));
    }
    Backup::whereIn('uuid', array_values($latest->backups))->update(['completed_at' => now(), 'is_successful' => true]);
    $log = [];
    $problems = app(NetworkBackups::class)->restore($latest->fresh(), $user);
    $stops = collect($log)->filter(fn ($l) => str_starts_with($l, 'power') && str_ends_with($l, 'stop'))->count();
    $restores = collect($log)->filter(fn ($l) => str_starts_with($l, 'restore'))->values();
    check('restore: every running server is stopped first', $stops === 4 && array_search($restores->first(), $log) > max(array_keys(array_filter($log, fn ($l) => str_ends_with($l, ' stop')))), json_encode($log));
    check('restore: every server restored exactly (truncate)', $problems === [] && $restores->count() === 5 && $restores->every(fn ($l) => str_ends_with($l, 'truncate=true')), json_encode([$problems, $restores]));
    check('restore: servers marked as restoring', Server::whereIn('id', [$west->id, $redis->id])->get()->every(fn ($s) => $s->status?->value === 'restoring_backup'));

    // ---------------- Chunky and spark
    $tools = app(\Ginjer\Network\Services\Ops\ServerTools::class);
    check('tools: Chunky isn\'t there yet', $tools->findPlugin($west, 'chunky') === null);
    $file = $tools->installFromModrinth($west, 'chunky');
    check('tools: the newest Paper build is downloaded by Wings into plugins/', $file === 'Chunky-Bukkit-1.4.40.jar'
        && ($fs[$west->uuid]['plugins/Chunky-Bukkit-1.4.40.jar'] ?? '') === 'downloaded from https://cdn.modrinth.com/data/fALzjamp/versions/x/Chunky-Bukkit-1.4.40.jar');
    check('tools: then it\'s found', $tools->findPlugin($west, 'chunky') === 'Chunky-Bukkit-1.4.40.jar');
    [$shardName, $shardRegions] = \Ginjer\Network\Services\Ops\ServerTools::shardOf($west);
    check('tools: a shard knows its name for pre-generation', $shardName === 'west');
    check('tools: a server outside a network isn\'t a shard', \Ginjer\Network\Services\Ops\ServerTools::shardOf($redis) === [null, null]);

    $fs[$west->uuid]['logs/latest.log'] = "[10:00:00 INFO]: https://spark.lucko.me/OldProfile1\n";
    $before = DB::table('notifications')->where('notifiable_id', $user->id)->count();
    $fs[$west->uuid]['logs/latest.log'] .= "[10:01:05 INFO]: [⚡] Profiler stopped & upload complete!\n[10:01:05 INFO]: [⚡] https://spark.lucko.me/NewProfile2\n";
    app()->call([new \Ginjer\Network\Jobs\ProfileWithSpark($west->id, $user->id, 60, 'https://spark.lucko.me/OldProfile1'), 'handle']);
    $note = DB::table('notifications')->where('notifiable_id', $user->id)->get()->pluck('data')->slice($before)->implode(' ');
    check('spark: the new profile link is sent, not the old one', str_contains($note, 'NewProfile2') && !str_contains($note, 'OldProfile1'), $note);
    $before = DB::table('notifications')->where('notifiable_id', $user->id)->count();
    app()->call([new \Ginjer\Network\Jobs\ProfileWithSpark($west->id, $user->id, 60, 'https://spark.lucko.me/NewProfile2'), 'handle']);
    $note = DB::table('notifications')->where('notifiable_id', $user->id)->get()->pluck('data')->slice($before)->implode(' ');
    check('spark: no new link is reported as a problem, not as the old link', str_contains($note, 'No spark profile link') && !str_contains($note, 'Open the profile'), $note);
} catch (Throwable $exception) {
    check('no exception', false, get_class($exception) . ': ' . $exception->getMessage() . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
} finally {
    DB::rollBack();
}

echo $failures === 0 ? "\nAll checks passed\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
