<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\Probe;
use IgorDoctor\Runtime\Audit\ProbeRun;
use IgorDoctor\Runtime\Audit\ResponseEchoOracle;
use IgorDoctor\Runtime\Snapshot\Snapshot;
use PHPUnit\Framework\TestCase;

final class ResponseEchoOracleTest extends TestCase
{
    public function testAnAnswerShowingWhatOnlyTheFirstRequestSentIsALeak(): void
    {
        $finding = (new ResponseEchoOracle())->check($this->probeRun('Recently viewed: /posts/first-post, /posts/second-post'), 'Recently viewed: /posts/second-post');

        self::assertNotNull($finding);
        self::assertTrue($finding->certain);
        self::assertStringContainsString('"/posts/first-post"', $finding->evidence);
    }

    public function testAValueAFreshWorkerAlsoShowsIsNotALeak(): void
    {
        self::assertNull((new ResponseEchoOracle())->check($this->probeRun('Menu: /posts/first-post /posts/second-post'), 'Menu: /posts/first-post /posts/second-post'));
    }

    public function testAnHtmlEscapedMarkerIsFound(): void
    {
        self::assertNotNull((new ResponseEchoOracle())->check($this->probeRun('<p>marker-one&amp;x</p>'), '<p></p>', 'marker-one&x'));
    }

    private function probeRun(string $secondAnswer, string $firstMarker = 'marker-one'): ProbeRun
    {
        $snapshots = [new Snapshot([]), new Snapshot([]), new Snapshot([]), new Snapshot([])];

        return new ProbeRun(
            new Probe('post', '{/posts/first-post|/posts/second-post}'),
            [1 => $firstMarker, 2 => 'marker-two', 3 => 'marker-three'],
            $snapshots,
            [1 => 200, 2 => 200, 3 => 200],
            [],
            [1 => '', 2 => $secondAnswer, 3 => ''],
        );
    }
}
