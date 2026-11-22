<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Runtime;

use IgorDoctor\Doctor\Auditor;
use IgorDoctor\Doctor\CompositeAuditor;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Runtime\WorkerScanRunner;
use IgorDoctor\Doctor\ToolingException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class WorkerScanRunnerTest extends TestCase
{
    public function testItTurnsTheReportIntoOneFindingPerLeak(): void
    {
        $entry = static fn (string $probe, bool $certain): array => [
            'type' => 'CROSS_REQUEST_STATE_LEAK',
            'certain' => $certain,
            'owner' => 'php process',
            'probe' => $probe,
            'paths' => ['{process}->timezone'],
            'evidence' => 'follows the value each request sent',
            'file' => 'src/EventSubscriber/TimezoneSubscriber.php',
            'line' => 43,
        ];

        $findings = WorkerScanRunner::findings([
            $entry('homepage', false),
            $entry('blog_index', true),
            ['type' => 'MONOTONIC_MEMORY_GROWTH', 'certain' => true, 'owner' => 'App\\Missing\\Breadcrumbs', 'probe' => 'blog_post', 'paths' => ['@App\\Missing\\Breadcrumbs->$items'], 'evidence' => 'grows', 'file' => null, 'line' => null],
            ['type' => 'UNKNOWN'],
        ]);

        self::assertCount(2, $findings);
        self::assertSame('RuntimeStateLeak', $findings[0]->ruleId);
        self::assertSame(Finding::SEVERITY_ERROR, $findings[0]->severity, 'a certain sighting wins over a suspect one');
        self::assertSame('src/EventSubscriber/TimezoneSubscriber.php:43', $findings[0]->location());
        self::assertSame('State kept from one request to the next: php process at {process}->timezone.', $findings[0]->message);
        self::assertStringContainsString('route "blog_index"', (string) $findings[0]->code);
        self::assertSame('RuntimeMemoryGrowth', $findings[1]->ruleId);
        self::assertSame('', $findings[1]->file, 'a leak outside the project has no file to patch');
    }

    public function testTheIdentityOfAFindingDoesNotDependOnTheRouteNorTheValues(): void
    {
        $finding = static fn (string $probe, string $evidence): Finding => WorkerScanRunner::findings([[
            'type' => 'MONOTONIC_MEMORY_GROWTH', 'certain' => true, 'owner' => 'App\\Service\\Log', 'probe' => $probe,
            'paths' => ['@App\\Service\\Log->$entries'], 'evidence' => $evidence, 'file' => 'src/Service/Log.php', 'line' => 9,
        ]])[0];

        self::assertSame($finding('a', '1 → 2 → 3')->id(), $finding('b', '4 → 5 → 6')->id());
    }

    public function testACompositeAuditorMergesItsSensors(): void
    {
        $sensor = static fn (string $rule): Auditor => new class($rule) implements Auditor {
            public function __construct(private readonly string $rule)
            {
            }

            public function audit(): array
            {
                return [new Finding('src/A.php', 1, $this->rule, Finding::SEVERITY_ERROR, $this->rule)];
            }
        };

        $findings = (new CompositeAuditor($sensor('StateMutation'), $sensor('RuntimeStateLeak')))->audit();

        self::assertSame(['StateMutation', 'RuntimeStateLeak'], array_map(static fn (Finding $f): string => $f->ruleId, $findings));
    }

    public function testATargetWithoutTheProbeIsAToolingError(): void
    {
        $target = sys_get_temp_dir().'/igor-doctor-no-probe-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($target.'/bin/console', "<?php\nfwrite(STDERR, 'Command \"igor:worker-scan\" is not defined.');\nexit(1);\n");

        try {
            $this->expectException(ToolingException::class);
            $this->expectExceptionMessage('bin/install-runtime');
            (new WorkerScanRunner($target))->audit();
        } finally {
            (new Filesystem())->remove($target);
        }
    }

    #[Group('runtime')]
    public function testItRunsTheProbeOnARealKernel(): void
    {
        $fixtureConsole = \dirname(__DIR__, 3).'/runtime/tests/Fixtures/App/console.php';
        $target = sys_get_temp_dir().'/igor-doctor-runtime-target-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($target.'/bin/console', "<?php\nrequire ".var_export($fixtureConsole, true).";\n");

        try {
            $findings = (new WorkerScanRunner($target, ['IgorDoctor\\Runtime\\Tests\\Fixtures\\App\\'], tour: false))->audit();
        } finally {
            (new Filesystem())->remove($target);
        }

        $byRule = [];
        foreach ($findings as $finding) {
            $byRule[$finding->ruleId][] = $finding;
        }
        self::assertArrayHasKey('RuntimeExecutionTerminated', $byRule);
        self::assertArrayHasKey('RuntimeStateLeak', $byRule);
        self::assertArrayHasKey('RuntimeMemoryGrowth', $byRule);
        self::assertSame('Controller/FixtureController.php', $byRule['RuntimeExecutionTerminated'][0]->file);
    }
}
