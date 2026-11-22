<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Igor;

use IgorDoctor\Doctor\Briefing\RuleReference;
use IgorDoctor\Doctor\Briefing\ServiceContext;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\ToolingException;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Exception\ExceptionInterface as McpException;

/**
 * Reads the reference tools of Igor's MCP server (get_rule, explain_service), through the same client
 * as AuditRunner.
 *
 * Every answer is cached for the run. A failure gives no reference instead of an error: the
 * briefing helps the agent, it never decides whether the run can go on.
 */
final class IgorReference implements RuleReference, ServiceContext
{
    private const SERVER = 'igor';
    private const MAX_RELATED_FILES = 3;

    /** @var array<string, string|null> */
    private array $rules = [];

    /** @var array<string, list<array<mixed>>> */
    private array $services = [];

    public function __construct(
        private readonly McpClientInterface $client,
        private readonly string $targetDir,
        private readonly IgorBinaryLocator $locator,
    ) {
    }

    public function rule(string $ruleId): ?string
    {
        if (!\array_key_exists($ruleId, $this->rules)) {
            $doc = $this->call('get_rule', ['rule_id' => $ruleId])['doc'] ?? null;
            $this->rules[$ruleId] = \is_string($doc) && '' !== trim($doc) ? trim($doc) : null;
        }

        return $this->rules[$ruleId];
    }

    /**
     * The finding's service lists its dependencies (class names or service ids). Those named in the
     * code line of the finding ($this->closureLeakService for App\Service\ClosureLeakService) are the
     * ones the fix may depend on; Igor gives the file of each.
     */
    public function relatedFiles(Finding $finding): array
    {
        if (null === $finding->code) {
            return [];
        }
        $code = self::normalize($finding->code);

        $dependencies = [];
        foreach ($this->services($finding->class ?? $finding->file) as $service) {
            if (($service['file'] ?? null) === $finding->file && \is_array($service['dependencies'] ?? null)) {
                $dependencies = array_filter($service['dependencies'], 'is_string');
                break;
            }
        }

        $files = [];
        foreach ($dependencies as $dependency) {
            $name = self::normalize((string) preg_replace('/^.*[\\\\.]/', '', $dependency));
            if (\strlen($name) < 4 || 1 !== preg_match('/[a-z]/', $name) || !str_contains($code, $name)) {
                continue;
            }
            if (null !== $file = $this->fileOf($dependency)) {
                $files[$file] = true;
            }
            if (\count($files) >= self::MAX_RELATED_FILES) {
                break;
            }
        }
        unset($files[$finding->file]);

        return array_keys($files);
    }

    private function fileOf(string $serviceId): ?string
    {
        $matches = $this->services($serviceId);
        foreach ($matches as $service) {
            if (($service['service_id'] ?? null) === $serviceId && \is_string($service['file'] ?? null) && '' !== $service['file']) {
                return $service['file'];
            }
        }

        // explain_service matches on a part of the name: a single match is the service.
        return 1 === \count($matches) && \is_string($matches[0]['file'] ?? null) && '' !== $matches[0]['file'] ? $matches[0]['file'] : null;
    }

    /**
     * @return list<array<mixed>>
     */
    private function services(string $name): array
    {
        if (!isset($this->services[$name])) {
            $services = $this->call('explain_service', ['name' => $name])['services'] ?? null;
            $this->services[$name] = \is_array($services) ? array_values(array_filter($services, 'is_array')) : [];
        }

        return $this->services[$name];
    }

    private static function normalize(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($text));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>|null the structured content of the result, null on any failure
     */
    private function call(string $tool, array $arguments): ?array
    {
        $connection = null;
        try {
            AuditRunner::exportServerEnvironment($this->locator->locate(), $this->targetDir);
            $connection = $this->client->get(self::SERVER);
            $result = $connection->callTool($tool, $arguments);

            return !$result->isError && \is_array($result->structuredContent) ? $result->structuredContent : null;
        } catch (McpException|ToolingException) {
            return null;
        } finally {
            try {
                $connection?->disconnect();
            } catch (McpException) {
                // The answer is already known.
            }
        }
    }
}
