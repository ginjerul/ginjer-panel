<?php

namespace Ginjer\Network\Services\Ops;

/**
 * Lag hotspots from Reef's entity report (reef-entities.json, written by "/reef entities"), with
 * the Nodes town that owns each chunk, so you know whose farm it is.
 */
final class Hotspots
{
    /**
     * chunk "x,z" => [town, nation] from Nodes' world.json and towns.json.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function owners(?string $worldJson, ?string $townsJson): array
    {
        $world = $worldJson ? json_decode($worldJson, true) : null;
        $towns = $townsJson ? json_decode($townsJson, true) : null;
        if (!is_array($world) || !is_array($towns)) {
            return [];
        }
        $nationOf = [];
        foreach (($towns['nations'] ?? []) as $nation => $data) {
            foreach (($data['towns'] ?? []) as $town) {
                $nationOf[$town] = $nation;
            }
        }
        $owners = [];
        foreach (($towns['towns'] ?? []) as $town => $data) {
            foreach (array_merge($data['territories'] ?? [], $data['annexed'] ?? []) as $id) {
                $chunks = $world['territories'][(string) $id]['chunks'] ?? [];
                for ($i = 0; $i + 1 < count($chunks); $i += 2) {
                    $owners[$chunks[$i] . ',' . $chunks[$i + 1]] = [(string) $town, $nationOf[$town] ?? null];
                }
            }
        }

        return $owners;
    }

    /**
     * The report's chunks, worst first, with their owner.
     *
     * @param  array<string, array{0: string, 1: ?string}>  $owners
     * @return array{generated: ?int, totals: array<string, int>, chunks: list<array<string, mixed>>}
     */
    public static function parse(string $reportJson, array $owners = []): array
    {
        $report = json_decode($reportJson, true);
        if (!is_array($report)) {
            throw new \InvalidArgumentException('reef-entities.json isn\'t valid JSON.');
        }
        $chunks = [];
        foreach (($report['chunks'] ?? []) as $c) {
            $owner = $owners[$c['x'] . ',' . $c['z']] ?? null;
            arsort($c['types']);
            arsort($c['block_types']);
            $chunks[] = $c + [
                'key' => "{$c['world']}:{$c['x']},{$c['z']}",
                'block_x' => $c['x'] * 16 + 8,
                'block_z' => $c['z'] * 16 + 8,
                'town' => $owner[0] ?? null,
                'nation' => $owner[1] ?? null,
                'load' => (int) $c['entities'] + (int) $c['block_entities'],
            ];
        }
        usort($chunks, fn ($a, $b) => $b['load'] <=> $a['load']);
        $totals = $report['totals'] ?? [];
        arsort($totals);

        return ['generated' => $report['generated'] ?? null, 'totals' => $totals, 'chunks' => $chunks];
    }

    /** "zombie ×120, item ×40, +3 more" */
    public static function summary(array $types, int $show = 3): string
    {
        $parts = [];
        foreach (array_slice($types, 0, $show, true) as $type => $count) {
            $parts[] = str_replace('minecraft:', '', $type) . " ×$count";
        }
        $more = count($types) - $show;

        return implode(', ', $parts) . ($more > 0 ? ", +$more more" : '');
    }

    /**
     * How much of an area has been generated: the share of its region files (512×512 blocks each)
     * that exist. A region file can be only partly generated, so this is an estimate.
     *
     * @param  list<string>  $regionFiles  names in world/region, like "r.0.-1.mca"
     * @return array{have: int, need: int, percent: int}
     */
    public static function pregenProgress(array $regionFiles, array $area): array
    {
        $present = array_flip(array_filter($regionFiles, fn ($f) => preg_match('/^r\.-?\d+\.-?\d+\.mca$/', $f)));
        $need = 0;
        $have = 0;
        for ($rx = (int) floor($area['min-x'] / 512); $rx * 512 < $area['max-x']; $rx++) {
            for ($rz = (int) floor($area['min-z'] / 512); $rz * 512 < $area['max-z']; $rz++) {
                $need++;
                $have += isset($present["r.$rx.$rz.mca"]) ? 1 : 0;
            }
        }

        return ['have' => $have, 'need' => $need, 'percent' => $need === 0 ? 100 : (int) floor(100 * $have / $need)];
    }
}
