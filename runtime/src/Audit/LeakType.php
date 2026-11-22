<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

enum LeakType: string
{
    case CrossRequestStateLeak = 'CROSS_REQUEST_STATE_LEAK';
    case MonotonicMemoryGrowth = 'MONOTONIC_MEMORY_GROWTH';
    case ZombieRequestRetention = 'ZOMBIE_REQUEST_RETENTION';
    case ExecutionTerminated = 'EXECUTION_TERMINATED';
}
