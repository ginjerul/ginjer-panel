<?php

namespace Ginjer\Network\Policies;

use App\Models\User;
use App\Policies\DefaultAdminPolicies;
use Ginjer\Network\Models\Network;

/**
 * Network permissions for admin roles (Admin → Roles → Network). Root admins can do everything.
 *
 *  - viewList / view: the networks, status, history, activity
 *  - control: power, rolling restarts and updates, maintenance mode, clearing items, backups
 *  - console: the network console (commands on every shard)
 *  - players: player lookup, kick, ban and move
 *  - update: settings, servers, config push and sync, plugins, the database, rebalancing, test copies
 *  - create / delete
 */
class NetworkPolicy
{
    use DefaultAdminPolicies;

    public const PERMISSIONS = ['viewList', 'view', 'create', 'update', 'delete', 'control', 'console', 'players'];

    protected string $modelName = 'network';

    public function control(User $user, Network $network): bool
    {
        return $user->can('control network', $network);
    }

    public function console(User $user, Network $network): bool
    {
        return $user->can('console network', $network);
    }

    public function players(User $user, Network $network): bool
    {
        return $user->can('players network', $network);
    }
}
