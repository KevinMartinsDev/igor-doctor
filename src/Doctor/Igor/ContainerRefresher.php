<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Igor;

use IgorDoctor\Doctor\ToolingException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Rebuilds the target's Symfony container so that Igor's service map matches the code on disk.
 *
 * Igor reads which services are shared or resettable from a map that its bundle writes while
 * the container compiles. A patch that adds ResetInterface only counts once the container is
 * compiled again, and a plain cache:warmup can keep a stale container, so the cache directory
 * is removed first. This runs the target's own bin/console, a fixed command with no argument
 * coming from the agent.
 */
final class ContainerRefresher
{
    public function __construct(
        private readonly string $targetDir,
        private readonly string $environment = 'dev',
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function refresh(): void
    {
        $console = $this->targetDir.'/bin/console';
        if (!is_file($console)) {
            return; // Not a Symfony application: Igor runs its static analysis only.
        }

        $this->filesystem->remove($this->targetDir.'/var/cache/'.$this->environment);

        $process = new Process(
            [\PHP_BINARY, $console, 'cache:warmup', '--env='.$this->environment, '--no-interaction', '--quiet'],
            $this->targetDir,
            ['APP_ENV' => $this->environment],
            timeout: 300,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ToolingException(\sprintf("The Symfony container of the target does not compile:\n%s", trim($process->getErrorOutput().$process->getOutput())));
        }
    }
}
