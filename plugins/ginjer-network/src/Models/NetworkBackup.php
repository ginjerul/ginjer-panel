<?php

namespace Ginjer\Network\Models;

use App\Models\Backup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One backup of a whole network: a Pelican backup of every member server, taken together.
 *
 * @property int $id
 * @property int $network_id
 * @property string $name
 * @property array<int|string, string> $backups server id => backup uuid
 * @property string $status running, done, failed
 * @property ?string $note
 * @property \Illuminate\Support\Carbon $created_at
 * @property Network $network
 */
class NetworkBackup extends Model
{
    protected $table = 'ginjer_network_backups';

    protected $fillable = ['network_id', 'name', 'backups', 'status', 'note'];

    protected function casts(): array
    {
        return ['backups' => 'array'];
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /** @return Collection<int, Backup> */
    public function serverBackups(): Collection
    {
        return Backup::query()->whereIn('uuid', array_values($this->backups ?? []))->with('server')->get();
    }

    /** Marks the group done or failed once every server backup has finished. */
    public function refreshStatus(): void
    {
        if ($this->status !== 'running') {
            return;
        }
        $backups = $this->serverBackups();
        if ($backups->count() < count($this->backups) || $backups->contains(fn (Backup $b) => $b->completed_at === null)) {
            return;
        }
        $failed = $backups->filter(fn (Backup $b) => !$b->is_successful);
        $this->update($failed->isEmpty()
            ? ['status' => 'done']
            : ['status' => 'failed', 'note' => 'Failed on ' . $failed->map(fn (Backup $b) => $b->server?->name)->implode(', ') . '.']);
    }
}
