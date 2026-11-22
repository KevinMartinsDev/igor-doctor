<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Snapshot;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\VarExporter\LazyObjectInterface;

/**
 * Walks the object graph reachable from the roots and flattens it into a Snapshot.
 *
 * A value belongs to the object that directly holds it: a framework object reached through an
 * application service is reported as the framework's, not as the service's.
 *
 * The walk stops at a Request: its content belongs to one request, and keeping it is the leak.
 * It is recorded even outside the filtered namespaces, so that a zombie request has a path.
 *
 * Properties are read with get_mangled_object_vars(), which neither calls magic methods nor
 * initializes lazy objects. Every object is visited once per snapshot, so cycles and shared
 * references end the walk. Only the nodes under an object the filter keeps are recorded, but the
 * walk goes through every object: a framework service is often reachable only through the
 * private property of another one.
 */
final class StateSnapshotter
{
    public const OUTSIDE = 'outside the audited namespaces';

    private const MAX_STRING = 4096;

    /** @var array<int, true> */
    private array $visited = [];

    /** @var array<string, StateNode> */
    private array $nodes = [];

    private int $walked = 0;

    private bool $truncated = false;

    /** @var array<class-string, \ReflectionClass<object>> */
    private array $reflections = [];

    public function __construct(
        private readonly NamespaceFilter $filter,
        private readonly int $maxObjectDepth = 16,
        private readonly int $maxNodes = 500_000,
    ) {
    }

    /**
     * @param array<string, array{mixed, ?string}> $roots path => [value, owner class kept by the filter, if any]
     */
    public function snapshot(array $roots): Snapshot
    {
        $this->visited = [];
        $this->nodes = [];
        $this->walked = 0;
        $this->truncated = false;

        foreach ($roots as $path => [$value, $owner]) {
            $this->walk($value, $path, $owner, 0, null);
        }

        $snapshot = new Snapshot($this->nodes, $this->truncated);
        $this->visited = [];
        $this->nodes = [];

        return $snapshot;
    }

    /**
     * @param string|null $owner  the class of the object that directly holds this value, when the filter keeps it
     * @param string|null $holder the object or closure that directly holds this value, whatever its namespace
     */
    private function walk(mixed $value, string $path, ?string $owner, int $depth, ?string $holder): void
    {
        if (++$this->walked > $this->maxNodes) {
            $this->truncated = true;

            return;
        }

        if (null === $value || \is_scalar($value)) {
            $this->record($path, new StateNode(get_debug_type($value), self::scalar($value), null, $owner));

            return;
        }

        if (\is_array($value)) {
            $this->record($path, new StateNode('array', null, \count($value), $owner));
            foreach ($value as $key => $item) {
                $this->walk($item, $path.'['.$key.']', $owner, $depth, $holder);
            }

            return;
        }

        if (!\is_object($value)) {
            $this->record($path, new StateNode(get_debug_type($value), null, null, $owner));

            return;
        }

        $class = $value::class;
        if ($value instanceof Request) {
            $this->record($path, new StateNode($class, $holder, null, $owner ?? self::OUTSIDE));

            return;
        }

        $inner = $this->filter->matches($class) ? $class : null;

        if ($value instanceof \UnitEnum) {
            $this->record($path, new StateNode($class, $value->name, null, $owner));

            return;
        }

        if ($value instanceof \DateTimeInterface) {
            $this->record($path, new StateNode($class, $value->format(\DATE_RFC3339_EXTENDED), null, $owner));

            return;
        }

        if ($value instanceof ContainerInterface) {
            $this->record($path, new StateNode($class, null, null, $owner));

            return;
        }

        $id = spl_object_id($value);
        if ($value instanceof \Closure) {
            if (isset($this->visited[$id])) {
                return;
            }
            $this->visited[$id] = true;
            $this->walkClosure($value, $path, $owner, $depth);

            return;
        }

        if (isset($this->visited[$id])) {
            $this->record($path, new StateNode('ref '.$class, null, null, $owner));

            return;
        }
        $this->visited[$id] = true;

        if ($this->isUninitializedLazy($value)) {
            $this->record($path, new StateNode($class, 'lazy', null, $owner));

            return;
        }

        $this->record($path, new StateNode($class, null, null, $owner));
        if ($depth >= $this->maxObjectDepth) {
            return;
        }

        foreach (get_mangled_object_vars($value) as $raw => $property) {
            $this->walk($property, $path.'->$'.self::propertyName((string) $raw, $class), $inner, $depth + 1, $class);
        }

        if ($value instanceof \ArrayObject || $value instanceof \ArrayIterator) {
            foreach ($value->getArrayCopy() as $key => $item) {
                $this->walk($item, $path.'['.$key.']', $inner, $depth + 1, $class);
            }
        } elseif ($value instanceof \SplObjectStorage) {
            foreach ($value->__serialize()[0] as $key => $item) {
                $this->walk($item, $path.'{storage}['.$key.']', $inner, $depth + 1, $class);
            }
        } elseif ($value instanceof \WeakMap) {
            $index = 0;
            foreach ($value as $item) {
                $this->walk($item, $path.'{weak}['.$index++.']', $inner, $depth + 1, $class);
            }
        }
    }

    /**
     * A closure keeps its `use` variables and its bound object alive: a Request captured by a
     * listener closure is retained as surely as one stored in a property.
     */
    private function walkClosure(\Closure $closure, string $path, ?string $owner, int $depth): void
    {
        $function = new \ReflectionFunction($closure);
        $this->record($path, new StateNode(\Closure::class, $function->getName(), null, $owner));
        if ($depth >= $this->maxObjectDepth) {
            return;
        }

        $scope = $function->getClosureScopeClass()?->name;
        $inner = null !== $scope && $this->filter->matches($scope) ? $scope : null;
        $holder = $function->getName();

        $this->walk($function->getClosureThis(), $path.'{this}', $inner, $depth + 1, $holder);
        foreach ($function->getClosureUsedVariables() as $name => $variable) {
            $this->walk($variable, $path.'{use $'.$name.'}', $inner, $depth + 1, $holder);
        }
    }

    private function record(string $path, StateNode $node): void
    {
        if (null !== $node->owner) {
            $this->nodes[$path] = $node;
        }
    }

    private function isUninitializedLazy(object $value): bool
    {
        if ($value instanceof LazyObjectInterface && !$value->isLazyObjectInitialized()) {
            return true;
        }

        if (\PHP_VERSION_ID < 80400) {
            return false;
        }

        $reflection = $this->reflections[$value::class] ??= new \ReflectionClass($value);

        return $reflection->isUninitializedLazyObject($value);
    }

    /**
     * Mangled names are "\0Class\0name" for private and "\0*\0name" for protected properties.
     * A private property of a parent keeps its class, so it cannot collide with the child's.
     */
    private static function propertyName(string $raw, string $class): string
    {
        if (!str_starts_with($raw, "\0")) {
            return $raw;
        }

        $separator = strrpos($raw, "\0");
        $name = substr($raw, $separator + 1);
        $declaring = substr($raw, 1, $separator - 1);

        return '*' === $declaring || $declaring === $class ? $name : $declaring.'::'.$name;
    }

    private static function scalar(bool|float|int|string|null $value): ?string
    {
        return match (true) {
            null === $value => null,
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value) && \strlen($value) > self::MAX_STRING => substr($value, 0, self::MAX_STRING).'…#'.hash('xxh128', $value),
            default => (string) $value,
        };
    }
}
