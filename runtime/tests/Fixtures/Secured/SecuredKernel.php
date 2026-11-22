<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\Secured;

use IgorDoctor\Runtime\IgorRuntimeBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * An application with a login: a session firewall over every page, two users in memory, and a listener
 * that sets the timezone of the logged-in user without setting it back for an anonymous visitor.
 */
final class SecuredKernel extends Kernel
{
    use MicroKernelTrait;

    private static bool $cacheCleared = false;

    public static function create(): self
    {
        $kernel = new self('secured', false);
        if (!self::$cacheCleared) {
            (new Filesystem())->remove($kernel->getCacheDir());
            self::$cacheCleared = true;
        }

        return $kernel;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new IgorRuntimeBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/igor-doctor-runtime-tests/secured-cache';
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
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file', 'save_path' => sys_get_temp_dir().'/igor-doctor-runtime-tests/sessions'],
        ]);
        $container->extension('security', [
            'providers' => ['users' => ['memory' => ['users' => [
                'alice' => ['password' => 'unused', 'roles' => ['ROLE_USER']],
                'bob' => ['password' => 'unused', 'roles' => ['ROLE_USER']],
            ]]]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
        ]);

        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->load(__NAMESPACE__.'\\', __DIR__)
            ->exclude([__DIR__.'/SecuredKernel.php']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('page', '/page')->controller(PageController::class);
        $routes->import(__DIR__.'/ProfileController.php', 'attribute');
    }
}
