<?php

namespace Ginjer\Network\Services\Ops;

use App\Enums\ServerState;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\DownloadLinkService;
use App\Services\Backups\InitiateBackupService;
use App\Services\Backups\ServerRestoreService;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkBackup;
use Predis\Client;
use RuntimeException;
use Throwable;

/**
 * Backs up every server of a network at the same moment, so a restore never mixes towns from one
 * time with worlds from another, and restores them together.
 */
class NetworkBackups
{
    public function __construct(
        private ServerLifecycle $lifecycle,
        private InitiateBackupService $initiate,
        private DeleteBackupService $delete,
        private DownloadLinkService $links,
        private ServerRestoreService $restoreService,
        private DaemonBackupRepository $daemonBackups,
    ) {}

    /**
     * Saves Nodes, the worlds and Redis, then starts a Pelican backup of every member.
     *
     * @return array{backup: NetworkBackup, problems: list<string>}
     */
    public function take(Network $network, ?string $name = null, bool $prune = true): array
    {
        $network->loadMissing('members.server');
        $name ??= 'Network backup ' . now()->format('Y-m-d H:i');
        $problems = [];

        // 1. save everything that lives in memory
        foreach ($network->membersWithRole(MemberRole::Shard) as $shard) {
            if ($this->lifecycle->state($shard->server) !== 'running') {
                continue;
            }
            try {
                $shard->server->send(array_values(array_filter([$shard->nodes_leader ? 'nodesadmin save' : null, 'save-all flush'])));
            } catch (Throwable $exception) {
                $problems[] = "Couldn't ask {$shard->server->name} to save: {$exception->getMessage()}";
            }
        }
        $this->snapshotRedis($network, $problems);
        ServerLifecycle::sleep(10); // let the saves reach the disk

        // 2. one backup per member, all at once
        $uuids = [];
        foreach ($network->members as $member) {
            try {
                $backup = $this->initiate->setIsLocked(false)->handle($member->server, $name);
                $uuids[(string) $member->server_id] = $backup->uuid;
            } catch (Throwable $exception) {
                $problems[] = "{$member->server->name}: " . ($exception instanceof \App\Exceptions\Service\Backup\TooManyBackupsException
                    ? "it's at its backup limit ({$member->server->backup_limit}). Raise the limit or keep fewer network backups."
                    : $exception->getMessage());
            }
        }

        $group = NetworkBackup::create([
            'network_id' => $network->id,
            'name' => $name,
            'backups' => $uuids,
            'status' => $uuids === [] ? 'failed' : 'running',
            'note' => $problems === [] ? null : implode(' ', $problems),
        ]);
        $network->update(['last_backup_at' => now()]);
        if ($prune) {
            $this->prune($network);
        }

        return ['backup' => $group, 'problems' => $problems];
    }

    /**
     * A network backup before something that's hard to undo, waited for until it's finished. Null
     * when the network has this turned off, or for a test copy. Throws if the backup fails, so the
     * risky part never starts without one.
     */
    public function safetyBackup(Network $network, string $before, int $timeoutSeconds = 3600): ?NetworkBackup
    {
        if (!$network->backup_before_risky || $network->test_of_network_id !== null) {
            return null;
        }
        // no pruning: a restore's own backup could be the oldest kept one
        $group = $this->take($network, "Before $before " . now()->format('Y-m-d H:i'), false)['backup'];
        for ($waited = 0; $group->status === 'running' && $waited < $timeoutSeconds; $waited += 5) {
            ServerLifecycle::sleep(5);
            $group->refresh()->refreshStatus();
        }
        if ($group->status !== 'done') {
            throw new \RuntimeException("The backup before $before " . ($group->status === 'running' ? 'took too long' : 'failed' . ($group->note ? " ({$group->note})" : ''))
                . '. Nothing else was done. Turn off "Back up first" under Backups on the network to go ahead without one.');
        }

        return $group;
    }

    /** Deletes the oldest network backups beyond the network's "keep" setting, with their server backups. */
    public function prune(Network $network): int
    {
        $old = NetworkBackup::where('network_id', $network->id)->orderByDesc('id')->skip(max(1, $network->backup_keep))->take(PHP_INT_MAX)->get();
        foreach ($old as $group) {
            $this->remove($group);
        }

        return $old->count();
    }

    public function remove(NetworkBackup $group): void
    {
        foreach ($group->serverBackups() as $backup) {
            rescue(fn () => $this->delete->handle($backup), report: false);
        }
        $group->delete();
    }

    /**
     * Restores every server of the group. The servers are stopped first; each is restored exactly
     * as it was (files that weren't in the backup are removed). Start the network afterwards.
     *
     * @return list<string> problems (servers that couldn't be restored)
     */
    public function restore(NetworkBackup $group, User $user): array
    {
        $group->refreshStatus();
        if ($group->status !== 'done') {
            throw new RuntimeException($group->status === 'running' ? 'This backup hasn\'t finished yet.' : 'Some of this backup failed, so it can\'t be restored as a whole.');
        }
        $backups = $group->serverBackups();
        foreach ($backups as $backup) {
            if (!$this->lifecycle->stop($backup->server)) {
                throw new RuntimeException("{$backup->server->name} didn't stop, so nothing was restored.");
            }
        }

        $problems = [];
        foreach ($backups as $backup) {
            /** @var Backup $backup */
            $server = $backup->server->fresh();
            try {
                if ($server->status !== null) {
                    throw new RuntimeException('it is busy (' . $server->status->value . ').');
                }
                $this->restoreService->whileRestoring($backup, function () use ($backup, $server, $user) {
                    $url = $this->links->handle($backup, $user);
                    $server->update(['status' => ServerState::RestoringBackup]);
                    $this->daemonBackups->setServer($server)->restore($backup, $url, true);
                });
            } catch (Throwable $exception) {
                $problems[] = "{$server->name}: {$exception->getMessage()}";
            }
        }

        return $problems;
    }

    /** @param  list<string>  $problems */
    private function snapshotRedis(Network $network, array &$problems): void
    {
        if (!$network->membersWithRole(MemberRole::Redis)->count()) {
            return;
        }
        try {
            $redis = new Client([
                'host' => $network->redis_host, 'port' => $network->redis_port, 'database' => $network->redis_database,
                'password' => filled($network->redis_password) ? $network->redis_password : null,
                'timeout' => 3, 'read_write_timeout' => 30,
            ]);
            $before = (int) $redis->lastsave();
            $redis->bgsave();
            for ($i = 0; $i < 10 && (int) $redis->lastsave() === $before; $i++) {
                ServerLifecycle::sleep(1);
            }
            $redis->disconnect();
        } catch (Throwable $exception) {
            $problems[] = "Couldn't snapshot Redis first ({$exception->getMessage()}); its backup has the last snapshot Redis saved on its own.";
        }
    }
}
