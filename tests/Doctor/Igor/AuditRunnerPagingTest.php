<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Igor;

use IgorDoctor\Doctor\Igor\AuditRunner;
use IgorDoctor\Doctor\Igor\ContainerRefresher;
use IgorDoctor\Doctor\Igor\IgorBinaryLocator;
use IgorDoctor\Doctor\ToolingException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\McpBundle\Exception\RemoteCallException;

final class AuditRunnerPagingTest extends TestCase
{
    public function testPagesThroughTheAuditAndClosesTheConnection(): void
    {
        $page = static fn (int $line): array => ['file' => 'src/A.php', 'line' => $line, 'rule_id' => 'StateMutation', 'severity' => 'ERROR', 'message' => 'm'];
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->expects(self::exactly(2))->method('callTool')
            ->willReturnOnConsecutiveCalls(
                new CallToolResult([], structuredContent: ['total' => 2, 'findings' => [$page(1)]]),
                new CallToolResult([], structuredContent: ['total' => 2, 'findings' => [$page(2)]]),
            );
        $connection->expects(self::once())->method('disconnect');

        self::assertCount(2, $this->runner($connection)->audit());
    }

    public function testAnErrorResultIsAToolingError(): void
    {
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->method('callTool')->willReturn(new CallToolResult([new TextContent('boom')], isError: true));
        $connection->expects(self::once())->method('disconnect');

        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage('boom');

        $this->runner($connection)->audit();
    }

    public function testAFailedRequestIsAToolingError(): void
    {
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->method('callTool')->willThrowException(new RemoteCallException('request failed'));

        $this->expectException(ToolingException::class);

        $this->runner($connection)->audit();
    }

    private function runner(ServerConnectionInterface $connection): AuditRunner
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('get')->with('igor')->willReturn($connection);

        $binary = (string) realpath(\PHP_BINARY);

        return new AuditRunner($client, sys_get_temp_dir(), new IgorBinaryLocator(sys_get_temp_dir(), $binary), new ContainerRefresher(sys_get_temp_dir()));
    }
}
