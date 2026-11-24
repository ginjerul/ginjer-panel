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
                                    $this->files->setServer($shards[$target])->putContent($mcc, $bytes);
                                }
                            }
                        }
                    }
                    if (!$changed) {
                        continue;
                    }
                    if ($original !== null) {
                        $this->files->setServer($shards[$target])->putContent($path . self::OLD, $original);
                    }
                    $this->files->setServer($shards[$target])->putContent($path, Anvil::write($chunks));
                }
                if ($progress) {
                    $progress($target, $file);
                }
            }
        }

        return $copied;
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
