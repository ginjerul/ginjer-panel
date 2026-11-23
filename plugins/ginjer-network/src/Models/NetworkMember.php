<?php

namespace Ginjer\Network\Models;

use App\Models\Server;
use Ginjer\Network\Enums\MemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $network_id
 * @property int $server_id
 * @property MemberRole $role
 * @property ?string $shard_name
 * @property bool $nodes_leader
 * @property Network $network
 * @property Server $server
 */
class NetworkMember extends Model
{
    protected $table = 'ginjer_network_members';

    protected $fillable = [
        'network_id',
        'server_id',
        'role',
        'shard_name',
        'nodes_leader',
    ];

    protected static function booted(): void
    {
        static::saving(function (NetworkMember $member) {
            if ($member->role !== MemberRole::Shard) {
                $member->shard_name = null;
                $member->nodes_leader = false;
            }
        });

        // only one leader per network
        static::saved(function (NetworkMember $member) {
            if ($member->nodes_leader) {
                static::query()
                    ->where('network_id', $member->network_id)
                    ->whereKeyNot($member->getKey())
                    ->where('nodes_leader', true)
                    ->update(['nodes_leader' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'nodes_leader' => 'boolean',
        ];
    }

    /** @return BelongsTo<Network, $this> */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** The name other shards and Velocity know this server by. */
    public function displayName(): string
    {
        return $this->shard_name ?: $this->server->name;
    }
}
