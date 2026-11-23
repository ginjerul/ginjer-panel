<?php

namespace Ginjer\Network\Services\Split;

/**
 * Rules of thumb for sizing a Reef shard, in MB.
 *
 * A Paper server needs about 2 GB to itself, then roughly 48 MB per player online (chunks around
 * them, entities, Nodes' per-player state). The Nodes leader also runs every town, war and income
 * task, saves the shared state, keeps the Nether and the End, and hosts the full GinjerMap, so it
 * gets 1.5 GB more.
 */
final class ResourcePlanner
{
    public const BASE_MB = 2048;

    public const PER_PLAYER_MB = 48;

    public const LEADER_EXTRA_MB = 1536;

    public const MIN_MB = 3072;

    public const MAX_MB = 16384;

    /** Below about this many players, one server is simpler and faster than shards (see Reef's SHARDING.md). */
    public const WORTH_SHARDING_PLAYERS = 150;

    /** Players one shard handles comfortably; used to pick the shard count. */
    public const PLAYERS_PER_SHARD = 100;

    public static function memoryFor(float $players, bool $leader): int
    {
        $mb = self::BASE_MB + self::PER_PLAYER_MB * $players + ($leader ? self::LEADER_EXTRA_MB : 0);
        $mb = max(self::MIN_MB, min(self::MAX_MB + ($leader ? self::LEADER_EXTRA_MB : 0), $mb));

        return (int) (ceil($mb / 512) * 512);
    }

    public static function shardCountFor(int $players): int
    {
        return max(2, min(8, (int) ceil($players / self::PLAYERS_PER_SHARD)));
    }

    /** A guess at peak players online from Nodes data: about a third of residents at once. */
    public static function guessPeakPlayers(int $residents): int
    {
        return max(50, (int) round($residents / 3));
    }
}
