<?php

namespace Ginjer\Network\Services\Ops;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkMember;
use Throwable;

/**
 * One console for the whole network: the end of every server's latest.log merged by time, each
 * line labelled with its server, and commands sent to several servers at once.
 */
class NetworkConsole
{
    public const MAX_LOG_BYTES = 8 * 1024 * 1024;

    public function __construct(private DaemonFileRepository $files) {}

    /** @return array<string, Server> label => server: the shards (by shard name), then the proxy */
    public static function targets(Network $network): array
    {
        $out = [];
        $members = $network->loadMissing('members.server')->members
            ->filter(fn (NetworkMember $m) => in_array($m->role, [MemberRole::Shard, MemberRole::Proxy], true))
            ->sortBy(fn (NetworkMember $m) => [$m->role === MemberRole::Proxy ? 1 : 0, $m->shard_name]);
        foreach ($members as $member) {
            $out[$member->role === MemberRole::Proxy ? 'proxy' : ($member->shard_name ?: $member->server->name)] = $member->server;
        }

        return $out;
    }

    /**
     * The newest lines of every server, merged in time order.
     *
     * @param  list<string>|null  $only  labels to show, or null for all
     * @return array{lines: list<array{server: string, time: ?string, text: string}>, errors: array<string, string>}
     */
    public function lines(Network $network, int $max = 300, ?array $only = null): array
    {
        $logs = [];
        $errors = [];
        foreach (self::targets($network) as $label => $server) {
            if ($only !== null && !in_array($label, $only, true)) {
                continue;
            }
            try {
                $logs[$label] = (string) $this->files->setServer($server)->getContent('logs/latest.log', self::MAX_LOG_BYTES);
            } catch (\App\Exceptions\Http\Server\FileSizeTooLargeException) {
                $errors[$label] = 'its log is over 8 MB; restart it to start a new one';
            } catch (Throwable $exception) {
                $errors[$label] = $exception instanceof \Illuminate\Contracts\Filesystem\FileNotFoundException ? 'no log yet' : 'not answering';
            }
        }

        return ['lines' => self::merge($logs, $max), 'errors' => $errors];
    }

    /**
     * Merges logs by their "[HH:MM:SS" times. Lines without a time (stack traces) stay under the
     * line before them; equal times keep each log's own order.
     *
     * @param  array<string, string>  $logs  label => log text
     * @return list<array{server: string, time: ?string, text: string}>
     */
    public static function merge(array $logs, int $max): array
    {
        $all = [];
        $order = 0;
        foreach ($logs as $label => $text) {
            $text = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', str_replace("\r", '', $text)); // colour codes
            $lines = array_slice(array_values(array_filter(explode("\n", $text), fn ($l) => trim($l) !== '')), -$max);
            $time = null;
            $sort = '00:00:00';
            foreach ($lines as $i => $line) {
                if (preg_match('/^\[(\d{2}:\d{2}:\d{2})/', $line, $m)) {
                    $time = $m[1];
                    $sort = $m[1];
                }
                $all[] = ['server' => (string) $label, 'time' => $time, 'text' => $line, 'sort' => $sort, 'order' => $order++, 'i' => $i];
            }
        }
        usort($all, fn ($a, $b) => [$a['sort'], $a['order']] <=> [$b['sort'], $b['order']]);
        $all = array_slice($all, -$max);

        return array_map(fn ($l) => ['server' => $l['server'], 'time' => $l['time'], 'text' => $l['text']], $all);
    }

    /**
     * Sends a command to the chosen servers.
     *
     * @param  list<string>  $labels
     * @return array{sent: list<string>, failed: list<string>}
     */
    public function send(Network $network, string $command, array $labels): array
    {
        $command = ltrim(trim($command), '/');
        $sent = [];
        $failed = [];
        foreach (self::targets($network) as $label => $server) {
            if (!in_array($label, $labels, true)) {
                continue;
            }
            try {
                Console::run($server, $command);
                $sent[] = $label;
            } catch (Throwable) {
                $failed[] = $label;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
