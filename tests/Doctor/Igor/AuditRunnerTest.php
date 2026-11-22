<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Igor;

use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Igor\AuditRunner;
use IgorDoctor\Doctor\ToolingException;
use PHPUnit\Framework\TestCase;

final class AuditRunnerTest extends TestCase
{
    public function testParsesTheFrozenDemoLeakAudit(): void
    {
        $payload = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/mcp-audit-project-demo-leak.json'), true, flags: \JSON_THROW_ON_ERROR);

        [$findings, $total] = AuditRunner::parseAuditPage($payload);

        self::assertSame(32, $total);
        self::assertCount(32, $findings);
        self::assertCount(17, array_filter($findings, static fn (Finding $f): bool => $f->isBlocking()));

        $first = $findings[0];
        self::assertSame('src/Controller/LeakDemoController.php', $first->file);
        self::assertSame(73, $first->line);
        self::assertSame('DetectSingletonMutation', $first->ruleId);
        self::assertSame('stateful', $first->method);
    }

    public function testRejectsAPayloadWithoutFindings(): void
    {
        $this->expectException(ToolingException::class);

        AuditRunner::parseAuditPage(['errors' => 0]);
    }

    public function testRejectsAFindingWithoutRule(): void
    {
        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage('rule_id');

        AuditRunner::parseAuditPage(['total' => 1, 'findings' => [['file' => 'a.php', 'line' => 1, 'severity' => 'ERROR', 'message' => 'm']]]);
    }

    public function testFindingIdIgnoresTheLine(): void
    {
        $data = ['file' => 'src/A.php', 'line' => 5, 'rule_id' => 'StateMutation', 'severity' => 'ERROR', 'message' => "Mutation of state 'x'"];

        self::assertSame(Finding::fromIgor($data)->id(), Finding::fromIgor(['line' => 9] + $data)->id());
        self::assertNotSame(Finding::fromIgor($data)->id(), Finding::fromIgor(['rule_id' => 'Other'] + $data)->id());
    }
}
