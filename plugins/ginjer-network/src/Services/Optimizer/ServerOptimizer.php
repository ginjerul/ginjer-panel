<?php

namespace Ginjer\Network\Services\Optimizer;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\NetworkMember;
use Ginjer\Network\Models\OptimizerBackup;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\ConnectionException;

/** Runs the optimizer against a real server: reads its files through Wings, applies changes with a backup, and reverts. */
class ServerOptimizer
{
    public function __construct(private DaemonFileRepository $files, private DaemonServerRepository $daemon) {}

    /** @return array<string, ?string> path => content, null when the file doesn't exist */
    public function readFiles(Server $server): array
    {
        $out = [];
        foreach (Optimizer::files() as $path) {
            try {
                $out[$path] = (string) $this->files->setServer($server)->getContent($path);
            } catch (FileNotFoundException) {
                $out[$path] = null;
            }
        }

        return $out;
    }

    /** @param  array<string, ?string>  $files */
    public function context(Server $server, array $files): Context
    {
        $member = NetworkMember::with('network.members')->where('server_id', $server->id)->first();
        $behindProxy = $member !== null && $member->network->members->contains(fn (NetworkMember $m) => $m->role === MemberRole::Proxy);
        $shardRole = $member?->role === MemberRole::Shard ? ($member->nodes_leader ? 'leader' : 'follower') : null;

        return new Context(
            memoryMb: (int) $server->memory,
            hasNodes: ($files[Rules::NODES] ?? null) !== null,
            hasReef: ($files[Rules::REEF] ?? null) !== null,
            behindProxy: $behindProxy,
            shardRole: $shardRole,
        );
    }

    /** @return list<array<string, mixed>> */
    public function analyze(Server $server, string $profile, bool $canChangeStartup): array
    {
        $files = $this->readFiles($server);

        return Optimizer::analyze($files, (string) $server->startup, $this->context($server, $files), $profile, $canChangeStartup);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string> the rules that were applied
     */
    public function apply(Server $server, string $profile, array $ids, bool $canChangeStartup, ?int $userId): array
    {
        $files = $this->readFiles($server);
        $result = Optimizer::apply($files, (string) $server->startup, $this->context($server, $files), $profile, $ids, $canChangeStartup);
        if ($result['applied'] === []) {
            return [];
        }

        // back up exactly what is about to change
        OptimizerBackup::create([
            'server_id' => $server->id,
            'user_id' => $userId,
            'profile' => $profile,
            'changes' => $result['applied'],
            'files' => array_intersect_key($files, $result['files']),
            'startup' => $result['startup'] !== null ? $server->startup : null,
        ]);

        foreach ($result['files'] as $path => $content) {
            $this->files->setServer($server)->putContent($path, $content);
        }
        if ($result['startup'] !== null) {
            $server->forceFill(['startup' => $result['startup']])->save();
            $this->sync($server);
        }

        return $result['applied'];
    }

    public function lastBackup(Server $server): ?OptimizerBackup
    {
        return OptimizerBackup::where('server_id', $server->id)->latest('id')->first();
    }

    /** Puts back what the last apply changed. Returns how many files (and the startup command) were restored. */
    public function revert(Server $server): int
    {
        $backup = $this->lastBackup($server);
        if ($backup === null) {
            return 0;
        }

        foreach ($backup->files as $path => $content) {
            $this->files->setServer($server)->putContent($path, $content);
        }
        if ($backup->startup !== null) {
            $server->forceFill(['startup' => $backup->startup])->save();
            $this->sync($server);
        }
        $count = count($backup->files) + ($backup->startup !== null ? 1 : 0);
        $backup->delete();

        return $count;
    }

    /** Wings reads the startup command from the panel when the server boots; syncing now is only a courtesy. */
    private function sync(Server $server): void
    {
        try {
            $this->daemon->setServer($server)->sync();
        } catch (ConnectionException) {
            // applied on the next start anyway
        }
    }
}
