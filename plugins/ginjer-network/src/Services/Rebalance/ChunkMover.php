<?php

namespace Ginjer\Network\Services\Rebalance;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

/**
 * When borders move, the land changes shard. Every shard has its own copy of the world and only
 * keeps its own area up to date, so the chunks that change hands are copied from the old owner's
 * world files into the new owner's: blocks (region/), entities (entities/) and points of interest
 * (poi/). Only with the shards stopped. Each changed file is kept as "<file>.ginjer-old".
 */
class ChunkMover
{
    public const DIRS = ['region', 'entities', 'poi'];

    public const MAX_FILE = 512 * 1024 * 1024;

    public const OLD = '.ginjer-old';

    /** @var list<array{shard: string, path: string, backup: bool}> every file the last apply() wrote; backup: the old one is at path.ginjer-old */
    public array $written = [];

    public function __construct(private DaemonFileRepository $files) {}

    /**
     * Which chunks go where: target shard => region file => source shard => chunk indexes.
     *
     * @param  array<string, Server>  $shards  shard name => server
     * @return array{moves: array<string, array<string, array<string, list<int>>>>, chunks: int, files: int}
     */
    public function plan(array $shards, string $world, ShardMap $before, ShardMap $after): array
    {
        $moves = [];
        $chunks = 0;
        foreach ($shards as $source => $server) {
            $entries = rescue(fn () => $this->files->setServer($server)->getDirectory("$world/region"), [], false);
            foreach ($entries as $entry) {
                $name = (string) ($entry['name'] ?? '');
                if (!($entry['file'] ?? false) || !preg_match('/^r\.-?\d+\.-?\d+\.mca$/', $name) || ($entry['size'] ?? 0) === 0) {
                    continue;
                }
                [$rx, $rz] = Anvil::regionOf($name);
                for ($i = 0; $i < 1024; $i++) {
                    $cx = $rx * 32 + ($i % 32);
                    $cz = $rz * 32 + intdiv($i, 32);
                    if ($before->chunkOwner($cx, $cz) !== $source) {
                        continue; // only the owner's copy is up to date
                    }
                    $target = $after->chunkOwner($cx, $cz);
                    if ($target === null || $target === $source || !isset($shards[$target])) {
                        continue;
                    }
                    $moves[$target][$name][$source][] = $i;
                    $chunks++;
                }
            }
        }
        $files = array_sum(array_map('count', $moves));

        return ['moves' => $moves, 'chunks' => $chunks, 'files' => $files];
    }

    /**
     * Copies the planned chunks. Returns how many chunks were copied (chunks a source never
     * generated are skipped).
     *
     * @param  array<string, Server>  $shards
     * @param  array<string, array<string, array<string, list<int>>>>  $moves
     */
    public function apply(array $shards, string $world, array $moves, ?callable $progress = null): int
    {
        $copied = 0;
        $this->written = [];
        foreach ($moves as $target => $files) {
            foreach ($files as $file => $sources) {
                foreach (self::DIRS as $dir) {
                    $path = "$world/$dir/$file";
                    $original = $this->read($shards[$target], $path);
                    $chunks = Anvil::read((string) $original);
                    $changed = false;
                    foreach ($sources as $source => $indexes) {
                        $from = Anvil::read((string) $this->read($shards[$source], $path));
                        [$rx, $rz] = Anvil::regionOf($file);
                        foreach ($indexes as $i) {
                            if (!isset($from[$i])) {
                                continue;
                            }
                            $chunks[$i] = $from[$i];
                            $changed = true;
                            if ($dir === 'region') {
                                $copied++;
                            }
                            if ($from[$i]['external']) {
                                // the chunk's data is in its own file next to the region file
                                $mcc = "$world/$dir/c." . ($rx * 32 + $i % 32) . '.' . ($rz * 32 + intdiv($i, 32)) . '.mcc';
                                $bytes = $this->read($shards[$source], $mcc);
                                if ($bytes !== null) {
                                    $this->write($shards, $target, $mcc, $bytes);
                                }
                            }
                        }
                    }
                    if (!$changed) {
                        continue;
                    }
                    $this->write($shards, $target, $path, Anvil::write($chunks), $original);
                }
                if ($progress) {
                    $progress($target, $file);
                }
            }
        }

        return $copied;
    }

    /** Writes a file, keeping the one it replaces as .ginjer-old, and remembers it for undo. */
    private function write(array $shards, string $target, string $path, string $bytes, string|false|null $original = false): void
    {
        $server = $shards[$target];
        if ($original === false) {
            $original = $this->read($server, $path);
        }
        if (collect($this->written)->contains(fn ($w) => $w['shard'] === $target && $w['path'] === $path)) {
            $original = null; // written twice: the first write already kept the real original
        } elseif ($original !== null) {
            $this->files->setServer($server)->putContent($path . self::OLD, $original);
        }
        $this->files->setServer($server)->putContent($path, $bytes);
        if (!collect($this->written)->contains(fn ($w) => $w['shard'] === $target && $w['path'] === $path)) {
            $this->written[] = ['shard' => $target, 'path' => $path, 'backup' => $original !== null];
        }
    }

    /**
     * Puts back what apply() changed: the .ginjer-old files over the new ones, and files that
     * didn't exist before removed. Returns how many files were put back.
     *
     * @param  array<string, Server>  $shards
     * @param  list<array{shard: string, path: string, backup: bool}>  $written
     */
    public function undo(array $shards, array $written): int
    {
        $count = 0;
        foreach ($written as $w) {
            $server = $shards[$w['shard']] ?? null;
            if ($server === null) {
                continue;
            }
            $dir = '/' . trim(dirname($w['path']), '/.');
            $name = basename($w['path']);
            $this->files->setServer($server)->deleteFiles($dir, [$name]);
            if ($w['backup']) {
                $this->files->setServer($server)->renameFiles($dir, [['from' => $name . self::OLD, 'to' => $name]]);
            }
            $count++;
        }

        return $count;
    }

    private function read(Server $server, string $path): ?string
    {
        try {
            return (string) $this->files->setServer($server)->getContent($path, self::MAX_FILE);
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
