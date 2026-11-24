<?php

namespace Ginjer\Network\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a network's activity log: who did what, or what the panel noticed.
 *
 * @property int $id
 * @property int $network_id
 * @property ?int $user_id
 * @property string $kind
 * @property string $level
 * @property string $title
 * @property ?string $body
 * @property \Illuminate\Support\Carbon $created_at
 */
class NetworkEvent extends Model
{
    public const KEEP_DAYS = 90;

    public const UPDATED_AT = null;

    protected $table = 'ginjer_network_events';

    protected $fillable = ['network_id', 'user_id', 'kind', 'level', 'title', 'body'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
