<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Snapshot;

/**
 * One value reached while walking the shared state: its type, a printable value for scalars,
 * the size of arrays, and the class of the nearest enclosing object the namespace filter keeps.
 */
final readonly class StateNode
{
    public function __construct(
        public string $type,
        public ?string $value = null,
        public ?int $count = null,
        public ?string $owner = null,
    ) {
    }

    public function sameAs(self $other): bool
    {
        return self::withoutReference($this->type) === self::withoutReference($other->type) && $this->value === $other->value && $this->count === $other->count;
    }

    /**
     * An object met a second time is recorded as "ref Class": which path reaches it first depends
     * on the order of the walk, not on the state.
     */
    private static function withoutReference(string $type): string
    {
        return str_starts_with($type, 'ref ') ? substr($type, 4) : $type;
    }

    public function isArray(): bool
    {
        return 'array' === $this->type;
    }

    public function describe(): string
    {
        return match (true) {
            null !== $this->count => \sprintf('array(%d)', $this->count),
            null !== $this->value => \sprintf('%s %s', $this->type, $this->value),
            default => $this->type,
        };
    }
}
