<?php

namespace Ginjer\Network\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $network_id
 * @property string $shard
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property ?float $tps
 * @property ?float $mspt
 * @property ?int $players
 * @property ?int $memory_mb
 * @property ?int $memory_limit_mb
 * @property ?float $cpu
 */
class ShardMetric extends Model
{
    public const KEEP_DAYS = 7;

    public $timestamps = false;

    protected $table = 'ginjer_shard_metrics';

    protected $fillable = ['network_id', 'shard', 'recorded_at', 'tps', 'mspt', 'players', 'memory_mb', 'memory_limit_mb', 'cpu'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'tps' => 'float', 'mspt' => 'float', 'cpu' => 'float'];
    }
}
