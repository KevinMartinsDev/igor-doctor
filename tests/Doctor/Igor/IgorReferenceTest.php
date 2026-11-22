<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Igor;

use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Igor\IgorBinaryLocator;
use IgorDoctor\Doctor\Igor\IgorReference;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Client\McpClientInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\AI\McpBundle\Exception\RemoteCallException;

final class IgorReferenceTest extends TestCase
{
    public function testReadsTheRuleFromAFrozenGetRuleAnswerAndCachesIt(): void
    {
        /** @var array{structuredContent: array<string, mixed>} $frozen */
        $frozen = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/mcp-get-rule-DetectClosureStateLeak.json'), true, flags: \JSON_THROW_ON_ERROR);

        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->expects(self::once())->method('callTool')
            ->with('get_rule', ['rule_id' => 'DetectClosureStateLeak'])
            ->willReturn(new CallToolResult([], structuredContent: $frozen['structuredContent']));
        $connection->expects(self::once())->method('disconnect');

        $reference = $this->reference($connection);
        $doc = $reference->rule('DetectClosureStateLeak');

        self::assertNotNull($doc);
        self::assertStringStartsWith('**Message**', $doc);
        self::assertStringContainsString('**Fix**', $doc);
        self::assertSame($doc, $reference->rule('DetectClosureStateLeak'), 'The second read comes from the cache.');
    }

    public function testAnUnknownRuleGivesNoReference(): void
    {
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->method('callTool')->willReturn(new CallToolResult([new TextContent('unknown rule "X"')], isError: true));

        self::assertNull($this->reference($connection)->rule('X'));
    }

    public function testAFailingServerGivesNoReferenceInsteadOfAnError(): void
    {
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->method('callTool')->willThrowException(new RemoteCallException('request failed'));

        self::assertNull($this->reference($connection)->rule('StateMutation'));
    }

    public function testRelatedFilesAreTheDependenciesNamedInTheLineOfTheFinding(): void
    {
        $services = [
            'App\\Controller\\LeakDemoController' => [['service_id' => 'App\\Controller\\LeakDemoController', 'file' => 'src/Controller/LeakDemoController.php', 'dependencies' => [
                'App\\Service\\StatefulService',
                'App\\Service\\ClosureLeakService',
                'event_dispatcher',
                'inlined.Symfony\\Component\\DependencyInjection\\ServiceLocator.2807',
            ]]],
            'App\\Service\\ClosureLeakService' => [['service_id' => 'App\\Service\\ClosureLeakService', 'file' => 'src/Service/ClosureLeakService.php']],
            'event_dispatcher' => [],
        ];
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->method('callTool')->willReturnCallback(static function (string $tool, array $arguments) use ($services): CallToolResult {
            self::assertSame('explain_service', $tool);
            self::assertArrayHasKey($arguments['name'], $services, 'Only the finding\'s class and the dependencies its line names are explained.');

            return new CallToolResult([], structuredContent: ['framework' => 'Symfony', 'matches' => \count($services[$arguments['name']]), 'services' => $services[$arguments['name']]]);
        });

        $finding = new Finding('src/Controller/LeakDemoController.php', 259, 'DetectClosureStateLeak', 'ERROR', 'm', 'App\\Controller\\LeakDemoController', 'closureLeak', "\$this->closureLeakService->addListener('request', function () use (\$v) {");

        self::assertSame(['src/Service/ClosureLeakService.php'], $this->reference($connection)->relatedFiles($finding));
    }

    public function testAFindingWithoutCodeHasNoRelatedFile(): void
    {
        $connection = $this->createMock(ServerConnectionInterface::class);
        $connection->expects(self::never())->method('callTool');

        self::assertSame([], $this->reference($connection)->relatedFiles(new Finding('src/A.php', 1, 'StateMutation', 'ERROR', 'm')));
    }

    private function reference(ServerConnectionInterface $connection): IgorReference
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('get')->with('igor')->willReturn($connection);

        return new IgorReference($client, sys_get_temp_dir(), new IgorBinaryLocator(sys_get_temp_dir(), (string) realpath(\PHP_BINARY)));
    }
}
