<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Snapshot;

/**
 * Decides which classes own the state worth reporting: `App\` by default, a framework's
 * namespace (`ApiPlatform\`) to hunt framework bugs, or everything.
 */
final class NamespaceFilter
{
    /** @var array<class-string, bool> */
    private array $matches = [];

    /**
     * @param list<string> $prefixes an empty list keeps every class
     */
    public function __construct(private readonly array $prefixes)
    {
    }

    public static function everything(): self
    {
        return new self([]);
    }

    /**
     * @return list<string>
     */
    public function prefixes(): array
    {
        return $this->prefixes;
    }

    /**
     * Lazy proxies are generated subclasses with their own names: their parents count too.
     *
     * @param class-string $class
     */
    public function matches(string $class): bool
    {
        if (isset($this->matches[$class])) {
            return $this->matches[$class];
        }

        if ([] === $this->prefixes) {
            return $this->matches[$class] = true;
        }

        foreach ([$class, ...array_values(class_parents($class) ?: [])] as $candidate) {
            foreach ($this->prefixes as $prefix) {
                if (str_starts_with($candidate, $prefix)) {
                    return $this->matches[$class] = true;
                }
            }
        }

        return $this->matches[$class] = false;
    }
}
