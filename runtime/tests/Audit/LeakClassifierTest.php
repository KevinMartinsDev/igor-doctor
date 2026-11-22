<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\LeakClassifier;
use IgorDoctor\Runtime\Audit\LeakFinding;
use IgorDoctor\Runtime\Audit\LeakType;
use IgorDoctor\Runtime\Audit\Probe;
use IgorDoctor\Runtime\Audit\ProbeRun;
use IgorDoctor\Runtime\Snapshot\Snapshot;
use IgorDoctor\Runtime\Snapshot\StateNode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LeakClassifierTest extends TestCase
{
    private const OWNER = 'App\\Service\\Cache';

    public function testStateBuiltByTheFirstRequestAndKeptUnchangedIsAWarmup(): void
    {
        $built = ['@cache->$table' => self::array(2), '@cache->$table[books]' => self::string('/books')];

        self::assertSame([], $this->classify([[], $built, $built, $built]));
    }

    public function testAMarkerLeftInTheStateIsACertainLeak(): void
    {
        $findings = $this->classify([
            [],
            ['@factory->$localCache[Book]->$value' => self::string('m1')],
            ['@factory->$localCache[Book]->$value' => self::string('m2')],
            ['@factory->$localCache[Book]->$value' => self::string('m3')],
        ]);

        self::assertCount(1, $findings);
        self::assertSame(LeakType::CrossRequestStateLeak, $findings[0]->type);
        self::assertTrue($findings[0]->certain);
        self::assertSame(['@factory->$localCache[Book]->$value'], $findings[0]->paths);
        self::assertStringContainsString('request 1', $findings[0]->evidence);
    }

    public function testAMarkerInAnArrayKeyIsReportedOnceWithANormalizedPath(): void
    {
        $findings = $this->classify([
            [],
            ['@cache->$byTitle[m1]' => self::string('x')],
            ['@cache->$byTitle[m1]' => self::string('x'), '@cache->$byTitle[m2]' => self::string('x')],
            ['@cache->$byTitle[m1]' => self::string('x'), '@cache->$byTitle[m2]' => self::string('x'), '@cache->$byTitle[m3]' => self::string('x')],
        ]);

        self::assertCount(1, $findings);
        self::assertSame(['@cache->$byTitle['.Probe::MARKER.']'], $findings[0]->paths);
    }

    public function testAnArrayThatGrowsOnEveryRequestIsMemoryGrowth(): void
    {
        $findings = $this->classify([
            [],
            ['@log->$entries' => self::array(1)],
            ['@log->$entries' => self::array(2)],
            ['@log->$entries' => self::array(3)],
        ]);

        self::assertCount(1, $findings);
        self::assertSame(LeakType::MonotonicMemoryGrowth, $findings[0]->type);
        self::assertStringContainsString('1 → 2 → 3', $findings[0]->evidence);
    }

    public function testAnArrayThatChangesSizeWithoutGrowingIsOnlyASuspect(): void
    {
        $findings = $this->classify([
            [],
            ['@log->$entries' => self::array(2)],
            ['@log->$entries' => self::array(1)],
            ['@log->$entries' => self::array(2)],
        ]);

        self::assertCount(1, $findings);
        self::assertSame(LeakType::CrossRequestStateLeak, $findings[0]->type);
        self::assertFalse($findings[0]->certain);
    }

    public function testAValueThatChangesWithoutAMarkerIsASuspect(): void
    {
        $findings = $this->classify([
            [],
            ['@counter->$count' => self::int(1)],
            ['@counter->$count' => self::int(2)],
            ['@counter->$count' => self::int(3)],
        ]);

        self::assertCount(1, $findings);
        self::assertFalse($findings[0]->certain);
        self::assertStringContainsString('int 1 → int 2', $findings[0]->evidence);
    }

    public function testAValueThatFollowsTheSentAlternativesIsACertainLeak(): void
    {
        $value = static fn (string $flag): array => ['@factory->$cache[get]->$value' => new StateNode('bool', $flag, null, self::OWNER)];

        $findings = $this->classify([[], $value('false'), $value('true'), $value('false')], new Probe('item', '/items/1?published={false|true|false}'));

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->certain);
        self::assertStringContainsString('bool false → bool true → bool false', $findings[0]->evidence);
    }

    public function testACacheFilledForTheSecondAlternativeIsAWarmup(): void
    {
        $findings = $this->classify([
            [],
            ['@factory->$localCache' => self::array(6)],
            ['@factory->$localCache' => self::array(10)],
            ['@factory->$localCache' => self::array(10)],
        ], new Probe('item', '/items/1?published={false|true|false}'));

        self::assertSame([], $findings);
    }

    public function testAnObjectReachedThroughAnotherPathIsUnchanged(): void
    {
        $findings = $this->classify([
            [],
            ['@cache->$operation' => new StateNode('App\\Get', owner: self::OWNER)],
            ['@cache->$operation' => new StateNode('ref App\\Get', owner: self::OWNER)],
            ['@cache->$operation' => new StateNode('App\\Get', owner: self::OWNER)],
        ]);

        self::assertSame([], $findings);
    }

    public function testAProcessSettingChangedOnceIsACertainLeakButAStartedSessionIsNot(): void
    {
        $process = static fn (string $timezone, ?int $session): array => [
            '{process}->timezone' => new StateNode('string', $timezone, null, 'php process'),
            '{process}->$_SESSION' => null === $session ? new StateNode('null', null, null, 'php process') : new StateNode('array', null, $session, 'php process'),
        ];

        $findings = $this->classify([$process('UTC', null), $process('America/New_York', 0), $process('America/New_York', 0), $process('America/New_York', 0)]);

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->certain);
        self::assertSame(['{process}->timezone'], $findings[0]->paths);
        self::assertStringContainsString('string UTC → string America/New_York', $findings[0]->evidence);
    }

    public function testAValueOfTheSecondUserKeptForTheAnonymousRequestIsACertainLeak(): void
    {
        $timezone = static fn (string $zone): array => ['@formatter->$timezone' => self::string($zone)];
        $run = new ProbeRun(new Probe('dashboard', '/dashboard'), [1 => 'm1', 2 => 'm2', 3 => 'm3', 4 => 'm4'], self::snapshots([[], $timezone('Europe/Paris'), $timezone('Asia/Tokyo'), $timezone('Europe/Paris'), $timezone('Europe/Paris')]), [1 => 200, 2 => 200, 3 => 200, 4 => 200], identities: [1 => 'alice', 2 => 'bob', 3 => 'alice', 4 => null]);

        $findings = (new LeakClassifier())->classify($run);

        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->certain);
        self::assertSame(['@formatter->$timezone'], $findings[0]->paths);
        self::assertStringContainsString('anonymous request', $findings[0]->evidence);
    }

    public function testAValueEachRequestWritesAgainIsNotKeptForTheAnonymousRequest(): void
    {
        $locale = static fn (string $value): array => ['@subscriber->$locale' => self::string($value)];
        $run = new ProbeRun(new Probe('dashboard', '/dashboard'), [1 => 'm1', 2 => 'm2', 3 => 'm3', 4 => 'm4'], self::snapshots([[], $locale('fr'), $locale('de'), $locale('fr'), $locale('en')]), [1 => 200, 2 => 200, 3 => 200, 4 => 200], identities: [1 => 'alice', 2 => 'bob', 3 => 'alice', 4 => null]);

        $findings = (new LeakClassifier())->classify($run);

        self::assertCount(1, $findings);
        self::assertFalse($findings[0]->certain);
    }

    public function testAValueThatFollowsTheValuesSentWithTheUsersIsNotBlamedOnTheUser(): void
    {
        $page = static fn (string $value): array => ['@parser->$lastLine' => self::string($value)];
        $run = new ProbeRun(new Probe('post', '/posts/{first|second|first}'), [1 => 'm1', 2 => 'm2', 3 => 'm3', 4 => 'm4'], self::snapshots([[], $page('first'), $page('second'), $page('first'), $page('first')]), [1 => 200, 2 => 200, 3 => 200, 4 => 200], identities: [1 => 'alice', 2 => 'bob', 3 => 'alice', 4 => null]);

        $findings = (new LeakClassifier())->classify($run);

        self::assertCount(1, $findings);
        self::assertStringStartsWith('follows what each request sent, the user and the values of the request together', $findings[0]->evidence);
        self::assertSame('retention', $findings[0]->impact());
    }

    public function testOnlyAChangedProcessOrAnAnswerProvesAnExposure(): void
    {
        $process = new LeakFinding(LeakType::CrossRequestStateLeak, true, 'php process', 'p', ['{process}->timezone'], 1, 'changed');
        $kept = new LeakFinding(LeakType::CrossRequestStateLeak, true, self::OWNER, 'p', ['@cache->$last'], 1, 'kept');
        $growth = new LeakFinding(LeakType::MonotonicMemoryGrowth, true, self::OWNER, 'p', ['@cache->$all'], 1, 'grows');

        self::assertSame(['exposure', 'retention', 'growth'], [$process->impact(), $kept->impact(), $growth->impact()]);
        self::assertSame('exposure', $process->toArray()['impact']);
    }

    public function testALazyInitializationDuringTheSecondUserIsNotAHandover(): void
    {
        $flag = static fn (string $value): array => ['@configuration->$initialized' => new StateNode('bool', $value, null, self::OWNER)];
        $run = new ProbeRun(new Probe('dashboard', '/dashboard'), [1 => 'm1', 2 => 'm2', 3 => 'm3', 4 => 'm4'], self::snapshots([[], $flag('false'), $flag('true'), $flag('true'), $flag('true')]), [1 => 200, 2 => 200, 3 => 200, 4 => 200], identities: [1 => 'alice', 2 => 'bob', 3 => 'alice', 4 => null]);

        self::assertSame([], (new LeakClassifier())->classify($run));
    }

    public function testWithoutTwoUsersAValueKeptForTheLastRequestIsAWarmup(): void
    {
        $value = static fn (string $zone): array => ['@formatter->$timezone' => self::string($zone)];

        self::assertSame([], $this->classify([[], $value('UTC'), $value('Asia/Tokyo'), $value('Asia/Tokyo')], new Probe('dashboard', '/dashboard')));
    }

    public function testFindingsAreGroupedByOwner(): void
    {
        $state = static fn (string $marker): array => [
            '@factory->$cache[a]->$value' => self::string($marker),
            '@factory->$cache[b]->$value' => self::string($marker),
        ];

        $findings = $this->classify([[], $state('m1'), $state('m2'), $state('m3')]);

        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->pathCount);
        self::assertSame(self::OWNER, $findings[0]->owner);
    }

    public function testARequestAliveAfterItsSendingIsAZombieHeldByTheService(): void
    {
        $held = ['@holder->$last' => new StateNode(Request::class, owner: self::OWNER)];
        $run = new ProbeRun(new Probe('hold', '/hold'), [1 => 'm1', 2 => 'm2', 3 => 'm3'], self::snapshots([[], $held, $held, $held]), [1 => 200, 2 => 200, 3 => 200], [1, 2, 3]);

        $zombies = array_values(array_filter((new LeakClassifier())->classify($run), static fn (LeakFinding $finding): bool => LeakType::ZombieRequestRetention === $finding->type));

        self::assertCount(1, $zombies);
        self::assertSame(['@holder->$last'], $zombies[0]->paths);
    }

    /**
     * @param list<array<string, StateNode>> $states
     *
     * @return list<LeakFinding>
     */
    private function classify(array $states, ?Probe $probe = null): array
    {
        $run = new ProbeRun($probe ?? new Probe('probe', '/probe?q={marker}'),[1 => 'm1', 2 => 'm2', 3 => 'm3'], self::snapshots($states), [1 => 200, 2 => 200, 3 => 200]);

        return (new LeakClassifier())->classify($run);
    }

    /**
     * @param list<array<string, StateNode>> $states
     *
     * @return array<int, Snapshot>
     */
    private static function snapshots(array $states): array
    {
        return array_map(static fn (array $nodes): Snapshot => new Snapshot($nodes), $states);
    }

    private static function string(string $value): StateNode
    {
        return new StateNode('string', $value, null, self::OWNER);
    }

    private static function int(int $value): StateNode
    {
        return new StateNode('int', (string) $value, null, self::OWNER);
    }

    private static function array(int $count): StateNode
    {
        return new StateNode('array', null, $count, self::OWNER);
    }
}
