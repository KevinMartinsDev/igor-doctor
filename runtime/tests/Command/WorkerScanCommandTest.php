<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Command;

use IgorDoctor\Runtime\Command\WorkerScanCommand;
use IgorDoctor\Runtime\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkerScanCommandTest extends TestCase
{
    private const NAMESPACE = 'IgorDoctor\\Runtime\\Tests\\Fixtures\\App\\';

    public function testItReportsACertainLeakAsJsonAndExitsWithOne(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute(['--in-process' => true, '--probe' => ['/books?title={marker}'], '--namespace' => [self::NAMESPACE], '--json' => true]);

        self::assertSame(WorkerScanCommand::EXIT_LEAKS, $exit);
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([self::NAMESPACE], $report['namespaces']);
        self::assertSame([200, 200, 200], $report['probes'][0]['statuses']);
        self::assertSame('CROSS_REQUEST_STATE_LEAK', $report['findings'][0]['type']);
        self::assertTrue($report['findings'][0]['certain']);
    }

    public function testAKeptValueThatAPlainRequestReadsAgainIsAnExposure(): void
    {
        $tester = $this->tester();
        $tester->execute(['--in-process' => true, '--probe' => ['/catalog?title={marker}'], '--namespace' => [self::NAMESPACE], '--json' => true]);
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        $kept = array_values(array_filter($report['findings'], static fn (array $finding): bool => 'CROSS_REQUEST_STATE_LEAK' === $finding['type'] && $finding['certain'] && 'response' !== $finding['owner']));
        self::assertNotEmpty($kept);
        self::assertSame('exposure', $kept[0]['impact']);
        self::assertStringContainsString('read again: a request without parameters answers differently', $kept[0]['evidence']);
    }

    public function testAKeptValueThatEachRequestWritesAgainStaysARetention(): void
    {
        $tester = $this->tester();
        $tester->execute(['--in-process' => true, '--probe' => ['/books?title={marker}'], '--namespace' => [self::NAMESPACE], '--json' => true]);
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);

        $kept = array_values(array_filter($report['findings'], static fn (array $finding): bool => 'CROSS_REQUEST_STATE_LEAK' === $finding['type'] && $finding['certain'] && 'response' !== $finding['owner']));
        self::assertNotEmpty($kept);
        self::assertSame('retention', $kept[0]['impact']);
    }

    public function testItExitsWithZeroWhenNothingLeaks(): void
    {
        $tester = $this->tester();

        self::assertSame(WorkerScanCommand::EXIT_CLEAN, $tester->execute(['--in-process' => true, '--probe' => ['GET /books-fixed?title={marker}'], '--namespace' => [self::NAMESPACE]]));
        self::assertStringContainsString('No state survives between the requests.', $tester->getDisplay());
    }

    public function testItSendsEachProbeToAFreshKernel(): void
    {
        $tester = $this->tester();

        $tester->execute(['--in-process' => true, '--probe' => ['/log', '/log'], '--namespace' => [self::NAMESPACE], '--json' => true]);

        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, $report['findings']);
        self::assertSame($report['findings'][0]['evidence'], $report['findings'][1]['evidence']);
    }

    public function testItRunsEachProbeInAChildProcessAndReportsTheOneThatEndsIt(): void
    {
        $argv = $_SERVER['argv'];
        $_SERVER['argv'][0] = \dirname(__DIR__).'/Fixtures/App/console.php';
        $timezone = date_default_timezone_get();

        try {
            $tester = $this->tester();
            $exit = $tester->execute(['--route' => '/^(exit|timezone|books-fixed)$/', '--namespace' => [self::NAMESPACE], '--json' => true]);
        } finally {
            $_SERVER['argv'] = $argv;
        }

        self::assertSame(WorkerScanCommand::EXIT_LEAKS, $exit);
        self::assertSame($timezone, date_default_timezone_get(), 'the probes ran in child processes');
        $findings = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR)['findings'];
        $byType = [];
        foreach ($findings as $finding) {
            $byType[$finding['type']][] = $finding;
        }
        self::assertSame('exit', $byType['EXECUTION_TERMINATED'][0]['owner'] ?? null);
        self::assertSame(['{process}->timezone'], $byType['CROSS_REQUEST_STATE_LEAK'][0]['paths'] ?? null);
        self::assertStringContainsString('UTC → string America/New_York', $byType['CROSS_REQUEST_STATE_LEAK'][0]['evidence']);
        self::assertCount(2, $findings);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'invalid route pattern' => [['--route' => '/(']];
        yield 'probe without a path' => [['--probe' => ['books']]];
        yield 'header without a colon' => [['--probe' => ['/books'], '--header' => ['Accept']]];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testItExitsWithTwoOnInvalidInput(array $input): void
    {
        self::assertSame(WorkerScanCommand::EXIT_ERROR, $this->tester()->execute($input));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new WorkerScanCommand(TestKernel::create()));
    }
}
