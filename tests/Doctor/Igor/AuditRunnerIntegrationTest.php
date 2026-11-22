<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Igor;

use IgorDoctor\Doctor\Igor\AuditRunnerFactory;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the real Igor MCP server on examples/demo-leak (needs "composer install" in that directory).
 */
#[Group('igor')]
final class AuditRunnerIntegrationTest extends KernelTestCase
{
    public function testAuditsTheDemoProjectThroughMcp(): void
    {
        self::bootKernel();
        $demo = \dirname(__DIR__, 3).'/examples/demo-leak';
        if (!is_file($demo.'/vendor/bin/igor-php')) {
            self::markTestSkipped('Run "composer install" in examples/demo-leak to enable this test.');
        }

        $runner = self::getContainer()->get(AuditRunnerFactory::class)->forTarget($demo);

        $findings = $runner->audit();
        self::assertNotEmpty($findings);
        self::assertCount(\count($findings), $runner->audit(), 'A second audit in the same process reconnects.');
        self::assertContains('StateMutation', array_map(static fn ($f) => $f->ruleId, $findings));
    }
}
