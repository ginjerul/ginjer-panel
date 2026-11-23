<?php

return [
    // Seconds to wait between start stages: Redis, then the Nodes leader, then the other shards,
    // then the proxy. Stopping goes the other way round.
    'start_stage_delay' => (int) env('GINJER_NETWORK_START_STAGE_DELAY', 10),

    // Where Reef and Nodes keep their config, relative to the server's root folder.
    'reef_config_path' => env('GINJER_NETWORK_REEF_CONFIG', 'reef.yml'),
    'nodes_config_path' => env('GINJER_NETWORK_NODES_CONFIG', 'plugins/nodes/config.yml'),

    // How the proxy reaches a shard on its own node that listens on every interface: Wings' Docker
    // gateway (docker.network.interface in Wings' config.yml).
    'docker_gateway' => env('GINJER_NETWORK_DOCKER_GATEWAY', '172.18.0.1'),

    // Seconds the panel waits for Redis when it reads the Nodes status.
    'redis_timeout' => (float) env('GINJER_NETWORK_REDIS_TIMEOUT', 2),
];
