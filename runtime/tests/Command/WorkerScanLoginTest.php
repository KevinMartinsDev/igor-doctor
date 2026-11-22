<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Command;

use IgorDoctor\Runtime\Command\WorkerScanCommand;
use IgorDoctor\Runtime\Tests\Fixtures\Secured\SecuredKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerScanLoginTest extends TestCase
{
    private const NAMESPACE = 'IgorDoctor\\Runtime\\Tests\\Fixtures\\Secured\\';

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testTheStateOfTheLastUserKeptForAnAnonymousVisitorIsACertainLeak(): void
    {
        $report = $this->scan(['--user' => ['alice', 'bob']]);

        self::assertSame(2, $report['users']);
        self::assertSame([200, 200, 200, 200], $report['probes'][0]['statuses']);
        $handovers = array_values(array_filter($report['findings'], static fn (array $finding): bool => $finding['certain'] && str_contains($finding['evidence'], 'anonymous request')));
        $paths = array_merge(...array_column($handovers, 'paths'));
        self::assertContains('{process}->timezone', $paths);
        self::assertNotEmpty(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '->$lastUser')));
    }

    public function testWithoutUsersTheLoggedInPathIsNotReached(): void
    {
        $report = $this->scan([]);

        self::assertSame([], array_filter($report['findings'], static fn (array $finding): bool => $finding['certain']));
    }

    public function testAFormIsPostedWithTheTokenOfItsPageAndWhatItSavesIsReported(): void
    {
        $tester = new CommandTester(new WorkerScanCommand(SecuredKernel::create()));
        $tester->execute(['--in-process' => true, '--route' => '/^profile_edit$/', '--forms' => true, '--user' => ['alice', 'bob'], '--namespace' => [self::NAMESPACE], '--json' => true]);
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        $form = array_values(array_filter($report['probes'], static fn (array $probe): bool => 'form profile_save (POST)' === $probe['name']));
        self::assertCount(1, $form);
        self::assertSame([200, 200, 200, 200], $form[0]['statuses']);
        $saved = array_values(array_filter($report['findings'], static fn (array $finding): bool => 'form profile_save (POST)' === $finding['probe'] && str_ends_with($finding['paths'][0], '->$last')));
        self::assertCount(1, $saved);
        self::assertTrue($saved[0]['certain']);
    }

    public function testAnUnknownUserStopsTheScan(): void
    {
        $tester = new CommandTester(new WorkerScanCommand(SecuredKernel::create()));

        self::assertSame(WorkerScanCommand::EXIT_ERROR, $tester->execute(['--in-process' => true, '--probe' => ['/page'], '--user' => ['mallory']]));
        self::assertStringContainsString('mallory', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function scan(array $options): array
    {
        $tester = new CommandTester(new WorkerScanCommand(SecuredKernel::create()));
        $tester->execute(['--in-process' => true, '--probe' => ['/page'], '--namespace' => [self::NAMESPACE], '--json' => true, ...$options]);

        return json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
