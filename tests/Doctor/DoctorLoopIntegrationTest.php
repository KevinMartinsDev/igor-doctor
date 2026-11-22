<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor;

use IgorDoctor\Doctor\Agent\Patch;
use IgorDoctor\Doctor\Agent\PatchProposer;
use IgorDoctor\Doctor\Agent\Proposal;
use IgorDoctor\Doctor\DoctorLoop;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Igor\AuditRunnerFactory;
use IgorDoctor\Doctor\Outcome;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\PhpLinter;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Runs the loop with the real Igor on a copy of examples/demo-leak, with a scripted proposer in
 * place of the model: one known fix, every other finding reported as behaviour-changing.
 */
#[Group('igor')]
final class DoctorLoopIntegrationTest extends KernelTestCase
{
    private const FIXED_STATEFUL_SERVICE = <<<'PHP'
        <?php
        namespace App\Service;
        use Symfony\Contracts\Service\ResetInterface;
        class StatefulService implements ResetInterface {
            private array $cache = [];
            public function addData(string $k, string $v): void { $this->cache[$k] = $v; }
            public function getData(): array { return $this->cache; }
            public function reset(): void { $this->cache = []; }
        }

        PHP;

    private string $copy;

    protected function setUp(): void
    {
        $demo = \dirname(__DIR__, 2).'/examples/demo-leak';
        if (!is_file($demo.'/vendor/bin/igor-php')) {
            self::markTestSkipped('Run "composer install" in examples/demo-leak to enable this test.');
        }

        $this->copy = sys_get_temp_dir().'/igor-doctor-demo-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror($demo, $this->copy, (new \ArrayIterator(iterator_to_array(
            new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($demo, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => 'var' !== $f->getFilename() || \dirname($f->getPathname()) !== $demo,
            ), \RecursiveIteratorIterator::SELF_FIRST),
        ))));
        $this->copy = (string) realpath($this->copy);
    }

    protected function tearDown(): void
    {
        if (isset($this->copy)) {
            (new Filesystem())->remove($this->copy);
        }
    }

    public function testFixesTheStatefulServiceAndVerifiesItWithIgor(): void
    {
        $proposer = new class(self::FIXED_STATEFUL_SERVICE) implements PatchProposer {
            public function __construct(private readonly string $fix)
            {
            }

            public function propose(Finding $finding, string $fileContent): Proposal
            {
                return 'src/Service/StatefulService.php' === $finding->file
                    ? Proposal::patch(new Patch($finding->file, $this->fix, 'Reset the cache between requests.'))
                    : Proposal::behaviourChanging('Not scripted in this test.');
            }
        };

        self::bootKernel();
        $policy = new PatchPolicy($this->copy);
        $report = (new DoctorLoop(self::getContainer()->get(AuditRunnerFactory::class)->forTarget($this->copy), static fn () => $proposer, $policy, new PhpLinter(), 1))->run();

        $fixedLocations = array_map(static fn (Finding $f): string => $f->ruleId.'@'.$f->location(), $report->fixed());
        self::assertContains('StateMutation@src/Service/StatefulService.php:5', $fixedLocations);
        // The controller's calls on the now resettable service are no longer reported either.
        self::assertContains('DetectSingletonMutation@src/Controller/LeakDemoController.php:73', $fixedLocations);
        self::assertSame(self::FIXED_STATEFUL_SERVICE, file_get_contents($this->copy.'/src/Service/StatefulService.php'));
        $service = array_values(array_filter($report->fixed(), static fn (Finding $f): bool => 'src/Service/StatefulService.php' === $f->file))[0];
        self::assertSame(Outcome::FIXED, $report->outcomeOf($service)?->status);
        $flagged = array_map(static fn (Outcome $o): string => $o->finding->location(), $report->flagged());
        self::assertNotContains('src/Controller/LeakDemoController.php:73', $flagged, 'Fixed by the service patch, so not flagged.');
        self::assertFalse($report->isClean(), 'The scripted proposer leaves the other findings.');
        self::assertCount(\count($report->initial) - \count($report->fixed()), $report->remaining);
    }
}
