<?php

namespace Ginjer\Network\Services\Rebalance;

use Ginjer\Network\Models\Network;
use Illuminate\Support\Facades\DB;

/**
 * Once a minute (with the health check): adds where every player is to a per-day map of
 * 128-block squares. A player on a lagging shard counts for more, so the rebalancer gives that
 * shard less to do.
 */
class LoadSampler
{
    public const CELL = 128;

    public const KEEP_DAYS = 14;

    /**
     * @param  list<array{shard: string, where: ?string}>  $players
     * @param  array<string, ?float>  $mspt  shard => average tick time
     * @return array<string, float> "x:z" => weight added
     */
    public static function cells(array $players, string $world, array $mspt): array
    {
        $cells = [];
        foreach ($players as $player) {
            if (!preg_match('/^(\S+) (-?\d+) -?\d+ (-?\d+)$/', (string) $player['where'], $m) || $m[1] !== $world) {
                continue;
            }
            $tick = $mspt[$player['shard']] ?? null;
            $weight = $tick === null ? 1.0 : max(0.5, min(3.0, $tick / 25));
            $key = intdiv((int) $m[2] - (((int) $m[2] < 0) ? self::CELL - 1 : 0), self::CELL) . ':' . intdiv((int) $m[3] - (((int) $m[3] < 0) ? self::CELL - 1 : 0), self::CELL);
            $cells[$key] = ($cells[$key] ?? 0) + $weight;
        }

        return $cells;
    }

    /** @param  array<string, float>  $cells */
    public static function store(Network $network, array $cells): void
    {
        $day = now()->toDateString();
        foreach ($cells as $key => $weight) {
            [$x, $z] = array_map('intval', explode(':', $key));
            $updated = DB::table('ginjer_load_cells')->where(['network_id' => $network->id, 'day' => $day, 'cell_x' => $x, 'cell_z' => $z])->increment('weight', $weight);
            if ($updated === 0) {
                DB::table('ginjer_load_cells')->insert(['network_id' => $network->id, 'day' => $day, 'cell_x' => $x, 'cell_z' => $z, 'weight' => $weight]);
            }
        }
        if (now()->minute === 0) {
            DB::table('ginjer_load_cells')->where('day', '<', now()->subDays(self::KEEP_DAYS)->toDateString())->delete();
        }
    }

    /** @return list<array{0: int, 1: int, 2: float}> [x, z, weight] at each square's centre, over the last $days */
    public static function points(Network $network, int $days = 7): array
    {
        return DB::table('ginjer_load_cells')
            ->where('network_id', $network->id)
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->groupBy('cell_x', 'cell_z')
            ->selectRaw('cell_x, cell_z, SUM(weight) as w')
            ->get()
            ->map(fn ($r) => [(int) $r->cell_x * self::CELL + self::CELL / 2, (int) $r->cell_z * self::CELL + self::CELL / 2, (float) $r->w])
            ->all();
    }
}
