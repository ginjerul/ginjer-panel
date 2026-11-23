<?php

namespace Ginjer\Network\Services\Optimizer;

/**
 * The settings the optimizer knows, for Paper 1.21.11 (Paper commit c5eb079, which Reef is built
 * on), Reef and Nodes. Names and defaults were checked against Paper's WorldConfiguration,
 * SpigotWorldConfig and default bukkit.yml, Reef's ReefConfig and Nodes' config.yml.
 *
 * "balanced" changes nothing players would notice, or very little. "performance" adds changes
 * that trade some gameplay for speed; those are marked $gameplay and explained.
 */
final class Rules
{
    public const PROPERTIES = 'server.properties';

    public const BUKKIT = 'bukkit.yml';

    public const SPIGOT = 'spigot.yml';

    public const PAPER = 'config/paper-world-defaults.yml';

    public const REEF = 'reef.yml';

    public const NODES = 'plugins/nodes/config.yml';

    public const STARTUP = 'startup';

    /** @return list<Rule> */
    public static function all(): array
    {
        $b = Rule::BALANCED;
        $p = Rule::PERFORMANCE;
        $spigot = 'world-settings.default.';
        $needsReef = fn (Context $c) => $c->hasReef ? null : 'Not running Reef (no reef.yml).';
        $needsNodes = fn (Context $c) => $c->hasNodes ? null : 'Nodes isn\'t installed.';

        return [
            // ---------------- server.properties
            new Rule('view-distance', self::PROPERTIES, 'properties', 'view-distance', 'View distance',
                'How far players see, in chunks (default 10). Every extra chunk of view distance is sent to every player. 8 still looks far.',
                [$b => 8, $p => 6], gameplay: true),
            new Rule('simulation-distance', self::PROPERTIES, 'properties', 'simulation-distance', 'Simulation distance',
                'How far from players mobs, crops and redstone run, in chunks (default 10). The biggest single saving: chunks beyond it still show, they just pause.',
                [$b => 6, $p => 4], gameplay: true),
            new Rule('network-compression', self::PROPERTIES, 'properties', 'network-compression-threshold', 'Network compression',
                'Behind Velocity the proxy already compresses what players receive, so the server compressing it too only costs CPU (Velocity\'s own advice).',
                [$b => -1],
                when: fn (Context $c) => $c->behindProxy ? null : 'Only for servers behind a Velocity proxy (this one isn\'t in a network with a proxy).'),

            // ---------------- bukkit.yml (defaults: monsters 70, animals 10, water-ambient 20, ambient 15; ticks-per spawns 1; chunk-gc 600)
            new Rule('spawn-monsters', self::BUKKIT, 'yaml', 'spawn-limits.monsters', 'Monster limit',
                'Most hostile mobs per player (default 70). Fewer mobs means less AI and pathfinding every tick.',
                [$p => 50], gameplay: true),
            new Rule('spawn-animals', self::BUKKIT, 'yaml', 'spawn-limits.animals', 'Animal limit',
                'Most naturally spawned animals per player (default 10). Doesn\'t affect bred animals.',
                [$p => 8], gameplay: true),
            new Rule('spawn-water-ambient', self::BUKKIT, 'yaml', 'spawn-limits.water-ambient', 'Fish limit',
                'Most fish per player (default 20). Schools of fish are many entities for little gameplay.',
                [$b => 10, $p => 5], gameplay: true),
            new Rule('spawn-ambient', self::BUKKIT, 'yaml', 'spawn-limits.ambient', 'Bat limit',
                'Most bats per player (default 15). Bats do nothing for gameplay but still tick.',
                [$b => 5, $p => 1], gameplay: true),
            new Rule('ticks-monster-spawns', self::BUKKIT, 'yaml', 'ticks-per.monster-spawns', 'Monster spawn attempts',
                'Ticks between monster spawn attempts (default 1, every tick). Mobs still fill up to the limit, just over a few seconds instead of at once.',
                [$b => 5, $p => 10], gameplay: true),
            new Rule('ticks-water-ambient-spawns', self::BUKKIT, 'yaml', 'ticks-per.water-ambient-spawns', 'Fish spawn attempts',
                'Ticks between fish spawn attempts (default 1).',
                [$b => 10, $p => 20], gameplay: true),
            new Rule('ticks-ambient-spawns', self::BUKKIT, 'yaml', 'ticks-per.ambient-spawns', 'Bat spawn attempts',
                'Ticks between bat spawn attempts (default 1).',
                [$b => 10, $p => 20], gameplay: true),
            new Rule('chunk-gc', self::BUKKIT, 'yaml', 'chunk-gc.period-in-ticks', 'Unused chunk cleanup',
                'How often chunks nobody is near are unloaded (default every 600 ticks). Sooner frees memory sooner.',
                [$b => 400]),

            // ---------------- spigot.yml (defaults from SpigotWorldConfig)
            new Rule('activation-animals', self::SPIGOT, 'yaml', $spigot . 'entity-activation-range.animals', 'Animal activation range',
                'Animals further than this from players (in blocks, default 32) only tick now and then.',
                [$b => 24, $p => 16], gameplay: true),
            new Rule('activation-monsters', self::SPIGOT, 'yaml', $spigot . 'entity-activation-range.monsters', 'Monster activation range',
                'Monsters further than this from players (default 32) only tick now and then. Kept at 32 in balanced so mobs near bases still act normally.',
                [$p => 24], gameplay: true),
            new Rule('activation-raiders', self::SPIGOT, 'yaml', $spigot . 'entity-activation-range.raiders', 'Raider activation range',
                'Raid mobs (default 64).',
                [$b => 48]),
            new Rule('activation-misc', self::SPIGOT, 'yaml', $spigot . 'entity-activation-range.misc', 'Item and misc activation range',
                'Dropped items, minecarts and the like (default 16).',
                [$b => 12, $p => 8], gameplay: true),
            new Rule('activation-water', self::SPIGOT, 'yaml', $spigot . 'entity-activation-range.water', 'Water mob activation range',
                'Squid, fish and other water mobs (default 16).',
                [$b => 12, $p => 8]),
            new Rule('activation-villagers', self::SPIGOT, 'yaml', $spigot . 'entity-activation-range.villagers', 'Villager activation range',
                'Villagers (default 32). Villagers are among the most expensive mobs.',
                [$b => 24, $p => 16], gameplay: true),
            new Rule('activation-flying', self::SPIGOT, 'yaml', $spigot . 'entity-activation-range.flying-monsters', 'Flying monster activation range',
                'Phantoms, ghasts and the like (default 32).',
                [$p => 24], gameplay: true),
            new Rule('tracking-animals', self::SPIGOT, 'yaml', $spigot . 'entity-tracking-range.animals', 'Animal tracking range',
                'How far away animals are sent to players, in blocks (default 96). Players rarely need to see animals that far. Player tracking range is left alone for PvP and wars.',
                [$b => 48]),
            new Rule('tracking-monsters', self::SPIGOT, 'yaml', $spigot . 'entity-tracking-range.monsters', 'Monster tracking range',
                'How far away monsters are sent to players (default 96).',
                [$b => 64]),
            new Rule('tracking-misc', self::SPIGOT, 'yaml', $spigot . 'entity-tracking-range.misc', 'Item tracking range',
                'How far away items and misc entities are sent (default 96).',
                [$b => 48]),
            new Rule('merge-items', self::SPIGOT, 'yaml', $spigot . 'merge-radius.item', 'Item merging',
                'Dropped items this close together merge into one (default 0.5 blocks). Fewer item entities after explosions and mob farms.',
                [$b => 2.5]),
            new Rule('merge-exp', self::SPIGOT, 'yaml', $spigot . 'merge-radius.exp', 'Experience orb merging',
                'Experience orbs this close together merge (default -1, off).',
                [$b => 3.0]),
            new Rule('arrow-despawn', self::SPIGOT, 'yaml', $spigot . 'arrow-despawn-rate', 'Arrow despawn time',
                'Ticks until arrows stuck in the ground disappear (default 1200, one minute). Wars leave thousands.',
                [$b => 300]),
            new Rule('item-despawn', self::SPIGOT, 'yaml', $spigot . 'item-despawn-rate', 'Item despawn time',
                'Ticks until dropped items disappear (default 6000, five minutes).',
                [$p => 4000], gameplay: true),
            new Rule('nerf-spawner-mobs', self::SPIGOT, 'yaml', $spigot . 'nerf-spawner-mobs', 'Brainless spawner mobs',
                'Mobs from spawners don\'t think or move on their own (default off). Big saving with mob farms, but they behave very differently.',
                [$p => true], gameplay: true),
            new Rule('mob-spawn-range', self::SPIGOT, 'yaml', $spigot . 'mob-spawn-range', 'Mob spawn range',
                'How far from players mobs spawn, in chunks (default 8). Kept within the simulation distance, since mobs outside it don\'t move.',
                [$b => 6, $p => 4], gameplay: true),

            // ---------------- Paper (config/paper-world-defaults.yml)
            new Rule('autosave-chunks', self::PAPER, 'yaml', 'chunks.max-auto-save-chunks-per-tick', 'Chunks saved per tick',
                'Paper spreads auto-saves over time; fewer chunks per tick (default 24) smooths the lag spike every save.',
                [$b => 8]),
            new Rule('unloaded-chunks', self::PAPER, 'yaml', 'chunks.prevent-moving-into-unloaded-chunks', 'Block moving into unloaded chunks',
                'Stops players and fast elytra flights from loading chunks synchronously (default off).',
                [$b => true]),
            new Rule('save-limit-xp', self::PAPER, 'yaml', 'chunks.entity-per-chunk-save-limit.experience_orb', 'Saved XP orbs per chunk',
                'Caps how many of these a chunk keeps when it saves (default -1, no cap), so a broken farm can\'t make a chunk too big to load.',
                [$b => 16]),
            new Rule('save-limit-arrow', self::PAPER, 'yaml', 'chunks.entity-per-chunk-save-limit.arrow', 'Saved arrows per chunk', 'As above, for arrows.', [$b => 16]),
            new Rule('save-limit-snowball', self::PAPER, 'yaml', 'chunks.entity-per-chunk-save-limit.snowball', 'Saved snowballs per chunk', 'As above, for snowballs.', [$b => 8]),
            new Rule('save-limit-pearl', self::PAPER, 'yaml', 'chunks.entity-per-chunk-save-limit.ender_pearl', 'Saved ender pearls per chunk', 'As above, for ender pearls.', [$b => 8]),
            new Rule('save-limit-fireball', self::PAPER, 'yaml', 'chunks.entity-per-chunk-save-limit.fireball', 'Saved fireballs per chunk', 'As above, for fireballs.', [$b => 8]),
            new Rule('save-limit-small-fireball', self::PAPER, 'yaml', 'chunks.entity-per-chunk-save-limit.small_fireball', 'Saved small fireballs per chunk', 'As above, for small fireballs.', [$b => 8]),
            new Rule('optimize-explosions', self::PAPER, 'yaml', 'environment.optimize-explosions', 'Faster explosions',
                'Paper\'s faster explosion maths (default off). Same results in practice; matters with TNT in wars.',
                [$b => true]),
            new Rule('treasure-villager', self::PAPER, 'yaml', 'environment.treasure-maps.find-already-discovered.villager-trade', 'Reuse found treasure (villagers)',
                'Explorer maps from villagers may point at structures someone already found (default off). Searching for undiscovered ones can freeze the server for seconds.',
                [$b => true], gameplay: true),
            new Rule('treasure-loot', self::PAPER, 'yaml', 'environment.treasure-maps.find-already-discovered.loot-tables', 'Reuse found treasure (chests)',
                'The same for treasure maps found in chests (default: vanilla behaviour).',
                [$b => true], gameplay: true),
            new Rule('redstone', self::PAPER, 'yaml', 'misc.redstone-implementation', 'Faster redstone',
                'Alternate Current is much faster than vanilla redstone (default VANILLA). Almost every build works the same; a few that rely on vanilla update order behave differently.',
                [$b => 'ALTERNATE_CURRENT'], gameplay: true),
            new Rule('grass-spread', self::PAPER, 'yaml', 'tick-rates.grass-spread', 'Grass spread',
                'Ticks between grass and mycelium spread checks (default 1). Grass spreads a bit slower.',
                [$b => 4], gameplay: true),
            new Rule('mob-spawner-rate', self::PAPER, 'yaml', 'tick-rates.mob-spawner', 'Spawner checks',
                'Ticks between spawner checks (default 1). Spawners spawn at the same speed, they just check less often.',
                [$p => 2]),
            new Rule('max-collisions', self::PAPER, 'yaml', 'collisions.max-entity-collisions', 'Entity collisions',
                'How many other entities one entity collides with per tick (default 8). Crowded pens and farms get much cheaper.',
                [$b => 4, $p => 2], gameplay: true),
            new Rule('hopper-move-event', self::PAPER, 'yaml', 'hopper.disable-move-event', 'Skip hopper events',
                'Hoppers stop telling plugins about each item they move (default off). Fast, but plugins that protect chests from hoppers stop working.',
                [$b => true],
                when: fn (Context $c) => $c->hasNodes ? 'Nodes uses this event to stop hoppers draining protected town chests, so it stays on.' : null),
            new Rule('hopper-occluding', self::PAPER, 'yaml', 'hopper.ignore-occluding-blocks', 'Hoppers ignore buried containers',
                'Hoppers skip containers inside solid blocks, like a minecart chest in sand (default off).',
                [$p => true], gameplay: true),
            new Rule('armor-stand-lookups', self::PAPER, 'yaml', 'entities.armor-stands.do-collision-entity-lookups', 'Armor stand collisions',
                'Armor stands stop looking for entities to collide with (default on). Decorative stands are common in towns.',
                [$b => false]),
            new Rule('armor-stand-tick', self::PAPER, 'yaml', 'entities.armor-stands.tick', 'Armor stand ticking',
                'Armor stands stop ticking (default on): they no longer fall or get pushed.',
                [$p => false], gameplay: true),

            // ---------------- Reef (defaults from ReefConfig)
            new Rule('reef-dab', self::REEF, 'yaml', 'optimizations.dynamic-activation-of-brain.enabled', 'Dynamic mob brains (Reef)',
                'Mobs far from players think less often. On by default in Reef; this makes sure it stays on.',
                [$b => true], when: $needsReef),
            new Rule('reef-async-spawning', self::REEF, 'yaml', 'optimizations.async-mob-spawning', 'Async mob spawn counting (Reef)',
                'Counts mobs for spawning off the main thread. On by default in Reef.',
                [$b => true], when: $needsReef),
            new Rule('reef-async-saving', self::REEF, 'yaml', 'optimizations.async-player-data-saving', 'Async player saving (Reef)',
                'Saves player data off the main thread. On by default in Reef; sharding relies on it finishing before a handoff, which Reef already waits for.',
                [$b => true], when: $needsReef),
            new Rule('reef-push', self::REEF, 'yaml', 'optimizations.only-players-push-entities', 'Only players push mobs (Reef)',
                'Mobs no longer push each other (default off). Big saving in crowded farms, but farms that rely on mobs pushing each other stop working.',
                [$p => true], gameplay: true, when: $needsReef),

            // ---------------- Nodes
            new Rule('nodes-beacon-lighting', self::NODES, 'yaml', 'flagBeaconUpdateLighting', 'War flag beacon lighting (Nodes)',
                'Relighting around sky beacons when flags go up is expensive (Nodes\' own comment: keep false).',
                [$b => false], when: $needsNodes),
            new Rule('nodes-beacons', self::NODES, 'yaml', 'flagBeaconEnabled', 'War flag sky beacons (Nodes)',
                'Sky beacons above war flags (off by default in Nodes). Building them is expensive with many flags up; the flag itself still works.',
                [$p => false], gameplay: true, when: $needsNodes),
            new Rule('nodes-nametags', self::NODES, 'yaml', 'nametagUpdatePeriod', 'Nametag updates (Nodes)',
                'Ticks between town and nation nametag updates (default 80). Name tags update a little later after a change.',
                [$p => 160], gameplay: true, when: $needsNodes),
            new Rule('nodes-publish', self::NODES, 'yaml', 'sharding.publishIntervalMs', 'Shard sync batching (Nodes)',
                'How often the leader sends changes to the other shards, in ms (default 250). Every send makes followers reload towns, so bigger batches mean fewer reloads; changes reach other shards a quarter second later.',
                [$p => 500], gameplay: true,
                when: fn (Context $c) => $c->hasNodes && $c->shardRole === 'leader' ? null : 'Only for the Nodes leader of a sharded network.'),

            // ---------------- Java
            new Rule('jvm-flags', self::STARTUP, 'startup', 'startup', 'Java garbage collector flags',
                'Aikar\'s G1 flags, the usual choice for Paper: short, predictable garbage collection pauses instead of lag spikes. The heap is capped at 85% of the server\'s memory so Java\'s own memory outside the heap fits too and the container isn\'t killed.',
                [$b => fn (Context $c) => JvmFlags::for($c->memoryMb)]),
        ];
    }
}
