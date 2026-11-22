<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use IgorDoctor\Runtime\Snapshot\StateNode;

/**
 * Where a dependency keeps a leaked value: the first property, on the way from the service to the value, that is
 * declared by the package of the value's class. The value's own class is often a plain data object (a violation,
 * a token): the fix belongs to the class that keeps it (the validator that keeps its context, the lexer that keeps
 * its tokens), and that is the file to compare upstream.
 */
final class LeakHolder
{
    /**
     * @param array<string, StateNode> $nodes the snapshot, which records the class of every object on the path
     *
     * @return array{class: string, property: string, source: string}|null the source is relative to the project
     */
    public static function find(string $path, string $owner, array $nodes, string $projectDir): ?array
    {
        $package = self::package($owner, $projectDir);
        if (null === $package) {
            return null;
        }

        foreach (self::hops($path) as [$prefix, $class, $property]) {
            // A service is not recorded itself: the node of its property names it as the owner.
            $class ??= self::classOf($nodes[$prefix] ?? null) ?? self::ownerOf($nodes[$prefix.'->$'.$property] ?? null);
            if (null === $class) {
                continue;
            }
            if (str_contains($property, '::')) {
                [$class, $property] = explode('::', $property, 2);
            }
            $declaring = self::declaring($class, $property);
            if (null === $declaring || $declaring->getName() === $owner) {
                continue;
            }
            $file = $declaring->getFileName();
            if (\is_string($file) && str_starts_with($file, $projectDir.'/'.$package)) {
                return ['class' => $declaring->getName(), 'property' => $property, 'source' => substr($file, \strlen($projectDir) + 1)];
            }
        }

        return null;
    }

    /**
     * Each property on the path, with the path of the object that has it (or the class of a static property).
     *
     * @return list<array{string, ?string, string}>
     */
    private static function hops(string $path): array
    {
        $hops = [];
        if (1 === preg_match('/^([A-Za-z_][\w\\\\]*)::\$(\w+)/', $path, $static)) {
            $hops[] = ['', $static[1], $static[2]];
        }
        preg_match_all('/->\$((?:[A-Za-z_][\w\\\\]*::)?\w+)/', $path, $matches, \PREG_OFFSET_CAPTURE);
        foreach ($matches[1] as [$property, $offset]) {
            $hops[] = [substr($path, 0, $offset - 3), null, $property];
        }

        return $hops;
    }

    private static function classOf(?StateNode $node): ?string
    {
        if (null === $node) {
            return null;
        }
        $class = str_starts_with($node->type, 'ref ') ? substr($node->type, 4) : $node->type;

        return class_exists($class) ? $class : null;
    }

    private static function ownerOf(?StateNode $node): ?string
    {
        return null !== $node && null !== $node->owner && class_exists($node->owner) ? $node->owner : null;
    }

    /**
     * @return \ReflectionClass<object>|null
     */
    private static function declaring(string $class, string $property): ?\ReflectionClass
    {
        if (!class_exists($class)) {
            return null;
        }
        for ($reflection = new \ReflectionClass($class); false !== $reflection; $reflection = $reflection->getParentClass()) {
            if ($reflection->hasProperty($property)) {
                return $reflection->getProperty($property)->getDeclaringClass();
            }
        }

        return null;
    }

    /**
     * The vendor directory of the package that declares the owner, like "vendor/symfony/validator/".
     */
    private static function package(string $owner, string $projectDir): ?string
    {
        if (!class_exists($owner)) {
            return null;
        }
        $file = (new \ReflectionClass($owner))->getFileName();
        if (!\is_string($file) || 1 !== preg_match('#^'.preg_quote($projectDir, '#').'/(vendor/[^/]+/[^/]+/)#', $file, $match)) {
            return null;
        }

        return $match[1];
    }
}
