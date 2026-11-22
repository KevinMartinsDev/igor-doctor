<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\LeakClassifier;
use IgorDoctor\Runtime\Audit\LeakFinding;
use IgorDoctor\Runtime\Audit\LeakType;
use IgorDoctor\Runtime\Audit\Probe;
use IgorDoctor\Runtime\Audit\WorkerStateAuditor;
use IgorDoctor\Runtime\Snapshot\ContainerStateExtractor;
use IgorDoctor\Runtime\Snapshot\NamespaceFilter;
use IgorDoctor\Runtime\Snapshot\StateSnapshotter;
use IgorDoctor\Runtime\Tests\Fixtures\App\Metadata\Parameter;
use IgorDoctor\Runtime\Tests\Fixtures\App\Service\AccessLog;
use IgorDoctor\Runtime\Tests\Fixtures\App\Service\RequestHolder;
use IgorDoctor\Runtime\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerStateAuditorTest extends TestCase
{
    public function testItFindsARequestValueWrittenIntoCachedMetadata(): void
    {
        $findings = $this->audit(new Probe('books', '/books?title={marker}'));

        $leak = $this->only($findings, LeakType::CrossRequestStateLeak);
        self::assertTrue($leak->certain);
        self::assertSame(Parameter::class, $leak->owner);
        self::assertContains('@'.\IgorDoctor\Runtime\Tests\Fixtures\App\Controller\FixtureController::class.'->$metadata->$localCache[Book][get_collection]->$parameters[title]->$extraProperties[_values]', $leak->paths);
    }

    public function testItFindsAGrowingArray(): void
    {
        $growth = $this->only($this->audit(new Probe('log', '/log')), LeakType::MonotonicMemoryGrowth);

        self::assertSame(AccessLog::class, $growth->owner);
        self::assertStringContainsString('1 → 2 → 3', $growth->evidence);
    }

    public function testItFindsARequestKeptAliveByAService(): void
    {
        $findings = $this->audit(new Probe('hold', '/hold?q={marker}'));

        self::assertCount(1, $findings);
        $zombie = $this->only($findings, LeakType::ZombieRequestRetention);
        self::assertTrue($zombie->certain);
        self::assertSame(RequestHolder::class, $zombie->owner);
        self::assertSame(['@'.\IgorDoctor\Runtime\Tests\Fixtures\App\Controller\FixtureController::class.'->$holder->$last'], $zombie->paths);
    }

    /**
     * @return iterable<string, array{Probe}>
     */
    public static function cleanProbes(): iterable
    {
        yield 'metadata copied before the request values are set' => [new Probe('books-fixed', '/books-fixed?title={marker}')];
        yield 'cache built by the first request only' => [new Probe('routes', '/routes')];
        yield 'buffer emptied by services_resetter' => [new Probe('buffer', '/buffer?line={marker}')];
    }

    #[DataProvider('cleanProbes')]
    public function testItReportsNothingOnStateThatDoesNotLeak(Probe $probe): void
    {
        self::assertSame([], array_map(static fn (LeakFinding $finding): array => $finding->toArray(), $this->audit($probe)));
    }

    /**
     * @return list<LeakFinding>
     */
    private function audit(Probe $probe): array
    {
        $filter = new NamespaceFilter(['IgorDoctor\\Runtime\\Tests\\Fixtures\\App\\']);
        $kernel = TestKernel::create();
        $auditor = new WorkerStateAuditor($kernel, new StateSnapshotter($filter), new ContainerStateExtractor($filter));

        $run = $auditor->run($probe);
        self::assertSame([1 => 200, 2 => 200, 3 => 200], $run->statuses);
        $kernel->shutdown();

        return (new LeakClassifier())->classify($run);
    }

    /**
     * @param list<LeakFinding> $findings
     */
    private function only(array $findings, LeakType $type): LeakFinding
    {
        $matching = array_values(array_filter($findings, static fn (LeakFinding $finding): bool => $type === $finding->type));
        self::assertCount(1, $matching, json_encode(array_map(static fn (LeakFinding $finding): array => $finding->toArray(), $findings), \JSON_PRETTY_PRINT) ?: '');

        return $matching[0];
    }
}
