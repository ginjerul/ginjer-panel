<?php

namespace Ginjer\Network\Services\Optimizer;

/** What the optimizer knows about a server when it picks values. */
final class Context
{
    public function __construct(
        public int $memoryMb,
        public bool $hasNodes = false,
        public bool $hasReef = false,
        public bool $behindProxy = false,
        public ?string $shardRole = null, // leader, follower or null when not sharded
    ) {}
}
