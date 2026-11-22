<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Snapshot;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;

/**
 * Lists the roots of the state a worker keeps: the services the compiled container has
 * instantiated, public and private, the static properties and the `static` variables of the
 * methods of the classes the filter keeps, and the state of the PHP process itself.
 */
final class ContainerStateExtractor
{
    public const PROCESS = 'php process';

    private const SKIPPED_SERVICES = ['kernel', 'service_container'];

    public function __construct(private readonly NamespaceFilter $filter)
    {
    }

    /**
     * @return array<string, array{mixed, ?string}> path => [value, owner]
     */
    public function roots(Container $container): array
    {
        $read = \Closure::bind(fn (): array => [$this->services, $this->privates], $container, Container::class);
        [$services, $privates] = $read();

        $roots = [];
        foreach ($services + $privates as $id => $service) {
            if (!\is_object($service) || $service instanceof ContainerInterface || \in_array($id, self::SKIPPED_SERVICES, true)) {
                continue;
            }
            $roots['@'.$id] = [$service, null];
        }
        ksort($roots);

        return $roots + $this->statics() + self::process();
    }

    /**
     * What a worker keeps that no object holds. FrankenPHP fills $_GET, $_POST, $_COOKIE,
     * $_FILES and $_SERVER again for each request, so they are left out; $_ENV, the environment,
     * $_SESSION and the process settings are not.
     *
     * @return array<string, array{mixed, ?string}>
     */
    public static function process(): array
    {
        $process = [
            'timezone' => date_default_timezone_get(),
            'locale' => setlocale(\LC_ALL, '0'),
            'error_reporting' => error_reporting(),
            'cwd' => getcwd(),
            'getenv' => getenv(),
            '$_ENV' => $_ENV,
            '$_SESSION' => $_SESSION ?? null,
        ];
        if (\function_exists('mb_internal_encoding')) {
            $process['mb_internal_encoding'] = mb_internal_encoding();
        }

        $roots = [];
        foreach ($process as $name => $value) {
            $roots['{process}->'.$name] = [$value, self::PROCESS];
        }

        return $roots;
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    private function statics(): array
    {
        $roots = [];
        foreach (get_declared_classes() as $class) {
            if (!$this->filter->matches($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            if ($reflection->isInternal() || $reflection->isAnonymous()) {
                continue;
            }

            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->name !== $class || !$property->isInitialized()) {
                    continue;
                }
                $roots[$class.'::$'.$property->name] = [$property->getValue(), $class];
            }

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->name !== $class || $method->isAbstract()) {
                    continue;
                }
                foreach ($method->getStaticVariables() as $name => $value) {
                    $roots[$class.'::'.$method->name.'()::static $'.$name] = [$value, $class];
                }
            }
        }
        ksort($roots);

        return $roots;
    }
}
