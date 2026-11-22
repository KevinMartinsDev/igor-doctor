<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

use IgorDoctor\Doctor\Agent\PatchProposer;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\Patch\PatchRejected;
use IgorDoctor\Doctor\Patch\ProjectManifest;

/**
 * Audit → patch → verify, until no blocking finding is left or the iteration cap is reached (AGENTS.md §5).
 *
 * Every patch is applied to the project, then checked by php -l, by the project manifest and by a
 * fresh Igor audit. It is kept only when its finding is gone and no new finding appeared; otherwise
 * the file is restored before the next finding.
 */
final class DoctorLoop
{
    /** @var \Closure(string): void */
    private readonly \Closure $log;

    private ?PatchProposer $proposer = null;

    /**
     * @param \Closure(): PatchProposer  $proposerFactory      called once, only when there is something to fix
     * @param (\Closure(string): void)|null $log
     * @param bool                         $allowBehaviourChange apply a patch the agent flags as behaviour-changing
     */
    public function __construct(
        private readonly Auditor $auditor,
        private readonly \Closure $proposerFactory,
        private readonly PatchPolicy $policy,
        private readonly Linter $linter,
        private readonly int $maxIterations = 3,
        ?\Closure $log = null,
        private readonly bool $allowBehaviourChange = false,
    ) {
        if ($maxIterations < 1) {
            throw new \InvalidArgumentException('The iteration cap must be at least 1.');
        }
        $this->log = $log ?? static function (string $message): void {};
    }

    /**
     * @throws ToolingException
     */
    public function run(): DoctorReport
    {
        $initial = $this->auditor->audit();
        if ([] === self::blocking($initial)) {
            // Nothing blocks: change nothing, so a second run on a fixed project is a no-op.
            return new DoctorReport($initial, $initial, [], 0);
        }

        $manifest = ProjectManifest::capture($this->policy->root());
        $current = $initial;
        /** @var array<string, Outcome> $outcomes */
        $outcomes = [];
        $iteration = 0;

        while ($iteration < $this->maxIterations && [] !== self::blocking($current)) {
            ++$iteration;
            ($this->log)(\sprintf('Iteration %d/%d: %d blocking finding(s).', $iteration, $this->maxIterations, \count(self::blocking($current))));

            $triedThisIteration = [];
            $progress = false;
            while (null !== $finding = $this->nextFinding($current, $triedThisIteration, $outcomes)) {
                $triedThisIteration[$finding->id()] = true;
                [$outcome, $after] = $this->attempt($finding, $current, $manifest);
                $outcomes[$finding->id()] = $outcome;

                if (null !== $after) {
                    $current = $after;
                    $progress = true;
                }
            }

            if (!$progress) {
                break; // Another round would ask the same questions about the same code.
            }
        }

        return new DoctorReport($initial, $current, $outcomes, $iteration);
    }

    /**
     * Blocking findings first, then warnings; never one reported as behaviour-changing or already
     * tried in this iteration.
     *
     * @param list<Finding>          $current
     * @param array<string, true>    $tried
     * @param array<string, Outcome> $outcomes
     */
    private function nextFinding(array $current, array $tried, array $outcomes): ?Finding
    {
        $candidates = array_filter($current, static fn (Finding $f): bool => !isset($tried[$f->id()])
            && Outcome::BEHAVIOUR_CHANGING !== ($outcomes[$f->id()]->status ?? null));

        usort($candidates, static fn (Finding $a, Finding $b): int => [$b->isBlocking(), $a->file, $a->line] <=> [$a->isBlocking(), $b->file, $b->line]);

        return $candidates[0] ?? null;
    }

    /**
     * @param list<Finding> $before
     *
     * @return array{Outcome, list<Finding>|null} the outcome, and the new audit when the patch was kept
     */
    private function attempt(Finding $finding, array $before, ProjectManifest $manifest): array
    {
        ($this->log)(\sprintf('  %s %s at %s', $finding->severity, $finding->ruleId, $finding->location()));

        try {
            $source = $this->policy->resolveWritable($finding->file);
        } catch (PatchRejected $e) {
            return [$this->notApplied($finding, 'The file is out of scope: '.$e->getMessage()), null];
        }

        $proposal = ($this->proposer ??= ($this->proposerFactory)())->propose($finding, (string) file_get_contents($source));

        // Checked here too: a behaviour-changing patch never reaches the disk unless the run allows it.
        if ($proposal->behaviourChanging && (null === $proposal->patch || !$this->allowBehaviourChange)) {
            ($this->log)('    flagged: the fix would change behaviour');

            return [new Outcome($finding, Outcome::BEHAVIOUR_CHANGING, $proposal->reason), null];
        }
        if (null === $proposal->patch) {
            return [$this->notApplied($finding, 'No patch: '.$proposal->reason), null];
        }

        $patch = $proposal->patch;
        try {
            // Checked again here: the loop never trusts that the tool ran.
            $target = $this->policy->resolveWritable($patch->file);
            $original = is_file($target) ? (string) file_get_contents($target) : null;
            $this->policy->assertAcceptable($patch->file, $original ?? '', $patch->newContent);
        } catch (PatchRejected $e) {
            return [$this->notApplied($finding, 'Patch rejected: '.$e->getMessage(), $patch->file), null];
        }

        $relative = $this->policy->relativize($target);
        $restore = static function () use ($target, $original): void {
            null === $original ? @unlink($target) : file_put_contents($target, $original);
        };

        file_put_contents($target, $patch->newContent);

        if (null !== $error = $this->linter->lint($target)) {
            $restore();

            return [$this->notApplied($finding, 'Rolled back, invalid PHP: '.$error, $relative), null];
        }

        $outside = $manifest->changesOutside([$relative]);
        if ([] !== $outside) {
            $restore();
            $manifest->restore($outside);

            throw new ToolingException(\sprintf('Files outside the patch changed and were restored (%s). Stopping.', implode(', ', $outside)));
        }

        try {
            $after = $this->auditor->audit();
        } catch (ToolingException $e) {
            $restore();
            $this->auditor->audit(); // Rethrows when the project is broken without the patch too.

            return [$this->notApplied($finding, 'Rolled back, the audit failed with the patch: '.$e->getMessage(), $relative), null];
        }

        if (null !== $problem = self::regression($finding, $before, $after)) {
            $restore();

            return [$this->notApplied($finding, 'Rolled back, '.$problem, $relative), null];
        }

        $manifest->accept($relative);
        ($this->log)(\sprintf('    fixed%s in %s: %s', $proposal->behaviourChanging ? ' (behaviour changed)' : '', $relative, $patch->justification));

        return [new Outcome($finding, Outcome::FIXED, $patch->justification, $relative, $proposal->behaviourChanging), $after];
    }

    /**
     * @param list<Finding> $before
     * @param list<Finding> $after
     */
    private static function regression(Finding $finding, array $before, array $after): ?string
    {
        $countBefore = array_count_values(array_map(static fn (Finding $f): string => $f->id(), $before));
        $countAfter = array_count_values(array_map(static fn (Finding $f): string => $f->id(), $after));

        if (($countAfter[$finding->id()] ?? 0) >= ($countBefore[$finding->id()] ?? 0)) {
            return 'Igor still reports the finding.';
        }

        foreach ($after as $candidate) {
            if (($countAfter[$candidate->id()] ?? 0) > ($countBefore[$candidate->id()] ?? 0)) {
                return \sprintf('the patch introduced a new finding: %s at %s (%s).', $candidate->ruleId, $candidate->location(), $candidate->message);
            }
        }

        return null;
    }

    private function notApplied(Finding $finding, string $reason, ?string $file = null): Outcome
    {
        ($this->log)('    not applied: '.$reason);

        return new Outcome($finding, Outcome::NOT_APPLIED, $reason, $file);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private static function blocking(array $findings): array
    {
        return array_values(array_filter($findings, static fn (Finding $f): bool => $f->isBlocking()));
    }
}
