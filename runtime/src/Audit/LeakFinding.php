<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * One leak, grouped by the class that owns the state. A certain finding carries a value sent by an
 * earlier request, or a Request kept alive; a suspect one is state that changed between two
 * requests of the same route without any request value in it.
 */
final readonly class LeakFinding
{
    public const MAX_PATHS = 5;

    /**
     * @param list<string> $paths the first paths found, at most MAX_PATHS
     */
    public function __construct(
        public LeakType $type,
        public bool $certain,
        public string $owner,
        public string $probe,
        public array $paths,
        public int $pathCount,
        public string $evidence,
    ) {
    }

    /**
     * What the leak does to the next request:
     * - exposure: proven to change it (the PHP process was changed, or its answer shows the previous request's value);
     * - retention: the value stays in memory until the next request; whether that request reads it before writing
     *   it again is not proven (a parser that keeps its last input, a validator that keeps its last context);
     * - growth: memory grows on every request;
     * - crash: the request ended the process.
     */
    public function impact(): string
    {
        return match ($this->type) {
            LeakType::MonotonicMemoryGrowth => 'growth',
            LeakType::ExecutionTerminated => 'crash',
            LeakType::ZombieRequestRetention => 'retention',
            LeakType::CrossRequestStateLeak => 'response' === $this->owner || str_starts_with($this->paths[0] ?? '', '{process}') ? 'exposure' : 'retention',
        };
    }

    /**
     * @return array{type: string, certain: bool, impact: string, owner: string, probe: string, paths: list<string>, path_count: int, evidence: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'certain' => $this->certain,
            'impact' => $this->impact(),
            'owner' => $this->owner,
            'probe' => $this->probe,
            'paths' => $this->paths,
            'path_count' => $this->pathCount,
            'evidence' => $this->evidence,
        ];
    }
}
