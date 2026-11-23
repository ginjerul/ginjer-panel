<?php

namespace Ginjer\Network\Services;

use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Throwable;

/**
 * Plugin switches for a whole network: every shard should run the same plugins, so a plugin is
 * switched on or off on all shards at once. Plugins are matched by jar name.
 */
class NetworkPlugins
{
    public function __construct(private PluginSwitch $switch) {}

    /**
     * @return array{plugins: list<array{file: string, name: string, on: int, off: int, missing: int, enabled: bool, mixed: bool}>, errors: list<string>, shards: int}
     */
    public function list(Network $network): array
    {
        $shards = $network->loadMissing('members.server')->membersWithRole(MemberRole::Shard);
        $byFile = [];
        $errors = [];
        foreach ($shards as $shard) {
            try {
                foreach ($this->switch->list($shard->server) as $plugin) {
                    $key = self::key($plugin['file']);
                    $byFile[$key] ??= ['file' => $key, 'name' => $plugin['name'], 'on' => 0, 'off' => 0];
                    $byFile[$key][$plugin['enabled'] ? 'on' : 'off']++;
                }
            } catch (Throwable $exception) {
                $errors[] = "{$shard->server->name}: {$exception->getMessage()}";
            }
        }

        $total = $shards->count() - count($errors);
        $plugins = array_map(fn ($p) => $p + [
            'missing' => $total - $p['on'] - $p['off'],
            'enabled' => $p['on'] > 0 && $p['off'] === 0,
            'mixed' => $p['on'] > 0 && $p['off'] > 0,
        ], array_values($byFile));
        usort($plugins, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['plugins' => $plugins, 'errors' => $errors, 'shards' => $total];
    }

    /**
     * Switch a plugin on every shard that has it.
     *
     * @return array{changed: int, failed: list<string>}
     */
    public function set(Network $network, string $file, bool $enabled): array
    {
        $key = self::key($file);
        $changed = 0;
        $failed = [];
        foreach ($network->loadMissing('members.server')->membersWithRole(MemberRole::Shard) as $shard) {
            /** @var NetworkMember $shard */
            try {
                foreach ($this->switch->list($shard->server) as $plugin) {
                    if (self::key($plugin['file']) === $key && $plugin['enabled'] !== $enabled) {
                        $this->switch->set($shard->server, $plugin['file'], $enabled);
                        $changed++;
                    }
                }
            } catch (Throwable $exception) {
                $failed[] = "{$shard->server->name}: {$exception->getMessage()}";
            }
        }

        return ['changed' => $changed, 'failed' => $failed];
    }

    /** The jar name without ".disabled", so the same plugin matches whether it's on or off. */
    private static function key(string $file): string
    {
        return preg_replace('/' . preg_quote(PluginSwitch::OFF, '/') . '$/i', '', $file);
    }
}
