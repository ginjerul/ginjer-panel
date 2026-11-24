<?php

namespace Ginjer\Network\Services\Activity;

use Ginjer\Network\Jobs\PostToDiscord;
use Ginjer\Network\Models\Network;
use Ginjer\Network\Models\NetworkEvent;

/**
 * The network's activity log, and its Discord channel: everything people do to a network and
 * everything the panel notices goes through here.
 */
class NetworkLog
{
    /** Kinds that are alerts: posted to Discord even when it's set to alerts only. */
    public const ALERT_KINDS = ['health', 'crash', 'backup.failed', 'update.failed'];

    public static function record(Network|int $network, string $kind, string $title, ?string $body = null, string $level = 'info', ?int $userId = null): ?NetworkEvent
    {
        $network = $network instanceof Network ? $network : Network::find($network);
        if ($network === null) {
            return null;
        }
        $userId ??= auth()->id();
        $event = rescue(fn () => NetworkEvent::create([
            'network_id' => $network->id, 'user_id' => $userId, 'kind' => $kind, 'level' => $level,
            'title' => mb_strimwidth($title, 0, 250, '…'), 'body' => $body,
        ]), null);
        if ($event !== null && random_int(1, 200) === 1) {
            NetworkEvent::where('created_at', '<', now()->subDays(NetworkEvent::KEEP_DAYS))->delete();
        }

        if (filled($network->discord_webhook) && self::goesToDiscord($network->discord_level ?: 'alerts', $kind, $level)) {
            $who = $userId ? \App\Models\User::find($userId)?->username : null;
            PostToDiscord::dispatch($network->id, $title, (string) $body, $level, $who);
        }

        return $event;
    }

    public static function goesToDiscord(string $setting, string $kind, string $level): bool
    {
        if ($setting === 'all') {
            return true;
        }
        if ($setting === 'off') {
            return false;
        }
        foreach (self::ALERT_KINDS as $alert) {
            if ($kind === $alert || str_starts_with($kind, $alert . '.')) {
                return true;
            }
        }

        return $level === 'danger';
    }

    /** A Discord webhook link, or null if it isn't one. */
    public static function validWebhook(?string $url): bool
    {
        return $url !== null && preg_match('#^https://(?:ptb\.|canary\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#', trim($url)) === 1;
    }
}
