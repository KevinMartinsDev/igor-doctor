<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime;

use IgorDoctor\Runtime\Audit\ProbeFactory;
use IgorDoctor\Runtime\Audit\SessionLogin;
use IgorDoctor\Runtime\Command\WorkerScanCommand;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Registers `igor:worker-scan` in the audited application. It adds no service to the requests
 * the application serves; it only makes API Platform's resource metadata readable by the command,
 * to build probes from the declared parameters, and the firewalls, user providers and session factory
 * readable, to log requests in as a user.
 *
 * A plain Bundle, not an AbstractBundle, so that it also loads in Symfony 5.4 applications.
 */
final class IgorRuntimeBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->register(WorkerScanCommand::class, WorkerScanCommand::class)
            ->setArguments([new Reference('kernel')])
            ->addTag('console.command', ['command' => 'igor:worker-scan']);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $aliases = [
                    ProbeFactory::RESOURCE_METADATA => 'api_platform.metadata.resource.metadata_collection_factory',
                    SessionLogin::FIREWALL_MAP => 'security.firewall.map',
                    SessionLogin::USER_PROVIDERS => 'security.user_providers',
                    SessionLogin::SESSION_FACTORY => 'session.factory',
                ];
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, SessionLogin::PROVIDER_PREFIX)) {
                        $aliases[SessionLogin::USER_PROVIDER.substr($id, \strlen(SessionLogin::PROVIDER_PREFIX))] = $id;
                    }
                }
                foreach ($aliases as $alias => $id) {
                    if ($container->has($id)) {
                        $container->setAlias($alias, $id)->setPublic(true);
                    }
                }
            }
        });
    }

    public function getContainerExtension(): ?\Symfony\Component\DependencyInjection\Extension\ExtensionInterface
    {
        return null;
    }
}
