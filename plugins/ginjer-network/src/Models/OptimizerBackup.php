<?php

namespace Ginjer\Network\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property ?int $user_id
 * @property string $profile
 * @property list<string> $changes
 * @property array<string, string> $files
 * @property ?string $startup
 */
class OptimizerBackup extends Model
{
    protected $table = 'ginjer_optimizer_backups';

    protected $fillable = ['server_id', 'user_id', 'profile', 'changes', 'files', 'startup'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'files' => 'array'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
