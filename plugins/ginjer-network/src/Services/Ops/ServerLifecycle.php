<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Closure;

/** Stops and starts servers and waits until Wings says they really are stopped or running. */
class ServerLifecycle
{
    /** How the waiting sleeps; tests replace it so they don't really wait. */
    public static ?Closure $sleep = null;

    public function __construct(private DaemonServerRepository $daemon) {}

    /** The state straight from Wings ("running", "offline", "starting", "stopping"), or null if Wings doesn't answer. */
    public function state(Server $server): ?string
    {
        return $this->daemon->setServer($server)->getState(5)?->value;
    }

    /** Stops the server and waits until it's offline; kills it if it doesn't stop in time. */
    public function stop(Server $server, int $timeout = 180): bool
    {
        if (in_array($this->state($server), ['offline', 'missing'], true)) {
            return true;
        }
        $this->daemon->setServer($server)->power('stop');
        if ($this->waitFor($server, ['offline'], $timeout)) {
            return true;
        }
        $this->daemon->setServer($server)->power('kill');

        return $this->waitFor($server, ['offline'], 30);
    }

    /** Starts the server and waits until it's running. */
    public function start(Server $server, int $timeout = 300): bool
    {
        if ($this->state($server) === 'running') {
            return true;
        }
        $this->daemon->setServer($server)->power('start');

        return $this->waitFor($server, ['running'], $timeout);
    }

    /**
     * Stops several servers together (all are asked at once, then waited for); one that doesn't
     * stop in time is killed.
     *
     * @param  list<Server>  $servers
     * @return list<Server> the ones that still didn't stop
     */
    public function stopAll(array $servers, int $timeout = 180): array
    {
        $waiting = [];
        foreach ($servers as $server) {
            if (!in_array($this->state($server), ['offline', 'missing'], true)) {
                $this->daemon->setServer($server)->power('stop');
                $waiting[] = $server;
            }
        }
        $failed = [];
        foreach ($waiting as $server) {
            if ($this->waitFor($server, ['offline'], $timeout)) {
                continue;
            }
            $this->daemon->setServer($server)->power('kill');
            if (!$this->waitFor($server, ['offline'], 30)) {
                $failed[] = $server;
            }
        }

        return $failed;
    }

    /**
     * Starts several servers together and waits until each is running.
     *
     * @param  list<Server>  $servers
     * @return list<Server> the ones that didn't come up in time
     */
    public function startAll(array $servers, int $timeout = 300): array
    {
        foreach ($servers as $server) {
            if ($this->state($server) !== 'running') {
                $this->daemon->setServer($server)->power('start');
            }
        }

        return array_values(array_filter($servers, fn (Server $server) => !$this->waitFor($server, ['running'], $timeout)));
    }

    public function restart(Server $server): bool
    {
        return $this->stop($server) && $this->start($server);
    }

    /** @param  list<string>  $states */
    public function waitFor(Server $server, array $states, int $timeout): bool
    {
        $waited = 0;
        while (true) {
            if (in_array($this->state($server), $states, true)) {
                return true;
            }
            if ($waited >= $timeout) {
                return false;
            }
            self::sleep(3);
            $waited += 3;
        }
    }

    public static function sleep(int $seconds): void
    {
        $sleep = self::$sleep ?? fn (int $s) => sleep($s);
        $sleep($seconds);
    }
}
