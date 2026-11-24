<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Database;
use App\Models\DatabaseHost;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Databases\DatabaseManagementService;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\YamlScalarEditor;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Throwable;

/**
 * One MySQL database for a whole network, so plugins that keep data (LuckPerms, economy, stats...)
 * see the same data on every shard. It's a normal Pelican database owned by the Nodes leader's
 * server (it shows on that server's Databases tab), open to connections from any address so every
 * shard can reach it.
 */
class NetworkDatabase
{
    /** Paper's LuckPerms folder, then Velocity's */
    public const LUCKPERMS_PATHS = ['plugins/LuckPerms/config.yml', 'plugins/luckperms/config.yml'];

    public function __construct(private DatabaseManagementService $databases, private DaemonFileRepository $files) {}

    public static function database(Network $network): ?Database
    {
        return $network->database_id ? Database::with('host')->find($network->database_id) : null;
    }

    /** The server that owns the database: the Nodes leader, or the first shard. */
    public static function owner(Network $network): ?Server
    {
        $shards = $network->loadMissing('members.server')->membersWithRole(MemberRole::Shard);

        return ($network->leader() ?? $shards->first())?->server;
    }

    /** @return array<int, string> database host id => label; the hosts linked to the owner's node first */
    public static function hostOptions(Network $network): array
    {
        $linked = self::owner($network)?->node?->databaseHosts()->pluck('database_hosts.id')->all() ?? [];

        return DatabaseHost::query()->get()
            ->sortBy(fn (DatabaseHost $h) => in_array($h->id, $linked, true) ? 0 : 1)
            ->mapWithKeys(fn (DatabaseHost $h) => [$h->id => "{$h->name} ({$h->host}:{$h->port})" . (in_array($h->id, $linked, true) ? '' : ', not linked to the leader\'s node')])
            ->all();
    }

    public function create(Network $network, int $hostId): Database
    {
        if (self::database($network) !== null) {
            throw new \RuntimeException('This network already has a database.');
        }
        $owner = self::owner($network) ?? throw new \RuntimeException('Add shards to the network first.');

        $database = $this->databases->setValidateDatabaseLimit(false)->create($owner, [
            'database_host_id' => $hostId,
            'database' => DatabaseManagementService::generateUniqueDatabaseName('network', $owner->id),
            'remote' => '%', // the shards connect from their own nodes
        ]);
        $network->update(['database_id' => $database->id]);

        return $database;
    }

    /** Drops the database and everything in it. */
    public function remove(Network $network): void
    {
        if (($database = self::database($network)) !== null) {
            $this->databases->delete($database);
        }
        $network->update(['database_id' => null]);
    }

    public function rotatePassword(Network $network): void
    {
        $this->databases->rotatePassword(self::database($network) ?? throw new \RuntimeException('This network has no database.'));
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string, jdbc: string} */
    public static function details(Database $database): array
    {
        $host = $database->host->host;
        $port = (int) $database->host->port;

        return [
            'host' => $host,
            'port' => $port,
            'database' => $database->database,
            'username' => $database->username,
            'password' => $database->password,
            'jdbc' => "jdbc:mysql://$host:$port/{$database->database}",
        ];
    }

    /** LuckPerms' config.yml pointed at the database, with Redis (or the database) carrying updates between shards. */
    public static function luckPermsConfig(string $content, Database $database, Network $network): string
    {
        return self::luckPermsEditor($content, $database, $network)->content();
    }

    private static function luckPermsEditor(string $content, Database $database, Network $network): YamlScalarEditor
    {
        $d = self::details($database);
        $redis = filled($network->redis_host);
        $editor = (new YamlScalarEditor($content))
            ->set('storage-method', 'mysql')
            ->set('data.address', "{$d['host']}:{$d['port']}")
            ->set('data.database', $d['database'])
            ->set('data.username', $d['username'])
            ->set('data.password', $d['password'])
            // a change made on one shard (a rank, a permission) reaches the others right away
            ->set('messaging-service', $redis ? 'redis' : 'sql')
            ->set('redis.enabled', $redis);
        if ($redis) {
            $editor->set('redis.address', "{$network->redis_host}:{$network->redis_port}")
                ->set('redis.password', (string) $network->redis_password);
        }

        return $editor;
    }

    /**
     * Points LuckPerms on every shard (and the proxy, if it runs LuckPerms) at the database.
     *
     * @return list<array{server: string, ok: bool, message: string}>
     */
    public function setUpLuckPerms(Network $network): array
    {
        $database = self::database($network) ?? throw new \RuntimeException('Create the network database first.');
        $results = [];
        foreach ($network->loadMissing('members.server')->members as $member) {
            $server = $member->server;
            $path = null;
            $content = null;
            foreach (self::LUCKPERMS_PATHS as $candidate) {
                try {
                    $content = (string) $this->files->setServer($server)->getContent($candidate);
                    $path = $candidate;
                    break;
                } catch (FileNotFoundException) {
                    continue;
                } catch (Throwable $exception) {
                    $results[] = ['server' => $server->name, 'ok' => false, 'message' => $exception->getMessage()];

                    continue 2;
                }
            }
            if ($path === null) {
                if ($member->role === MemberRole::Shard) {
                    $results[] = ['server' => $server->name, 'ok' => false, 'message' => 'no LuckPerms config yet: put LuckPerms in plugins/ and start the shard once'];
                }

                continue;
            }
            try {
                $editor = self::luckPermsEditor($content, $database, $network);
                if ($editor->missing() !== []) {
                    $results[] = ['server' => $server->name, 'ok' => false, 'message' => 'its LuckPerms config has no ' . implode(', ', $editor->missing()) . ' (an old or edited config? delete it and restart once for a fresh one)'];

                    continue;
                }
                $new = $editor->content();
                if ($new === $content) {
                    $results[] = ['server' => $server->name, 'ok' => true, 'message' => 'already set up'];

                    continue;
                }
                $this->files->setServer($server)->putContent($path . ConfigSync::OLD, $content);
                $this->files->setServer($server)->putContent($path, $new);
                $results[] = ['server' => $server->name, 'ok' => true, 'message' => 'set up; restart it to use the database'];
            } catch (Throwable $exception) {
                $results[] = ['server' => $server->name, 'ok' => false, 'message' => $exception->getMessage()];
            }
        }

        return $results;
    }
}
