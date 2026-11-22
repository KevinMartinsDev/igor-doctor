<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Builds one probe per GET route of the application, without knowing where a leak could be.
 *
 * - A path variable gets `{a|b|a}` when its requirement lists values (`en|fr`), else its default
 *   (an empty default drops it, with its separator), else `1` when it names an identifier, else a
 *   marker when the requirement accepts one, else a value the requirement accepts.
 * - The query parameters API Platform declares on the operation get a marker, or alternatives
 *   for booleans and integers, which cannot carry one.
 * - Every probe also sends a marked query parameter and header, and alternates Accept-Language.
 */
final class ProbeFactory
{
    public const RESOURCE_METADATA = 'igor_runtime.resource_metadata_collection_factory';

    private const SAMPLE_MARKER = 'igor1x0123456789';

    private const INTERNAL_ROUTES = ['_profiler', '_wdt', '_preview_error', '_error'];

    /**
     * @param object|null $resourceMetadata API Platform's resource metadata collection factory, when installed
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ?object $resourceMetadata = null,
    ) {
    }

    /**
     * @return list<Probe>
     */
    public function probes(?string $routePattern = null): array
    {
        $probes = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if (self::isInternal($name) || (null !== $routePattern && 1 !== preg_match($routePattern, $name))) {
                continue;
            }

            $methods = $route->getMethods();
            if ([] !== $methods && !\in_array('GET', $methods, true)) {
                continue;
            }

            $path = $this->path($route);
            if (null === $path) {
                continue;
            }

            $query = [...$this->apiParameters($route), 'igor' => Probe::MARKER];
            $probes[] = new Probe(
                $name,
                $path.'?'.implode('&', array_map(static fn (string $key, string $value): string => rawurlencode($key).'='.$value, array_keys($query), $query)),
                'GET',
                ['X-Igor' => Probe::MARKER, 'Accept-Language' => '{en|fr|en}'],
            );
        }

        return $probes;
    }

    private static function isInternal(string $name): bool
    {
        foreach (self::INTERNAL_ROUTES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function path(Route $route): ?string
    {
        $path = $route->getPath();
        foreach ($route->compile()->getPathVariables() as $variable) {
            if ($route->hasDefault($variable) && \in_array($route->getDefault($variable), [null, ''], true)) {
                $path = (string) preg_replace('#[./]?\{'.preg_quote($variable, '#').'\}#', '', $path);

                continue;
            }

            $value = $this->pathValue($route, $variable);
            if (null === $value) {
                return null;
            }
            $path = str_replace('{'.$variable.'}', $value, $path);
        }

        return '' === $path ? '/' : $path;
    }

    private function pathValue(Route $route, string $variable): ?string
    {
        $requirement = $route->getRequirement($variable);
        $default = $route->getDefault($variable);

        if (null !== $requirement && 1 === preg_match('/^[\w-]+(?:\|[\w-]+)+$/', $requirement)) {
            // The route's default comes first, when it is one of the alternatives: the page as most visitors get it.
            $alternatives = explode('|', $requirement);
            $first = \is_scalar($default) && \in_array((string) $default, $alternatives, true) ? (string) $default : $alternatives[0];
            $second = array_values(array_diff($alternatives, [$first]))[0];

            return '{'.$first.'|'.$second.'|'.$first.'}';
        }

        if ($route->hasDefault($variable) && \is_scalar($default)) {
            return (string) $default;
        }

        if (1 === preg_match('/(?:^id|Id|_id)$/', $variable) && (null === $requirement || self::accepts($requirement, '1'))) {
            return '1';
        }

        if (null === $requirement || self::accepts($requirement, self::SAMPLE_MARKER)) {
            return Probe::MARKER;
        }

        // A locale (`en_US`) matches the locale requirements of shops and multilingual sites.
        foreach (['1', '2026-01-01', 'en_US', 'en', 'a'] as $candidate) {
            if (self::accepts($requirement, $candidate)) {
                return $candidate;
            }
        }

        return \is_scalar($default) ? (string) $default : null;
    }

    private static function accepts(string $requirement, string $value): bool
    {
        return 1 === @preg_match('#^(?:'.str_replace('#', '\#', $requirement).')$#u', $value);
    }

    /**
     * @return array<string, string>
     */
    private function apiParameters(Route $route): array
    {
        $resourceClass = $route->getDefault('_api_resource_class');
        $operationName = $route->getDefault('_api_operation_name');
        if (null === $this->resourceMetadata || !\is_string($resourceClass) || !\is_string($operationName) || !method_exists($this->resourceMetadata, 'create')) {
            return [];
        }

        try {
            $operation = $this->resourceMetadata->create($resourceClass)->getOperation($operationName);
            $parameters = method_exists($operation, 'getParameters') ? $operation->getParameters() : null;
        } catch (\Throwable) {
            return [];
        }

        $query = [];
        foreach ($parameters ?? [] as $key => $parameter) {
            if (!\is_string($key) || str_contains($key, ':') || !str_ends_with($parameter::class, 'QueryParameter')) {
                continue;
            }
            $query[$key] = match (self::nativeType($parameter)) {
                'bool' => '{false|true|false}',
                'int' => '{1|2|1}',
                default => Probe::MARKER,
            };
        }

        return $query;
    }

    private static function nativeType(object $parameter): ?string
    {
        $type = method_exists($parameter, 'getNativeType') ? $parameter->getNativeType() : null;
        if (null !== $type) {
            return (string) $type;
        }

        $schema = method_exists($parameter, 'getSchema') ? $parameter->getSchema() : null;

        return match ($schema['type'] ?? null) {
            'boolean' => 'bool',
            'integer' => 'int',
            default => null,
        };
    }
}
