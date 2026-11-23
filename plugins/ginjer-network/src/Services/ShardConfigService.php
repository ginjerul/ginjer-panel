<?php

namespace Ginjer\Network\Services;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Writes the network's settings into every shard:
 * - reef.yml "sharding": the same regions everywhere, this shard's own name in this-server.
 * - plugins/nodes/config.yml "sharding": leader or follower, the shard name and Redis.
 *
 * Both files are created by Reef and Nodes the first time a shard starts, so a shard that has
 * never run is skipped with a message. Shards read the new settings on their next restart
 * (Reef also on /reef reload).
 */
class ShardConfigService
{
    public function __construct(private DaemonFileRepository $files) {}

    /**
     * Problems that would make the pushed config wrong. Empty means it's safe to push.
     *
     * @return list<string>
     */
    public function problems(Network $network): array
    {
        $network->loadMissing('members.server');
        $shards = $network->membersWithRole(MemberRole::Shard);
        $problems = [];

        if ($shards->isEmpty()) {
            $problems[] = 'This network has no shards yet. Add servers with the "Reef shard" role first.';
        }
        foreach ($shards as $shard) {
            if (blank($shard->shard_name)) {
                $problems[] = "Shard {$shard->server->name} has no shard name (its name in velocity.toml).";
            }
        }
        $leaders = $shards->filter(fn (NetworkMember $m) => $m->nodes_leader)->count();
        if ($shards->isNotEmpty() && $leaders !== 1) {
            $problems[] = "Exactly one shard must be the Nodes leader (now $leaders).";
        }

        $names = $shards->pluck('shard_name')->filter()->values()->all();

        return array_merge($problems, RegionList::problems($network->regions ?? '', $names));
    }

    /**
     * @return list<array{server: string, ok: bool, complete: bool, message: string}>
     */
    public function push(Network $network): array
    {
        $results = [];
        foreach ($network->membersWithRole(MemberRole::Shard) as $shard) {
            $name = $shard->server->name;
            try {
                $notes = array_merge($this->writeReef($network, $shard), $this->writeNodes($network, $shard));
                $results[] = ['server' => $name, 'ok' => true, 'complete' => $notes === [], 'message' => $notes === [] ? 'Updated. Restart it to apply.' : implode(' ', $notes)];
            } catch (Throwable $exception) {
                $results[] = ['server' => $name, 'ok' => false, 'complete' => false, 'message' => $exception->getMessage()];
            }
        }

        foreach ($network->membersWithRole(MemberRole::Proxy) as $proxy) {
            try {
                $notes = $this->writeProxy($network, $proxy);
                $results[] = ['server' => $proxy->server->name, 'ok' => true, 'complete' => $notes === [], 'message' => $notes === [] ? 'velocity.toml updated, forwarding secret shared with the shards. Restart it to apply.' : implode(' ', $notes)];
            } catch (Throwable $exception) {
                $results[] = ['server' => $proxy->server->name, 'ok' => false, 'complete' => false, 'message' => $exception->getMessage()];
            }
        }

        return $results;
    }

    /**
     * The address the proxy reaches a shard on. A shard that listens on every interface (or only
     * on localhost) is reached through Docker's gateway when it's on the proxy's node, and through
     * its node's address otherwise.
     */
    public static function addressFor(NetworkMember $shard, Server $proxy): string
    {
        $allocation = $shard->server->allocation;
        if ($allocation === null) {
            return '';
        }
        $ip = $allocation->ip;
        if (in_array($ip, ['0.0.0.0', '::', '127.0.0.1', '::1', ''], true)) {
            $ip = $shard->server->node_id === $proxy->node_id
                ? (string) config('ginjer-network.docker_gateway', '172.18.0.1')
                : $shard->server->node->fqdn;
        }

        return (str_contains($ip, ':') ? "[$ip]" : $ip) . ':' . $allocation->port;
    }

    /** @return list<string> notes for the admin */
    private function writeProxy(Network $network, NetworkMember $proxy): array
    {
        $server = $proxy->server;
        $repository = $this->files->setServer($server);
        $toml = $this->read($repository, 'velocity.toml');
        if ($toml === null) {
            return ['No velocity.toml yet: start the proxy once so Velocity creates it, then push again.'];
        }

        // leader first: new players log in near spawn, which the leader keeps
        $shards = $network->membersWithRole(MemberRole::Shard)->sortByDesc('nodes_leader')->values();
        $servers = [];
        foreach ($shards as $shard) {
            $servers[$shard->shard_name] = self::addressFor($shard, $server);
        }
        $toml = VelocityConfig::setServers($toml, $servers, array_keys($servers));
        $toml = VelocityConfig::setTopLevel($toml, 'player-info-forwarding-mode', 'modern');
        $repository->putContent('velocity.toml', $toml);

        // the shards need the proxy's forwarding secret
        $secret = trim((string) $this->read($repository, 'forwarding.secret'));
        if ($secret === '') {
            return ['velocity.toml updated, but there is no forwarding.secret on the proxy yet: start it once, then push again so the shards get the secret.'];
        }
        foreach ($shards as $shard) {
            $this->shareSecret($shard->server, $secret);
        }

        return [];
    }

    /** Puts the proxy's forwarding secret where a shard reads it: the egg variable (if the egg has one) and paper-global.yml. */
    private function shareSecret(Server $shard, string $secret): void
    {
        $variable = $shard->egg?->variables()->where('env_variable', 'VELOCITY_SECRET')->first();
        if ($variable !== null) {
            // the shard egg writes this into paper-global.yml every time the shard starts
            \App\Models\ServerVariable::query()->updateOrCreate(
                ['server_id' => $shard->id, 'variable_id' => $variable->id],
                ['variable_value' => $secret],
            );
        }

        $repository = $this->files->setServer($shard);
        $global = $this->read($repository, 'config/paper-global.yml');
        if ($global !== null) {
            $editor = (new YamlScalarEditor($global))
                ->set('proxies.velocity.enabled', true)
                ->set('proxies.velocity.online-mode', true)
                ->set('proxies.velocity.secret', $secret);
            $repository->putContent('config/paper-global.yml', $editor->content());
        }
        $properties = $this->read($repository, 'server.properties');
        if ($properties !== null) {
            $editor = new \Ginjer\Network\Services\Optimizer\PropertiesEditor($properties);
            if ($editor->get('online-mode') !== 'false' && $editor->set('online-mode', 'false')) {
                $repository->putContent('server.properties', $editor->content()); // the proxy checks accounts
            }
        }
    }

    /** The reef.yml "sharding" section for one shard. */
    public function reefSection(Network $network, NetworkMember $shard): array
    {
        return [
            'enabled' => true,
            'this-server' => $shard->shard_name,
            'world' => $network->world,
            'shared-player-data-folder' => $network->shared_player_data_folder,
            'border-protection' => $network->border_protection,
            'handoff-timeout-seconds' => $network->handoff_timeout_seconds,
            'regions' => $network->parsedRegions(),
        ];
    }

    /** @return list<string> notes for the admin */
    private function writeReef(Network $network, NetworkMember $shard): array
    {
        $path = config('ginjer-network.reef_config_path', 'reef.yml');
        $repository = $this->files->setServer($shard->server);

        $content = $this->read($repository, $path);
        if ($content === null) {
            return ["No $path yet: start this shard once on Reef so it creates the file, then push again."];
        }

        $data = Yaml::parse($content) ?? [];
        if (!is_array($data)) {
            throw new \RuntimeException("$path is not a YAML map.");
        }
        $data['sharding'] = array_merge(is_array($data['sharding'] ?? null) ? $data['sharding'] : [], $this->reefSection($network, $shard));

        // Reef puts its comments back the next time it loads the file.
        $repository->putContent($path, Yaml::dump($data, 6, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

        return [];
    }

    /** @return list<string> notes for the admin */
    private function writeNodes(Network $network, NetworkMember $shard): array
    {
        $path = config('ginjer-network.nodes_config_path', 'plugins/nodes/config.yml');
        $repository = $this->files->setServer($shard->server);

        $content = $this->read($repository, $path);
        if ($content === null) {
            return ["No $path: Nodes isn't installed on this shard or hasn't started yet."];
        }

        $editor = (new YamlScalarEditor($content))
            ->set('sharding.enabled', true)
            ->set('sharding.role', $shard->nodes_leader ? 'leader' : 'follower')
            ->set('sharding.name', (string) $shard->shard_name)
            ->set('sharding.redis.host', $network->redis_host)
            ->set('sharding.redis.port', $network->redis_port)
            ->set('sharding.redis.password', (string) $network->redis_password)
            ->set('sharding.redis.database', $network->redis_database)
            ->set('sharding.keyPrefix', $network->key_prefix);

        $repository->putContent($path, $editor->content());

        return $editor->missing() === []
            ? []
            : ['Nodes config has no ' . implode(', ', $editor->missing()) . ' (older Nodes version?), so those were not set.'];
    }

    private function read(DaemonFileRepository $repository, string $path): ?string
    {
        try {
            return (string) $repository->getContent($path);
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
