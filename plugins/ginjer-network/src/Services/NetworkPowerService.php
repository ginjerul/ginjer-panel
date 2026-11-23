<?php

namespace Ginjer\Network\Services;

use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Jobs\SendPowerAction;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Illuminate\Support\Collection;

/**
 * Starts and stops a whole network in the order it needs:
 *   start:   Redis, then the Nodes leader, then the other shards, then the proxy
 *   stop:    the proxy, then the followers, then the leader, then Redis
 * so followers always find the leader's state in Redis, and players are only let in once every
 * shard is up. Restart is a stop followed by a start.
 */
class NetworkPowerService
{
    /** @return Collection<int, Collection<int, NetworkMember>> stages, first to last */
    public function startStages(Network $network): Collection
    {
        $network->loadMissing('members.server');
        $shards = $network->membersWithRole(MemberRole::Shard);

        return collect([
            $network->membersWithRole(MemberRole::Redis),
            $shards->filter(fn (NetworkMember $m) => $m->nodes_leader)->values(),
            $shards->reject(fn (NetworkMember $m) => $m->nodes_leader)->values()->concat($network->membersWithRole(MemberRole::Other)),
            $network->membersWithRole(MemberRole::Proxy),
        ])->filter(fn (Collection $stage) => $stage->isNotEmpty())->values();
    }

    /**
     * @param  'start'|'stop'|'restart'|'kill'  $action
     * @return int how many servers were sent the action
     */
    public function run(Network $network, string $action): int
    {
        $delay = max(0, (int) config('ginjer-network.start_stage_delay', 10));
        $stages = $this->startStages($network);
        $count = $stages->sum(fn (Collection $stage) => $stage->count());

        if ($action === 'kill') {
            $this->dispatchStages($stages, 'kill', 0, 0);

            return $count;
        }

        $offset = 0;
        if ($action === 'stop' || $action === 'restart') {
            $offset = $this->dispatchStages($stages->reverse()->values(), 'stop', 0, $delay);
        }
        if ($action === 'start' || $action === 'restart') {
            // after a stop, give the last stage time to save and shut down before starting again
            $this->dispatchStages($stages, 'start', $action === 'restart' ? $offset + $delay * 2 : 0, $delay);
        }

        return $count;
    }

    /**
     * @param  Collection<int, Collection<int, NetworkMember>>  $stages
     * @return int the delay of the last stage, in seconds
     */
    private function dispatchStages(Collection $stages, string $action, int $start, int $step): int
    {
        $at = $start;
        foreach ($stages as $i => $stage) {
            $at = $start + $i * $step;
            foreach ($stage as $member) {
                $job = SendPowerAction::dispatch($member->server_id, $action);
                if ($at > 0) {
                    $job->delay(now()->addSeconds($at));
                }
            }
        }

        return $at;
    }
}
