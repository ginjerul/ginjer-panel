<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Services\NodesStatusService;
use Ginjer\Network\Services\Optimizer\PropertiesEditor;
use Ginjer\Network\Services\Players\PlayerAdmin;
use Ginjer\Network\Services\VelocityConfig;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Throwable;

/**
 * Maintenance mode: only the allowed players can join while you work.
 *  - every shard turns its whitelist on, with the allowed players on it (a shard that already
 *    used a whitelist keeps it when maintenance ends)
 *  - players online who aren't allowed are kicked with a message
 *  - the proxy shows a maintenance MOTD in the server list (the old one comes back after)
 */
class Maintenance
{
    public const DEFAULT_MOTD = '<red>Down for maintenance. Back soon!';

    public const KICK = 'The server is down for maintenance. Back soon!';

    /** On each shard: whether its whitelist was on before maintenance. */
    public const MARKER = '.ginjer-maintenance';

    /** On the proxy: its MOTD line before maintenance. */
    public const MOTD_BACKUP = 'velocity.toml.ginjer-motd';

    public function __construct(private DaemonFileRepository $files, private NodesStatusService $nodes) {}

    /** @return list<string> */
    public static function allowed(Network $network): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\s,]+/', (string) $network->maintenance_allow) ?: []),
            fn ($name) => PlayerAdmin::validName($name),
        )));
    }

    /** @return list<string> what happened, server by server */
    public function enable(Network $network): array
    {
        $network->loadMissing('members.server');
        $allowed = self::allowed($network);
        $report = [];

        foreach ($network->membersWithRole(MemberRole::Shard) as $member) {
            $server = $member->server;
            try {
                // the state before maintenance, recorded once: turning it on twice must not lose it
                try {
                    $this->files->setServer($server)->getContent(self::MARKER);
                } catch (FileNotFoundException) {
                    $this->files->setServer($server)->putContent(self::MARKER, $this->whitelistWasOn($server) ? 'on' : 'off');
                }
                $commands = array_map(fn ($name) => "whitelist add $name", $allowed);
                $commands[] = 'whitelist on';
                try {
                    Console::run($server, $commands);
                    $report[] = "{$server->name}: whitelist on";
                } catch (Throwable) {
                    // offline: it starts with the whitelist on
                    $this->setProperty($server, 'white-list', 'true');
                    $report[] = "{$server->name}: offline; whitelist on when it starts" . ($allowed === [] ? '' : ' (add the allowed players with "whitelist add" once it\'s up)');
                }
            } catch (Throwable $exception) {
                $report[] = "{$server->name}: failed ({$exception->getMessage()})";
            }
        }

        // the whitelist only stops new joins: kick whoever isn't allowed
        $presence = $this->nodes->presence($network);
        $kicked = 0;
        foreach ($presence['players'] as $player) {
            if (in_array(strtolower($player['name']), array_map('strtolower', $allowed), true)) {
                continue;
            }
            $server = $network->membersWithRole(MemberRole::Shard)->firstWhere('shard_name', $player['shard'])?->server;
            if ($server && PlayerAdmin::validName($player['name'])) {
                rescue(fn () => $server->send("kick {$player['name']} " . self::KICK), report: false);
                $kicked++;
            }
        }
        if ($kicked > 0) {
            $report[] = "kicked $kicked player(s)";
        }

        if ($proxy = $network->membersWithRole(MemberRole::Proxy)->first()?->server) {
            $report[] = $this->setMotd($proxy, $network->maintenance_motd ?: self::DEFAULT_MOTD);
        }
        $network->update(['maintenance' => true]);

        return $report;
    }

    /** @return list<string> */
    public function disable(Network $network): array
    {
        $network->loadMissing('members.server');
        $report = [];
        foreach ($network->membersWithRole(MemberRole::Shard) as $member) {
            $server = $member->server;
            try {
                $before = rescue(fn () => trim((string) $this->files->setServer($server)->getContent(self::MARKER)), 'off', false);
                if ($before === 'on') {
                    $report[] = "{$server->name}: kept its own whitelist";
                } else {
                    try {
                        Console::run($server, 'whitelist off');
                    } catch (Throwable) {
                        $this->setProperty($server, 'white-list', 'false');
                    }
                    $report[] = "{$server->name}: whitelist off";
                }
                rescue(fn () => $this->files->setServer($server)->deleteFiles('/', [self::MARKER]), report: false);
            } catch (Throwable $exception) {
                $report[] = "{$server->name}: failed ({$exception->getMessage()})";
            }
        }
        if ($proxy = $network->membersWithRole(MemberRole::Proxy)->first()?->server) {
            $report[] = $this->restoreMotd($proxy);
        }
        $network->update(['maintenance' => false]);

        return $report;
    }

    private function whitelistWasOn(Server $server): bool
    {
        try {
            $properties = new PropertiesEditor((string) $this->files->setServer($server)->getContent('server.properties'));

            return $properties->get('white-list') === 'true';
        } catch (FileNotFoundException) {
            return false;
        }
    }

    private function setProperty(Server $server, string $key, string $value): void
    {
        $properties = new PropertiesEditor((string) $this->files->setServer($server)->getContent('server.properties'));
        $properties->set($key, $value);
        $this->files->setServer($server)->putContent('server.properties', $properties->content());
    }

    private function setMotd(Server $proxy, string $motd): string
    {
        try {
            $toml = (string) $this->files->setServer($proxy)->getContent('velocity.toml');
            if (!preg_match('/^motd\s*=.*$/m', $toml, $m)) {
                return "{$proxy->name}: no motd in velocity.toml";
            }
            // keep the original only once, so turning maintenance on twice doesn't lose it
            try {
                $this->files->setServer($proxy)->getContent(self::MOTD_BACKUP);
            } catch (FileNotFoundException) {
                $this->files->setServer($proxy)->putContent(self::MOTD_BACKUP, $m[0]);
            }
            $this->files->setServer($proxy)->putContent('velocity.toml', VelocityConfig::setTopLevel($toml, 'motd', $motd));
            rescue(fn () => $proxy->send('velocity reload'), report: false);

            return "{$proxy->name}: maintenance MOTD";
        } catch (Throwable $exception) {
            return "{$proxy->name}: MOTD not changed ({$exception->getMessage()})";
        }
    }

    private function restoreMotd(Server $proxy): string
    {
        try {
            $line = trim((string) $this->files->setServer($proxy)->getContent(self::MOTD_BACKUP));
            $toml = (string) $this->files->setServer($proxy)->getContent('velocity.toml');
            $this->files->setServer($proxy)->putContent('velocity.toml', preg_replace('/^motd\s*=.*$/m', addcslashes($line, '\\$'), $toml, 1));
            $this->files->setServer($proxy)->deleteFiles('/', [self::MOTD_BACKUP]);
            rescue(fn () => $proxy->send('velocity reload'), report: false);

            return "{$proxy->name}: MOTD back";
        } catch (FileNotFoundException) {
            return "{$proxy->name}: no saved MOTD to put back";
        } catch (Throwable $exception) {
            return "{$proxy->name}: MOTD not restored ({$exception->getMessage()})";
        }
    }
}
