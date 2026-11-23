<?php

namespace Ginjer\Network\Models;

use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Services\RegionList;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of servers that run as one Minecraft network: a Velocity proxy, Reef shards that split
 * one world between them, and the Redis that Nodes uses to link the shards.
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property string $world
 * @property ?string $regions
 * @property string $shared_player_data_folder
 * @property int $border_protection
 * @property int $handoff_timeout_seconds
 * @property string $redis_host
 * @property int $redis_port
 * @property ?string $redis_password
 * @property int $redis_database
 * @property string $key_prefix
 * @property Collection<int, NetworkMember> $members
 */
class Network extends Model
{
    protected $table = 'ginjer_networks';

    protected $fillable = [
        'name',
        'description',
        'world',
        'regions',
        'shared_player_data_folder',
        'border_protection',
        'handoff_timeout_seconds',
        'redis_host',
        'redis_port',
        'redis_password',
        'redis_database',
        'key_prefix',
    ];

    protected $hidden = ['redis_password'];

    protected function casts(): array
    {
        return [
            'redis_password' => 'encrypted',
            'border_protection' => 'integer',
            'handoff_timeout_seconds' => 'integer',
            'redis_port' => 'integer',
            'redis_database' => 'integer',
        ];
    }

    /** @return HasMany<NetworkMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(NetworkMember::class);
    }

    /** @return Collection<int, NetworkMember> */
    public function membersWithRole(MemberRole $role): Collection
    {
        return $this->members->filter(fn (NetworkMember $member) => $member->role === $role)->values();
    }

    public function leader(): ?NetworkMember
    {
        return $this->membersWithRole(MemberRole::Shard)->first(fn (NetworkMember $member) => $member->nodes_leader);
    }

    /**
     * The regions as plain arrays, ready for reef.yml.
     *
     * @return list<array<string, mixed>>
     */
    public function parsedRegions(): array
    {
        return RegionList::parse($this->regions ?? '');
    }
}
