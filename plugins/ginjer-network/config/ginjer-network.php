<?php

return [
    // Seconds to wait between start stages: Redis, then the Nodes leader, then the other shards,
    // then the proxy. Stopping goes the other way round.
    'start_stage_delay' => (int) env('GINJER_NETWORK_START_STAGE_DELAY', 10),

    // Where Reef and Nodes keep their config, relative to the server's root folder.
    'reef_config_path' => env('GINJER_NETWORK_REEF_CONFIG', 'reef.yml'),
    'nodes_config_path' => env('GINJER_NETWORK_NODES_CONFIG', 'plugins/nodes/config.yml'),

    // Seconds the panel waits for Redis when it reads the Nodes status.
    'redis_timeout' => (float) env('GINJER_NETWORK_REDIS_TIMEOUT', 2),
];
