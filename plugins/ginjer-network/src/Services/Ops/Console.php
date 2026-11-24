<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Server;
use RuntimeException;

/**
 * Sends console commands and says when they didn't arrive. Pelican's Server::send() doesn't
 * throw when Wings refuses (for example because the server is offline), so callers counting
 * "sent to N servers" need this.
 */
final class Console
{
    /** @param  string|list<string>  $command */
    public static function run(Server $server, string|array $command): void
    {
        $response = $server->send($command);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($status === 502 || $status === 409 ? "{$server->name} isn't running" : "Wings refused the command for {$server->name} (HTTP $status)");
        }
    }
}
