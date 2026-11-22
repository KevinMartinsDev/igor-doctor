<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Snapshot;

/**
 * The shared state at one point in time, as a flat map from a deep path
 * (`@service->$cache[key]->$value`) to the node found there.
 */
final readonly class Snapshot
{
    /**
     * @param array<string, StateNode> $nodes
     */
    public function __construct(
        public array $nodes,
        public bool $truncated = false,
    ) {
    }

    public function get(string $path): ?StateNode
    {
        return $this->nodes[$path] ?? null;
    }
}
