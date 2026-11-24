<?php

namespace Ginjer\Network\Services\Players;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Throwable;

/**
 * Kicks, bans and messages players across a network. Bans are kept per server by Minecraft, so a
 * network ban is the same ban on every shard.
 */
class PlayerAdmin
{
    public function __construct(private DaemonFileRepository $files) {}

    public static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,16}$/', $name) === 1;
    }

    /** One line, no section signs: safe to put after a command. */
    public static function clean(string $text): string
    {
        return trim(mb_substr(preg_replace('/[\r\n\x00-\x1F§]+/u', ' ', $text), 0, 200));
    }

    private static function shardServer(Network $network, string $shard): ?Server
    {
        return $network->loadMissing('members.server')->membersWithRole(MemberRole::Shard)->firstWhere('shard_name', $shard)?->server;
    }

    /** @return list<Server> */
    private static function shards(Network $network): array
    {
        return $network->loadMissing('members.server')->membersWithRole(MemberRole::Shard)->map->server->values()->all();
    }

    private function guard(string $name): void
    {
        if (!self::validName($name)) {
            throw new \InvalidArgumentException("\"$name\" isn't a Minecraft name.");
        }
    }

    public function kick(Network $network, string $name, string $shard, string $reason): void
    {
        $this->guard($name);
        $server = self::shardServer($network, $shard) ?? throw new \RuntimeException("No shard called $shard.");
        \Ginjer\Network\Services\Ops\Console::run($server, trim("kick $name " . self::clean($reason)));
    }

    public function message(Network $network, string $name, string $shard, string $text): void
    {
        $this->guard($name);
        $server = self::shardServer($network, $shard) ?? throw new \RuntimeException("No shard called $shard.");
        \Ginjer\Network\Services\Ops\Console::run($server, "tell $name " . self::clean($text));
    }

    /**
     * Bans on every shard (an offline shard keeps the ban in its banned-players.json once it's
     * started and the command is repeated, so offline ones are reported).
     *
     * @return array{done: list<string>, failed: list<string>}
     */
    public function ban(Network $network, string $name, string $reason): array
    {
        $this->guard($name);

        return $this->everywhere($network, trim("ban $name " . self::clean($reason)));
    }

    /** @return array{done: list<string>, failed: list<string>} */
    public function unban(Network $network, string $name): array
    {
        $this->guard($name);

        return $this->everywhere($network, "pardon $name");
    }

    /** @return array{done: list<string>, failed: list<string>} */
    private function everywhere(Network $network, string $command): array
    {
        $done = [];
        $failed = [];
        foreach (self::shards($network) as $server) {
            try {
                \Ginjer\Network\Services\Ops\Console::run($server, $command);
                $done[] = $server->name;
            } catch (Throwable) {
                $failed[] = $server->name;
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Bans on any shard, with the shards that have each one (a ban missing on some shards lets the
     * player in there).
     *
     * @return list<array{name: string, uuid: string, reason: string, source: string, created: string, on: list<string>, missing: list<string>}>
     */
    public function bans(Network $network): array
    {
        $bans = [];
        $readable = [];
        foreach (self::shards($network) as $server) {
            try {
                $list = json_decode((string) $this->files->setServer($server)->getContent('banned-players.json', 2 * 1024 * 1024), true);
            } catch (\Illuminate\Contracts\Filesystem\FileNotFoundException) {
                $list = [];
            } catch (Throwable) {
                continue;
            }
            $readable[] = $server->name;
            foreach (is_array($list) ? $list : [] as $ban) {
                $key = strtolower((string) ($ban['name'] ?? $ban['uuid'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $bans[$key] ??= ['name' => (string) ($ban['name'] ?? '?'), 'uuid' => (string) ($ban['uuid'] ?? ''), 'reason' => (string) ($ban['reason'] ?? ''),
                    'source' => (string) ($ban['source'] ?? ''), 'created' => (string) ($ban['created'] ?? ''), 'on' => []];
                $bans[$key]['on'][] = $server->name;
            }
        }
        foreach ($bans as &$ban) {
            $ban['missing'] = array_values(array_diff($readable, $ban['on']));
        }
        unset($ban);
        $bans = array_values($bans);
        usort($bans, fn ($a, $b) => strcmp($b['created'], $a['created']));

        return $bans;
    }
}
