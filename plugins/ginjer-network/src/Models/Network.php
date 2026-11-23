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
 * @property ?string $reef_jar_url
 * @property ?string $nodes_repo
 * @property string $nodes_release
 * @property ?string $github_token
 * @property int $restart_settle_seconds
 * @property bool $health_alerts
 * @property int $lag_tps
 * @property int $lag_minutes
 * @property bool $restart_on_lag
 * @property ?int $backup_every_hours
 * @property int $backup_keep
 * @property ?\Illuminate\Support\Carbon $last_backup_at
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
        'reef_jar_url',
        'nodes_repo',
        'nodes_release',
        'github_token',
        'restart_settle_seconds',
        'health_alerts',
        'lag_tps',
        'lag_minutes',
        'restart_on_lag',
        'backup_every_hours',
        'backup_keep',
        'last_backup_at',
    ];

    protected $hidden = ['redis_password', 'github_token'];

    protected function casts(): array
    {
        return [
            'redis_password' => 'encrypted',
            'github_token' => 'encrypted',
            'health_alerts' => 'boolean',
            'restart_on_lag' => 'boolean',
            'last_backup_at' => 'datetime',
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
