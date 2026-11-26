<?php

namespace Ginjer\Network\Services\Status;

use Ginjer\Network\Enums\MemberRole;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkEvent;
use Ginjer\Network\Models\ShardMetric;
use Ginjer\Network\Services\NodesStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The public status page: whether each shard is up, players, uptime and recent outages. Only
 * what players can see anyway: shard names, counts and times. No server names, addresses, plugin
 * names or error details. Read from Redis and the panel's own records (never Wings), and cached
 * for 30 seconds, so a busy page costs nothing.
 */
class StatusPage
{
    public function __construct(private NodesStatusService $nodes) {}

    public static function token(Network $network): string
    {
        if (blank($network->status_token)) {
            $network->forceFill(['status_token' => Str::random(32)])->saveQuietly();
        }

        return $network->status_token;
    }

    public static function url(Network $network): string
    {
        return url('/ginjer-status/' . self::token($network));
    }

    /**
     * @return array{name: string, maintenance: bool, reachable: bool, players: int, shards: list<array{name: string, up: ?bool, players: int}>,
     *               uptime: array{day: ?float, week: ?float}, incidents: list<array{shard: string, from: string, to: ?string}>, updated: string}
     */
    public function data(Network $network): array
    {
        return Cache::remember("ginjer-network:status:{$network->id}", 30, function () use ($network) {
            $status = $this->nodes->status($network);
            $shards = [];
            foreach ($network->loadMissing('members')->members->where('role', MemberRole::Shard)->sortBy('shard_name') as $member) {
                $live = $status['shards'][$member->shard_name] ?? null;
                $shards[] = ['name' => (string) $member->shard_name, 'up' => $status['reachable'] ? $live !== null : null, 'players' => (int) ($live['players'] ?? 0)];
            }

            return [
                'name' => $network->name,
                'maintenance' => (bool) $network->maintenance,
                'reachable' => $status['reachable'],
                'players' => array_sum(array_column($shards, 'players')),
                'shards' => $shards,
                'uptime' => ['day' => self::uptime($network, now()->subDay()), 'week' => self::uptime($network, now()->subWeek())],
                'incidents' => self::incidents($network),
                'updated' => now()->toIso8601String(),
            ];
        });
    }

    /** Share of recorded shard-minutes since $from in which the shard was up and reporting, in percent. */
    public static function uptime(Network $network, Carbon $from): ?float
    {
        $row = ShardMetric::where('network_id', $network->id)->where('recorded_at', '>=', $from)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN tps IS NOT NULL OR players IS NOT NULL THEN 1 ELSE 0 END) as up')
            ->first();

        return $row && $row->total > 0 ? round(100 * $row->up / $row->total, 2) : null;
    }

    /** @return list<array{shard: string, from: string, to: ?string}> outages in the last week, newest first */
    public static function incidents(Network $network): array
    {
        $events = NetworkEvent::where('network_id', $network->id)->whereIn('kind', ['health.down', 'health.back'])
            ->where('created_at', '>=', now()->subWeek())->orderBy('id')->get();
        $open = [];
        $out = [];
        foreach ($events as $event) {
            $shard = trim(preg_replace('/ is (down|back)$/', '', $event->title));
            if ($event->kind === 'health.down') {
                $open[$shard] = count($out);
                $out[] = ['shard' => $shard, 'from' => $event->created_at->toIso8601String(), 'to' => null];
            } elseif (isset($open[$shard])) {
                $out[$open[$shard]]['to'] = $event->created_at->toIso8601String();
                unset($open[$shard]);
            }
        }

        return array_slice(array_reverse($out), 0, 10);
    }

    public function html(array $d): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
        $allUp = $d['reachable'] && collect($d['shards'])->every(fn ($s) => $s['up']);
        $headline = match (true) {
            $d['maintenance'] => ['Down for maintenance', '#f59e0b'],
            !$d['reachable'] => ['Status unknown', '#9ca3af'],
            $allUp => ['All shards are up', '#22c55e'],
            collect($d['shards'])->contains(fn ($s) => $s['up']) => ['Some shards are down', '#f59e0b'],
            default => ['The network is down', '#ef4444'],
        };
        $rows = '';
        foreach ($d['shards'] as $s) {
            [$label, $color] = $s['up'] === null ? ['unknown', '#9ca3af'] : ($s['up'] ? ['up', '#22c55e'] : ['down', '#ef4444']);
            $rows .= "<div class=\"row\"><span><i style=\"background:$color\"></i>{$e($s['name'])}</span><span class=\"muted\">" . ($s['up'] ? $e($s['players']) . ' online' : $label) . '</span></div>';
        }
        $incidents = '';
        foreach ($d['incidents'] as $i) {
            $from = Carbon::parse($i['from']);
            $to = $i['to'] ? Carbon::parse($i['to']) : null;
            $incidents .= '<div class="row"><span>' . $e($i['shard']) . ' was down</span><span class="muted">' . $e($from->format('M j, H:i'))
                . ($to ? ' for ' . $e($from->diffForHumans($to, ['syntax' => Carbon::DIFF_ABSOLUTE])) : ', ongoing') . '</span></div>';
        }
        $uptime = fn (?float $v) => $v === null ? '–' : rtrim(rtrim(number_format($v, 2), '0'), '.') . '%';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="refresh" content="60"><title>' . $e($d['name']) . ' status</title><style>'
            . ':root{--bg:#f6f7f9;--card:#fff;--text:#111827;--muted:#6b7280;--line:#e5e7eb}'
            . '@media (prefers-color-scheme:dark){:root{--bg:#0b0d10;--card:#15181d;--text:#e5e7eb;--muted:#9ca3af;--line:#262a31}}'
            . 'body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}'
            . 'main{max-width:640px;margin:0 auto;padding:32px 16px}h1{font-size:22px;margin:0 0 4px}h2{font-size:14px;margin:24px 0 8px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em}'
            . '.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:4px 16px}.row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--line)}.row:last-child{border:0}'
            . '.banner{border-radius:12px;padding:16px;color:#fff;font-weight:600;font-size:17px;margin:16px 0}.muted{color:var(--muted)}i{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:10px}'
            . '.stats{display:flex;gap:12px}.stats .card{flex:1;padding:12px 16px}.big{font-size:22px;font-weight:600}</style></head><body><main>'
            . '<h1>' . $e($d['name']) . '</h1><div class="muted">Updated ' . $e(Carbon::parse($d['updated'])->format('H:i')) . ', refreshes every minute</div>'
            . '<div class="banner" style="background:' . $headline[1] . '">' . $e($headline[0]) . '</div>'
            . '<div class="stats"><div class="card"><div class="muted">Players online</div><div class="big">' . $e($d['players']) . '</div></div>'
            . '<div class="card"><div class="muted">Uptime, 24 hours</div><div class="big">' . $uptime($d['uptime']['day']) . '</div></div>'
            . '<div class="card"><div class="muted">Uptime, 7 days</div><div class="big">' . $uptime($d['uptime']['week']) . '</div></div></div>'
            . '<h2>Shards</h2><div class="card">' . ($rows ?: '<div class="row muted">No shards</div>') . '</div>'
            . '<h2>Outages this week</h2><div class="card">' . ($incidents ?: '<div class="row muted">None</div>') . '</div>'
            . '</main></body></html>';
    }
}
