<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor;

use IgorDoctor\Doctor\Agent\Patch;
use IgorDoctor\Doctor\Agent\PatchProposer;
use IgorDoctor\Doctor\Agent\Proposal;
use IgorDoctor\Doctor\Auditor;
use IgorDoctor\Doctor\DoctorLoop;
use IgorDoctor\Doctor\DoctorReport;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Outcome;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\PhpLinter;
use IgorDoctor\Doctor\ToolingException;
use IgorDoctor\Tests\Doctor\Patch\TemporaryProject;
use PHPUnit\Framework\TestCase;

final class DoctorLoopTest extends TestCase
{
    use TemporaryProject;

    public function testACleanProjectIsLeftUntouchedAndNeverCallsTheAgent(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// WARN\n"]);

        $report = $this->doctor(static fn () => throw new \LogicException('The agent must not be called.'));

        self::assertTrue($report->isClean());
        self::assertSame(0, $report->iterations);
        self::assertCount(1, $report->remaining, 'Warnings are reported but do not block.');
        self::assertSame("<?php\n// WARN\n", file_get_contents($this->root.'/src/A.php'));
    }

    public function testAppliesAVerifiedPatch(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);

        $report = $this->doctor(static fn (Finding $f, string $content) => Proposal::patch(new Patch($f->file, str_replace('LEAK', 'fixed', $content), 'Remove the leak.')));

        self::assertTrue($report->isClean());
        self::assertCount(1, $report->fixed());
        self::assertSame(Outcome::FIXED, $report->outcomeOf($report->initial[0])?->status);
        self::assertSame("<?php\n// fixed\n", file_get_contents($this->root.'/src/A.php'));
    }

    public function testRunningAgainOnTheFixedProjectChangesNothing(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);
        $this->doctor(static fn (Finding $f, string $content) => Proposal::patch(new Patch($f->file, str_replace('LEAK', 'fixed', $content), 'Remove the leak.')));
        $after = file_get_contents($this->root.'/src/A.php');

        $report = $this->doctor(static fn () => throw new \LogicException('The agent must not be called.'));

        self::assertTrue($report->isClean());
        self::assertSame($after, file_get_contents($this->root.'/src/A.php'));
    }

    public function testABehaviourChangingFixIsFlaggedAndNotRetried(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);
        $calls = 0;

        $report = $this->doctor(static function () use (&$calls) {
            ++$calls;

            return Proposal::behaviourChanging('Removing it changes the response.');
        });

        self::assertFalse($report->isClean());
        self::assertSame(1, $calls);
        self::assertCount(1, $report->flagged());
        self::assertSame("<?php\n// LEAK\n", file_get_contents($this->root.'/src/A.php'));
    }

    public function testABehaviourChangingPatchIsFlaggedWithoutTheOption(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);

        $report = $this->doctor(static fn (Finding $f, string $content) => Proposal::patch(new Patch($f->file, str_replace('LEAK', 'fixed', $content), 'Remove exit().'), behaviourChanging: true));

        self::assertFalse($report->isClean());
        self::assertCount(1, $report->flagged());
        self::assertSame("<?php\n// LEAK\n", file_get_contents($this->root.'/src/A.php'), 'The patch never reached the disk.');
    }

    public function testABehaviourChangingPatchIsAppliedAndMarkedWithTheOption(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);

        $report = $this->doctor(
            static fn (Finding $f, string $content) => Proposal::patch(new Patch($f->file, str_replace('LEAK', 'fixed', $content), 'Remove exit().'), behaviourChanging: true),
            allowBehaviourChange: true,
        );

        self::assertTrue($report->isClean());
        $outcome = $report->outcomeOf($report->fixed()[0]);
        self::assertNotNull($outcome);
        self::assertSame(Outcome::FIXED, $outcome->status);
        self::assertTrue($outcome->behaviourChanged);
    }

    public function testInvalidPhpIsRolledBack(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);

        $report = $this->doctor(static fn (Finding $f) => Proposal::patch(new Patch($f->file, "<?php\nfunction (\n", 'Broken.')));

        self::assertFalse($report->isClean());
        self::assertStringContainsString('invalid PHP', $report->notApplied()[0]->reason);
        self::assertSame("<?php\n// LEAK\n", file_get_contents($this->root.'/src/A.php'));
    }

    public function testAPatchThatIntroducesAFindingIsRolledBack(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);

        $report = $this->doctor(static fn (Finding $f, string $content) => Proposal::patch(new Patch($f->file, str_replace('LEAK', 'OTHER', $content), 'Swap the leak.')));

        self::assertFalse($report->isClean());
        self::assertStringContainsString('introduced a new finding', $report->notApplied()[0]->reason);
        self::assertSame("<?php\n// LEAK\n", file_get_contents($this->root.'/src/A.php'));
    }

    public function testAPatchThatDoesNotRemoveTheFindingIsRolledBack(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n"]);

        $report = $this->doctor(static fn (Finding $f, string $content) => Proposal::patch(new Patch($f->file, $content."// comment\n", 'Comment only.')));

        self::assertStringContainsString('still reports', $report->notApplied()[0]->reason);
        self::assertSame("<?php\n// LEAK\n", file_get_contents($this->root.'/src/A.php'));
    }

    public function testAPatchOutsideThePolicyIsRejected(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n", 'vendor/lib/B.php' => "<?php\n"]);

        $report = $this->doctor(static fn () => Proposal::patch(new Patch('vendor/lib/B.php', "<?php\n// hacked\n", 'Patch the vendor.')));

        self::assertStringContainsString('Patch rejected', $report->notApplied()[0]->reason);
        self::assertSame("<?php\n", file_get_contents($this->root.'/vendor/lib/B.php'));
    }

    public function testAWriteOutsideThePatchedFileIsRestoredAndStopsTheRun(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n", '.env' => "SECRET=1\n"]);
        $root = $this->root;

        try {
            $this->doctor(static function (Finding $f, string $content) use ($root) {
                file_put_contents($root.'/.env', "SECRET=stolen\n");

                return Proposal::patch(new Patch($f->file, str_replace('LEAK', 'fixed', $content), 'Remove the leak.'));
            });
            self::fail('Expected the run to stop.');
        } catch (ToolingException $e) {
            self::assertStringContainsString('.env', $e->getMessage());
        }

        self::assertSame("SECRET=1\n", file_get_contents($this->root.'/.env'));
        self::assertSame("<?php\n// LEAK\n", file_get_contents($this->root.'/src/A.php'));
    }

    public function testStopsAtTheIterationCap(): void
    {
        $this->createProject(['src/A.php' => "<?php\n// LEAK\n", 'src/B.php' => "<?php\n// LEAK\n"]);
        $seen = [];
        $fixOnSecondTry = static function (Finding $f, string $content) use (&$seen) {
            $seen[$f->file] = ($seen[$f->file] ?? 0) + 1;
            if ('src/B.php' === $f->file && 1 === $seen[$f->file]) {
                return Proposal::none('Not this time.');
            }

            return Proposal::patch(new Patch($f->file, str_replace('LEAK', 'fixed', $content), 'Remove the leak.'));
        };

        $capped = $this->doctor($fixOnSecondTry, maxIterations: 1);
        self::assertFalse($capped->isClean());
        self::assertSame(1, $capped->iterations);
        self::assertCount(1, $capped->remainingBlocking());

        $seen = [];
        file_put_contents($this->root.'/src/A.php', "<?php\n// LEAK\n");
        $twoRounds = $this->doctor($fixOnSecondTry, maxIterations: 2);
        self::assertTrue($twoRounds->isClean());
        self::assertSame(2, $twoRounds->iterations);
    }

    /**
     * @param \Closure(Finding, string): Proposal $propose
     */
    private function doctor(\Closure $propose, int $maxIterations = 3, bool $allowBehaviourChange = false): DoctorReport
    {
        $proposer = new class($propose) implements PatchProposer {
            public function __construct(private readonly \Closure $propose)
            {
            }

            public function propose(Finding $finding, string $fileContent): Proposal
            {
                return ($this->propose)($finding, $fileContent);
            }
        };

        return (new DoctorLoop(new MarkerAuditor($this->root), static fn () => $proposer, new PatchPolicy($this->root), new PhpLinter(), $maxIterations, allowBehaviourChange: $allowBehaviourChange))->run();
    }
}

/**
 * Reports one finding per marker word found in the PHP files under src/.
 */
final class MarkerAuditor implements Auditor
{
    private const MARKERS = ['LEAK' => 'ERROR', 'OTHER' => 'ERROR', 'WARN' => 'WARNING'];

    public function __construct(private readonly string $root)
    {
    }

    public function audit(): array
    {
        $findings = [];
        foreach (glob($this->root.'/src/*.php') ?: [] as $file) {
            foreach (file($file) ?: [] as $index => $line) {
                foreach (self::MARKERS as $marker => $severity) {
                    if (str_contains($line, $marker)) {
                        $findings[] = new Finding('src/'.basename($file), $index + 1, $marker.'Rule', $severity, $marker.' found');
                    }
                }
            }
        }

        return $findings;
    }
}
