<?php

namespace Ginjer\Network\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Console commands run on a network's servers at set times: announcements, events, anything.
 *
 * @property int $id
 * @property int $network_id
 * @property string $name
 * @property string $commands one per line
 * @property string $targets shards, proxy, all
 * @property ?string $at_time HH:MM
 * @property ?string $days ISO weekdays, "1,3,5"
 * @property ?int $every_minutes
 * @property bool $enabled
 * @property ?\Illuminate\Support\Carbon $last_run_at
 */
class NetworkSchedule extends Model
{
    protected $table = 'ginjer_network_schedules';

    protected $fillable = ['network_id', 'name', 'commands', 'targets', 'at_time', 'days', 'every_minutes', 'enabled', 'last_run_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'last_run_at' => 'datetime', 'every_minutes' => 'integer'];
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /** @return list<string> */
    public function commandLines(): array
    {
        return array_values(array_filter(array_map(fn ($l) => ltrim(trim($l), '/'), preg_split('/\R/', $this->commands) ?: [])));
    }

    /** @return list<int> */
    public function dayList(): array
    {
        return array_values(array_map('intval', array_filter(explode(',', (string) $this->days), 'strlen')));
    }
}
