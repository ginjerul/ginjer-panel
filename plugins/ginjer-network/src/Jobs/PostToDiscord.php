<?php

namespace Ginjer\Network\Jobs;

use Ginjer\Network\Models\Network;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/** Posts one network event to the network's Discord channel as an embed. */
class PostToDiscord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public const COLORS = ['success' => 0x22C55E, 'warning' => 0xF59E0B, 'danger' => 0xEF4444, 'info' => 0x3B82F6];

    public function __construct(public int $networkId, public string $title, public string $body, public string $level, public ?string $who = null) {}

    public function handle(): void
    {
        $network = Network::find($this->networkId);
        if ($network === null || blank($network->discord_webhook)) {
            return;
        }
        $response = Http::timeout(10)->post($network->discord_webhook, self::payload($network->name, $this->title, $this->body, $this->level, $this->who));
        if ($response->status() === 429) {
            $this->release((int) ceil((float) ($response->json('retry_after') ?? 5)));

            return;
        }
        $response->throw();
    }

    /** @return array<string, mixed> */
    public static function payload(string $network, string $title, string $body, string $level, ?string $who): array
    {
        return [
            'username' => 'Ginjer Panel',
            'allowed_mentions' => ['parse' => []], // a player name like @everyone must never ping
            'embeds' => [[
                'title' => mb_strimwidth($title, 0, 250, '…'),
                'description' => mb_strimwidth($body, 0, 4000, '…'),
                'color' => self::COLORS[$level] ?? self::COLORS['info'],
                'footer' => ['text' => $network . ($who ? " · by $who" : '')],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];
    }
}
