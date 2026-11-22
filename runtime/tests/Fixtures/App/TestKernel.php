<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    private static bool $cacheCleared = false;

    public static function create(): self
    {
        $kernel = new self('runtime', false);
        if (!self::$cacheCleared) {
            (new Filesystem())->remove($kernel->getCacheDir());
            self::$cacheCleared = true;
        }

        return $kernel;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/igor-doctor-runtime-tests/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/igor-doctor-runtime-tests/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'runtime-tests',
            'test' => false,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);

        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->load(__NAMESPACE__.'\\', __DIR__)
            ->exclude([__DIR__.'/TestKernel.php', __DIR__.'/console.php', __DIR__.'/Metadata/Operation.php', __DIR__.'/Metadata/Parameter.php']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__.'/Controller/', 'attribute');
    }
}
