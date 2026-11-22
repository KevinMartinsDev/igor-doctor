<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Runtime;

use IgorDoctor\Doctor\Auditor;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\ToolingException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Audits the target by running it: the runtime probe (`bin/console igor:worker-scan`, installed in the
 * target with bin/install-runtime) replays requests on one kernel and reports the state left behind.
 *
 * The production cache is removed first, so the scan runs the code on disk. The command is fixed, with
 * no argument coming from the agent. All knowledge of the probe's JSON report stays in this class.
 */
final class WorkerScanRunner implements Auditor
{
    public const RULE_PREFIX = 'Runtime';

    public const RULES = [
        'CROSS_REQUEST_STATE_LEAK' => 'RuntimeStateLeak',
        'MONOTONIC_MEMORY_GROWTH' => 'RuntimeMemoryGrowth',
        'ZOMBIE_REQUEST_RETENTION' => 'RuntimeRequestRetention',
        'EXECUTION_TERMINATED' => 'RuntimeExecutionTerminated',
    ];

    private const MESSAGES = [
        'CROSS_REQUEST_STATE_LEAK' => 'State kept from one request to the next',
        'MONOTONIC_MEMORY_GROWTH' => 'Memory that grows on every request',
        'ZOMBIE_REQUEST_RETENTION' => 'A Request kept alive after its response',
        'EXECUTION_TERMINATED' => 'A request ends the PHP process',
    ];

    /**
     * @param list<string> $namespaces the namespaces whose state is reported (the probe's default: App\)
     */
    public function __construct(
        private readonly string $targetDir,
        private readonly array $namespaces = [],
        private readonly string $environment = 'prod',
        private readonly bool $tour = true,
        private readonly float $timeout = 900,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function audit(): array
    {
        $console = $this->targetDir.'/bin/console';
        if (!is_file($console)) {
            throw new ToolingException('The runtime scan needs a Symfony application with bin/console.');
        }

        $this->filesystem->remove($this->targetDir.'/var/cache/'.$this->environment);

        $command = [\PHP_BINARY, '-d', 'display_errors=stderr', $console, 'igor:worker-scan', '--json', '--no-interaction'];
        if ($this->tour) {
            $command[] = '--tour';
        }
        foreach ($this->namespaces as $namespace) {
            $command[] = '--namespace='.$namespace;
        }
        $process = new Process($command, $this->targetDir, ['APP_ENV' => $this->environment, 'APP_DEBUG' => '0'], timeout: $this->timeout);
        $process->run();

        $output = $process->getOutput();
        if (!\in_array($process->getExitCode(), [0, 1], true) || !str_contains($output, '{')) {
            $error = trim($process->getErrorOutput().$output);
            if (str_contains($error, 'igor:worker-scan')) {
                throw new ToolingException('The runtime probe is not installed in the target: run bin/install-runtime '.$this->targetDir);
            }

            throw new ToolingException(\sprintf("The runtime scan failed:\n%s", substr($error, -2000)));
        }

        try {
            $report = json_decode(substr($output, (int) strpos($output, '{')), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ToolingException('The runtime scan printed an invalid report: '.$e->getMessage(), previous: $e);
        }

        return self::findings(\is_array($report['findings'] ?? null) ? $report['findings'] : []);
    }

    /**
     * One finding per leak: the same leak is often reached by several routes.
     *
     * @param array<mixed> $entries
     *
     * @return list<Finding>
     */
    public static function findings(array $entries): array
    {
        $findings = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !isset(self::RULES[$entry['type'] ?? ''])) {
                continue;
            }

            $owner = (string) ($entry['owner'] ?? '');
            $path = (string) ($entry['paths'][0] ?? '');
            $file = \is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $finding = new Finding(
                file: $file,
                line: (int) ($entry['line'] ?? 0),
                ruleId: self::RULES[$entry['type']],
                severity: ($entry['certain'] ?? false) ? Finding::SEVERITY_ERROR : Finding::SEVERITY_WARNING,
                message: \sprintf('%s: %s%s.', self::MESSAGES[$entry['type']], $owner, '' !== $path ? ' at '.$path : ''),
                class: class_exists($owner) ? $owner : null,
                code: \sprintf('Seen at runtime on the route "%s": %s.', $entry['probe'] ?? '?', $entry['evidence'] ?? ''),
                remediation: null,
            );

            $key = $finding->ruleId."\0".$owner."\0".$file."\0".$finding->line;
            if (!isset($findings[$key]) || ($finding->isBlocking() && !$findings[$key]->isBlocking())) {
                $findings[$key] = $finding;
            }
        }

        return array_values($findings);
    }
}
