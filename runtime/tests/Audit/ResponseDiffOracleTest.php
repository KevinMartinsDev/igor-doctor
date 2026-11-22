<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\LeakType;
use IgorDoctor\Runtime\Audit\Probe;
use IgorDoctor\Runtime\Audit\ResponseDiffOracle;
use IgorDoctor\Runtime\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\TestCase;

final class ResponseDiffOracleTest extends TestCase
{
    public function testItCatchesADecisionCachedByTheFirstUser(): void
    {
        $oracle = new ResponseDiffOracle(static fn (): TestKernel => TestKernel::create());

        $finding = $oracle->compare(
            [new Probe('secret as admin', '/secret', headers: ['X-User' => 'admin'])],
            new Probe('secret as alice', '/secret', headers: ['X-User' => 'alice']),
        );

        self::assertNotNull($finding);
        self::assertSame(LeakType::CrossRequestStateLeak, $finding->type);
        self::assertTrue($finding->certain);
        self::assertStringContainsString('"200 the secret report" instead of "403 denied"', $finding->evidence);
    }

    public function testItReportsNothingWhenTheAnswerDoesNotDependOnEarlierRequests(): void
    {
        $oracle = new ResponseDiffOracle(static fn (): TestKernel => TestKernel::create());

        self::assertNull($oracle->compare(
            [new Probe('routes', '/routes')],
            new Probe('routes again', '/routes'),
        ));
    }
}
