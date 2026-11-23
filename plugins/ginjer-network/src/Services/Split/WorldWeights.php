<?php

namespace Ginjer\Network\Services\Split;

/**
 * Where the players are, from Nodes' own files: every territory chunk in world.json becomes a
 * weighted point. Chunks owned by a town weigh more the more residents the town has, so a split
 * balances players rather than land.
 */
final class WorldWeights
{
    /** Weight of a territory chunk no town owns: land is still worth something (wilderness, travel). */
    public const UNCLAIMED_CHUNK = 0.2;

    /** Extra weight per resident, spread over the town's chunks. */
    public const PER_RESIDENT = 5.0;

    /**
     * @return array{points: list<array{0: int, 1: int, 2: float}>, territories: int, towns: int, residents: int}
     *                                                                                                          points are [block x, block z, weight] at chunk centres
     */
    public static function fromNodesFiles(string $worldJson, ?string $townsJson): array
    {
        $world = json_decode($worldJson, true);
        if (!is_array($world)) {
            throw new \InvalidArgumentException('world.json is not valid JSON.');
        }
        $territories = is_array($world['territories'] ?? null) ? $world['territories'] : [];

        // territory id => [town residents, town chunk count]
        $owner = [];
        $towns = 0;
        $residents = 0;
        $towns_ = is_string($townsJson) ? json_decode($townsJson, true) : null;
        foreach ((is_array($towns_['towns'] ?? null) ? $towns_['towns'] : []) as $town) {
            $towns++;
            $r = is_array($town['residents'] ?? null) ? count($town['residents']) : 0;
            $residents += $r;
            $ids = array_merge(
                is_array($town['territories'] ?? null) ? $town['territories'] : [],
                is_array($town['annexed'] ?? null) ? $town['annexed'] : [],
            );
            $chunks = 0;
            foreach ($ids as $id) {
                $chunks += intdiv(count($territories[(string) $id]['chunks'] ?? []), 2);
            }
            foreach ($ids as $id) {
                $owner[(string) $id] = [$r, max(1, $chunks)];
            }
        }

        $points = [];
        foreach ($territories as $id => $territory) {
            $flat = is_array($territory['chunks'] ?? null) ? $territory['chunks'] : [];
            [$r, $townChunks] = $owner[(string) $id] ?? [0, 1];
            $weight = self::UNCLAIMED_CHUNK + ($r > 0 ? self::PER_RESIDENT * $r / $townChunks : 0);
            for ($i = 0; $i + 1 < count($flat); $i += 2) {
                $points[] = [(int) $flat[$i] * 16 + 8, (int) $flat[$i + 1] * 16 + 8, $weight];
            }
        }

        return ['points' => $points, 'territories' => count($territories), 'towns' => $towns, 'residents' => $residents];
    }
}
