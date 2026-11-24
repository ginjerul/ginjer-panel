<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Throwable;

/**
 * Keeps chosen config files the same on every shard: compares each shard's copy with the source
 * shard's, and copies the source's over the ones that differ (the old copy is kept next to it as
 * "<file>.ginjer-old"). Folders are synced file by file.
 *
 * Files that must differ per shard are never synced: Reef's sharding settings, server.properties
 * (ports), everything of Nodes (its config has the shard's role; its data belongs to the leader),
 * worlds, logs and jars (plugins are switched and updated elsewhere).
 */
class ConfigSync
{
    public const MAX_FILES = 300;

    public const MAX_BYTES = 2 * 1024 * 1024;

    public const OLD = '.ginjer-old';

    public function __construct(private DaemonFileRepository $files) {}

    /** Why a path can't be synced, or null if it can. */
    public static function protectedReason(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $lower = strtolower($path);

        return match (true) {
            $path === '' || str_contains($path, '..') => 'not a valid path',
            in_array($lower, ['reef.yml', 'server.properties'], true) => 'it holds settings that differ per shard (shard name, ports)',
            $lower === 'plugins/nodes' || str_starts_with($lower, 'plugins/nodes/') => 'Nodes keeps each shard\'s role and the leader\'s data there',
            str_ends_with($lower, '.jar') || str_ends_with($lower, '.jar.disabled') => 'plugins are switched and updated on the Plugins and Update pages',
            preg_match('#^(world[^/]*|logs|cache|crash-reports|libraries|versions)(/|$)#', $lower) === 1 => 'worlds, logs and server files aren\'t config',
            str_ends_with($lower, self::OLD) => 'it\'s a sync backup',
            default => null,
        };
    }

    /** @return list<string> the paths saved on the network */
    public static function paths(Network $network): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($line) => trim(str_replace('\\', '/', $line), " \t/"),
            preg_split('/\R/', (string) $network->synced_files) ?: []
        ))));
    }

    /** The network's source shard: the one chosen, or the leader. */
    public static function source(Network $network): ?Server
    {
        $shards = $network->loadMissing('members.server')->membersWithRole(MemberRole::Shard);
        $chosen = $shards->firstWhere('server_id', $network->sync_source_server_id) ?? $shards->firstWhere('nodes_leader', true) ?? $shards->first();

        return $chosen?->server;
    }

    /**
     * Every file the paths cover on the source (folders expanded), skipping protected ones.
     *
     * @param  list<string>  $paths
     * @return array{files: list<string>, skipped: list<string>}
     */
    public function expand(Server $source, array $paths): array
    {
        $files = [];
        $skipped = [];
        foreach ($paths as $path) {
            if (($reason = self::protectedReason($path)) !== null) {
                $skipped[] = "$path: $reason";

                continue;
            }
            $this->walk($source, $path, $files, $skipped);
        }

        return ['files' => array_values(array_unique($files)), 'skipped' => $skipped];
    }

    /** @param list<string> $files @param list<string> $skipped */
    private function walk(Server $server, string $path, array &$files, array &$skipped, int $depth = 0): void
    {
        if (count($files) >= self::MAX_FILES || $depth > 6) {
            return;
        }
        $parent = trim(dirname($path), './');
        $name = basename($path);
        try {
            $entries = $this->files->setServer($server)->getDirectory($parent === '' ? '/' : $parent);
        } catch (Throwable) {
            $skipped[] = "$path: its folder can't be read";

            return;
        }
        $entry = collect($entries)->firstWhere('name', $name);
        if ($entry === null) {
            $skipped[] = "$path: not on the source shard";

            return;
        }
        if (!($entry['directory'] ?? false)) {
            if (($entry['size'] ?? 0) > self::MAX_BYTES) {
                $skipped[] = "$path: bigger than 2 MB";
            } elseif (self::protectedReason($path) === null) {
                $files[] = $path;
            }

            return;
        }
        foreach ($this->files->setServer($server)->getDirectory($path) as $child) {
            $this->walk($server, $path . '/' . $child['name'], $files, $skipped, $depth + 1);
        }
    }

    /**
     * Which shards have the same copy of each file as the source.
     *
     * @return array{source: ?string, files: list<array{path: string, same: list<string>, different: list<string>, missing: list<string>}>, skipped: list<string>}
     */
    public function compare(Network $network): array
    {
        $source = self::source($network);
        if ($source === null) {
            return ['source' => null, 'files' => [], 'skipped' => []];
        }
        ['files' => $paths, 'skipped' => $skipped] = $this->expand($source, self::paths($network));
        $targets = $this->targets($network, $source);

        $rows = [];
        foreach ($paths as $path) {
            $want = $this->read($source, $path);
            $row = ['path' => $path, 'same' => [], 'different' => [], 'missing' => []];
            foreach ($targets as $name => $server) {
                $have = $this->read($server, $path);
                $row[$have === null ? 'missing' : ($have === $want ? 'same' : 'different')][] = $name;
            }
            $rows[] = $row;
        }

        return ['source' => $source->name, 'files' => $rows, 'skipped' => $skipped];
    }

    /**
     * Copies the source's files to every shard whose copy differs or is missing.
     *
     * @return array{copied: int, failed: list<string>, skipped: list<string>}
     */
    public function sync(Network $network): array
    {
        $source = self::source($network);
        if ($source === null) {
            return ['copied' => 0, 'failed' => ['This network has no shards.'], 'skipped' => []];
        }
        ['files' => $paths, 'skipped' => $skipped] = $this->expand($source, self::paths($network));
        $copied = 0;
        $failed = [];
        foreach ($paths as $path) {
            $want = $this->read($source, $path);
            if ($want === null) {
                continue;
            }
            foreach ($this->targets($network, $source) as $name => $server) {
                try {
                    $have = $this->read($server, $path);
                    if ($have === $want) {
                        continue;
                    }
                    if ($have !== null) {
                        $this->files->setServer($server)->putContent($path . self::OLD, $have); // one step of undo
                    }
                    $this->files->setServer($server)->putContent($path, $want);
                    $copied++;
                } catch (Throwable $exception) {
                    $failed[] = "$path on $name: {$exception->getMessage()}";
                }
            }
        }

        return ['copied' => $copied, 'failed' => $failed, 'skipped' => $skipped];
    }

    /** @return array<string, Server> shard name => server, without the source */
    private function targets(Network $network, Server $source): array
    {
        $out = [];
        foreach ($network->membersWithRole(MemberRole::Shard) as $member) {
            if ($member->server_id !== $source->id) {
                $out[$member->shard_name ?: $member->server->name] = $member->server;
            }
        }

        return $out;
    }

    private function read(Server $server, string $path): ?string
    {
        try {
            return (string) $this->files->setServer($server)->getContent($path, self::MAX_BYTES);
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
