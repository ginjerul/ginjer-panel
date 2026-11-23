<?php

namespace Ginjer\Network\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;

/**
 * Turns Bukkit plugins on and off without deleting them: off renames "X.jar" to "X.jar.disabled",
 * which the server doesn't load, and on renames it back. The plugin's data folder stays as it is.
 * Changes take effect on the next restart.
 */
class PluginSwitch
{
    public const DIR = 'plugins';

    public const OFF = '.disabled';

    public function __construct(private DaemonFileRepository $files) {}

    /** @return list<array{file: string, name: string, enabled: bool, size: int, modified: ?string}> */
    public function list(Server $server): array
    {
        $entries = $this->files->setServer($server)->getDirectory(self::DIR);

        return collect($entries)
            ->filter(fn ($e) => ($e['file'] ?? !($e['directory'] ?? false)) && preg_match('/\.jar(' . preg_quote(self::OFF, '/') . ')?$/i', $e['name'] ?? ''))
            ->map(fn ($e) => [
                'file' => $e['name'],
                'name' => self::displayName($e['name']),
                'enabled' => !str_ends_with(strtolower($e['name']), self::OFF),
                'size' => (int) ($e['size'] ?? 0),
                'modified' => $e['modified'] ?? null,
            ])
            ->sortBy(fn ($p) => strtolower($p['name']))
            ->values()
            ->all();
    }

    /** Switch one plugin. Returns the file's new name. */
    public function set(Server $server, string $file, bool $enabled): string
    {
        if ($file === '' || str_contains($file, '/') || str_contains($file, '\\') || !preg_match('/\.jar(' . preg_quote(self::OFF, '/') . ')?$/i', $file)) {
            throw new \InvalidArgumentException('Not a plugin jar: ' . $file);
        }
        $isOn = !str_ends_with(strtolower($file), self::OFF);
        if ($isOn === $enabled) {
            return $file;
        }
        $to = $enabled ? substr($file, 0, -strlen(self::OFF)) : $file . self::OFF;
        $this->files->setServer($server)->renameFiles(self::DIR, [['from' => $file, 'to' => $to]]);

        return $to;
    }

    /**
     * Warnings about switching a plugin off while others still need it (known dependencies of
     * the Nodes stack; other plugins are not checked).
     *
     * @param  list<array{file: string, name: string, enabled: bool}>  $plugins
     * @return list<string>
     */
    public static function warnings(array $plugins): array
    {
        $on = fn (string $needle) => collect($plugins)->contains(fn ($p) => $p['enabled'] && str_contains(strtolower($p['name']), $needle));
        $off = fn (string $needle) => collect($plugins)->contains(fn ($p) => !$p['enabled'] && str_contains(strtolower($p['name']), $needle));
        $warnings = [];
        if ($on('nodes') && $off('kotlin')) {
            $warnings[] = 'Nodes is on but the Kotlin runtime is off: Nodes won\'t start without it.';
        }
        if ($on('ginjermap') && $off('nodes')) {
            $warnings[] = 'GinjerMap is on but Nodes is off: the map will show no towns or wars.';
        }

        return $warnings;
    }

    private static function displayName(string $file): string
    {
        return preg_replace('/\.jar(' . preg_quote(self::OFF, '/') . ')?$/i', '', $file);
    }
}
