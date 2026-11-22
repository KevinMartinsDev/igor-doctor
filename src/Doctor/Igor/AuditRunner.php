<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Igor;

use IgorDoctor\Doctor\Auditor;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\ToolingException;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Exception\ExceptionInterface as McpException;

/**
 * Audits the target with Igor's MCP server (igor-php mcp, Igor 0.11+), through the Symfony MCP bundle.
 *
 * The server is declared in config/packages/mcp.yaml. Every audit rebuilds the container and ends by
 * closing the connection, so that the next one starts a new session and reflects the files on disk. All knowledge of Igor's output format stays in this class.
 */
final class AuditRunner implements Auditor
{
    private const PAGE_SIZE = 200;

    public const ENV_BINARY = 'IGOR_DOCTOR_BIN';
    public const ENV_TARGET = 'IGOR_DOCTOR_TARGET';
    private const SERVER = 'igor';

    public function __construct(
        private readonly McpClientInterface $client,
        private readonly string $targetDir,
        private readonly IgorBinaryLocator $locator,
        private readonly ContainerRefresher $refresher,
    ) {
    }

    public function audit(): array
    {
        $binary = $this->locator->locate();
        $this->refresher->refresh();

        self::exportServerEnvironment($binary, $this->targetDir);

        $connection = null;
        try {
            $connection = $this->client->get(self::SERVER);

            $findings = [];
            $offset = 0;
            do {
                $result = $connection->callTool('audit_project', ['offset' => $offset, 'limit' => self::PAGE_SIZE]);
                if ($result->isError || !\is_array($result->structuredContent)) {
                    throw new ToolingException('Igor audit_project failed: '.self::text($result->content));
                }
                [$page, $total] = self::parseAuditPage($result->structuredContent);
                array_push($findings, ...$page);
                $offset += \count($page);
            } while ([] !== $page && $offset < $total);

            return $findings;
        } catch (McpException $e) {
            throw new ToolingException('Igor MCP server failed: '.$e->getMessage(), previous: $e);
        } finally {
            try {
                $connection?->disconnect();
            } catch (McpException) {
                // The audit result is already known: a failing close must not hide it.
            }
        }
    }

    /**
     * config/packages/mcp.yaml reads the server command and directory from these variables, because
     * the Igor binary and the target are only known at run time. The lazy MCP client resolves them
     * on its first request, after this call; Symfony falls back to getenv(), so putenv() is enough.
     */
    public static function exportServerEnvironment(string $binary, string $targetDir): void
    {
        putenv(self::ENV_BINARY.'='.$binary);
        putenv(self::ENV_TARGET.'='.$targetDir);
    }

    /**
     * @param array<mixed> $structured structured content of an audit_project result
     *
     * @return array{list<Finding>, int} the findings of the page and the total number of findings
     */
    public static function parseAuditPage(array $structured): array
    {
        if (!isset($structured['findings']) || !\is_array($structured['findings']) || !isset($structured['total'])) {
            throw new ToolingException('Igor audit_project returned an unexpected payload (no "findings" or "total").');
        }

        $findings = [];
        foreach ($structured['findings'] as $entry) {
            if (!\is_array($entry)) {
                throw new ToolingException('Igor audit_project returned a finding that is not an object.');
            }
            $findings[] = Finding::fromIgor($entry);
        }

        return [$findings, (int) $structured['total']];
    }

    /**
     * @param array<mixed> $content
     */
    private static function text(array $content): string
    {
        $text = [];
        foreach ($content as $item) {
            if (isset($item->text)) {
                $text[] = $item->text;
            }
        }

        return [] === $text ? '(no message)' : implode("\n", $text);
    }
}
