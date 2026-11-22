<?php

declare(strict_types=1);

namespace IgorDoctor\Command;

use IgorDoctor\Doctor\Agent\DoctorAgentFactory;
use IgorDoctor\Doctor\CompositeAuditor;
use IgorDoctor\Doctor\DoctorLoop;
use IgorDoctor\Doctor\DoctorReport;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Igor\AuditRunnerFactory;
use IgorDoctor\Doctor\Outcome;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\PhpLinter;
use IgorDoctor\Doctor\Runtime\WorkerScanRunner;
use IgorDoctor\Doctor\ToolingException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'igor:doctor',
    description: 'Fix the worker-mode findings reported by Igor, then verify the fixes with Igor.',
    help: <<<'HELP'
        Audits the target project with Igor's MCP server, asks an AI agent for a fix of each finding,
        applies it, and keeps it only if php -l passes and a fresh Igor audit no longer reports it.

        By default a fix that would change what the code does is reported, not applied. With
        --allow-behaviour-change it is applied, verified the same way, and marked in the report.

        With --runtime the target is also run: the runtime probe (bin/install-runtime) replays requests on
        one kernel, as a FrankenPHP worker does, and its findings are fixed and verified the same way.

        Only ERROR findings block. Exit codes: 0 no blocking finding left, 1 blocking findings remain
        after --max-iterations, 2 tooling error (Igor missing, AI not configured, invalid target).
        HELP,
)]
final class IgorDoctorCommand extends Command
{
    public const EXIT_CLEAN = 0;
    public const EXIT_FINDINGS_REMAIN = 1;
    public const EXIT_TOOLING_ERROR = 2;

    public function __construct(
        private readonly DoctorAgentFactory $agentFactory,
        private readonly AuditRunnerFactory $auditRunnerFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'Root of the Symfony project to fix', '.')
            ->addOption('max-iterations', null, InputOption::VALUE_REQUIRED, 'Maximum number of audit and patch rounds', '3')
            ->addOption('allow-behaviour-change', null, InputOption::VALUE_NONE, 'Also apply fixes that change what the code does (removing exit(), a destructor, a kept closure...)')
            ->addOption('runtime', null, InputOption::VALUE_NONE, 'Also run the target and fix what it keeps between requests (needs the runtime probe: bin/install-runtime <target>)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = realpath((string) $input->getArgument('target'));
        if (false === $target || !is_dir($target)) {
            $io->error(\sprintf('The target "%s" is not a directory.', $input->getArgument('target')));

            return self::EXIT_TOOLING_ERROR;
        }

        $maxIterations = filter_var($input->getOption('max-iterations'), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $maxIterations) {
            $io->error('--max-iterations must be a positive integer.');

            return self::EXIT_TOOLING_ERROR;
        }

        $allowBehaviourChange = (bool) $input->getOption('allow-behaviour-change');
        $policy = new PatchPolicy($target);
        $auditor = $this->auditRunnerFactory->forTarget($target);
        if ($input->getOption('runtime')) {
            $auditor = new CompositeAuditor($auditor, new WorkerScanRunner($target));
        }

        $loop = new DoctorLoop(
            $auditor,
            fn () => $this->agentFactory->create($policy, $this->auditRunnerFactory->referenceFor($target), $allowBehaviourChange),
            $policy,
            new PhpLinter(),
            $maxIterations,
            static fn (string $message) => $io->text($message),
            $allowBehaviourChange,
        );

        $io->title('Igor doctor');
        if ($allowBehaviourChange) {
            $io->note('Fixes that change behaviour are allowed in this run: they are applied and marked in the report.');
        }
        $io->text(\sprintf('Auditing <info>%s</info> with Igor%s...', $target, $input->getOption('runtime') ? ' and the runtime probe' : ''));

        try {
            $report = $loop->run();
        } catch (ToolingException $e) {
            $io->error($e->getMessage());

            return self::EXIT_TOOLING_ERROR;
        } catch (\Throwable $e) {
            // Exit code 1 means "findings remain": an unexpected failure must never look like it.
            $io->error(\sprintf('Unexpected %s: %s', $e::class, strtok($e->getMessage(), "\n")));

            return self::EXIT_TOOLING_ERROR;
        }

        $this->renderReport($io, $report);

        return $report->isClean() ? self::EXIT_CLEAN : self::EXIT_FINDINGS_REMAIN;
    }

    private function renderReport(SymfonyStyle $io, DoctorReport $report): void
    {
        $initialBlocking = \count(array_filter($report->initial, static fn (Finding $f): bool => $f->isBlocking()));
        $io->section('Report');
        $io->text(\sprintf(
            'Before: %d finding(s), %d blocking. After: %d finding(s), %d blocking. Iterations: %d.',
            \count($report->initial),
            $initialBlocking,
            \count($report->remaining),
            \count($report->remainingBlocking()),
            $report->iterations,
        ));

        $fixed = $report->fixed();
        if ([] !== $fixed) {
            $io->section(\sprintf('Fixed (%d)', \count($fixed)));
            $io->table(['Severity', 'Rule', 'Location', 'How'], array_map(static function (Finding $f) use ($report): array {
                $outcome = $report->outcomeOf($f);

                if (Outcome::FIXED !== $outcome?->status) {
                    return [$f->severity, $f->ruleId, $f->location(), 'Fixed by another patch.'];
                }

                return [$f->severity, $f->ruleId, $f->location(), ($outcome->behaviourChanged ? 'Behaviour changed: ' : '').$outcome->reason];
            }, $fixed));
        }

        $flagged = $report->flagged();
        if ([] !== $flagged) {
            $io->section(\sprintf('Flagged as behaviour-changing, not applied (%d)', \count($flagged)));
            $io->table(['Severity', 'Rule', 'Location', 'Why'], array_map(
                static fn (Outcome $o): array => [$o->finding->severity, $o->finding->ruleId, $o->finding->location(), $o->reason],
                $flagged,
            ));
        }

        $notApplied = $report->notApplied();
        if ([] !== $notApplied) {
            $io->section(\sprintf('Patches not applied (%d)', \count($notApplied)));
            $io->table(['Severity', 'Rule', 'Location', 'Why'], array_map(
                static fn (Outcome $o): array => [$o->finding->severity, $o->finding->ruleId, $o->finding->location(), $o->reason],
                $notApplied,
            ));
        }

        if ([] !== $report->remaining) {
            $io->section(\sprintf('Remaining (%d)', \count($report->remaining)));
            $io->table(['Severity', 'Rule', 'Location', 'Message'], array_map(
                static fn (Finding $f): array => [$f->severity, $f->ruleId, $f->location(), $f->message],
                $report->remaining,
            ));
        }

        if ($report->isClean()) {
            $io->success('No blocking finding left.');
        } else {
            $io->warning(\sprintf('%d blocking finding(s) remain.', \count($report->remainingBlocking())));
        }
    }
}
