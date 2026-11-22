<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Igor;

use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the auditor and the reference reader of one target, which share one binary locator. The MCP client is a lazy service: its server definition reads
 * environment variables that AuditRunner sets once the target is known.
 */
final class AuditRunnerFactory
{
    /** @var array<string, IgorBinaryLocator> */
    private array $locators = [];

    public function __construct(
        #[Autowire(service: 'mcp.client.igor_doctor', lazy: true)]
        private readonly McpClientInterface $client,
    ) {
    }

    public function forTarget(string $targetDir): AuditRunner
    {
        return new AuditRunner($this->client, $targetDir, $this->locator($targetDir), new ContainerRefresher($targetDir));
    }

    public function referenceFor(string $targetDir): IgorReference
    {
        return new IgorReference($this->client, $targetDir, $this->locator($targetDir));
    }

    private function locator(string $targetDir): IgorBinaryLocator
    {
        return $this->locators[$targetDir] ??= IgorBinaryLocator::forTarget($targetDir);
    }
}
