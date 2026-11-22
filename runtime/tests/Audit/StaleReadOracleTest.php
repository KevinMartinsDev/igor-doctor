<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\StaleReadOracle;
use PHPUnit\Framework\TestCase;

final class StaleReadOracleTest extends TestCase
{
    public function testTheSameAnswerProvesNothing(): void
    {
        self::assertNull(StaleReadOracle::difference([200, '{"title":null}'], [200, '{"title":null}']));
    }

    public function testTokensDatesAndIdentifiersDoNotCountAsADifference(): void
    {
        $used = [200, '<input name="_token" value="a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8"> 2026-11-20T10:30:12+01:00 #1795210574'];
        $fresh = [200, '<input name="_token" value="f9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2"> 2026-11-20T10:30:14+01:00 #1795210580'];

        self::assertNull(StaleReadOracle::difference($used, $fresh));
    }

    public function testADifferentBodyOrStatusIsReportedWithAnExcerpt(): void
    {
        $difference = (string) StaleReadOracle::difference([200, '{"title":"kept-by-the-previous-visitor"}'], [200, '{"title":null}']);
        self::assertStringStartsWith('a request without parameters answers differently on this worker', $difference);
        self::assertStringContainsString('kept-by-the-previous-visitor', $difference);
        self::assertSame('a request without parameters answers 200 on this worker and 404 on a fresh one', StaleReadOracle::difference([200, 'ok'], [404, 'ok']));
    }
}
