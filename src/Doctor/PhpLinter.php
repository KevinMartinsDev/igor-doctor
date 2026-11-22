<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

use Symfony\Component\Process\Process;

/**
 * php -l on one file, with the PHP binary running the command.
 */
final class PhpLinter implements Linter
{
    public function lint(string $absolutePath): ?string
    {
        $process = new Process([\PHP_BINARY, '-l', $absolutePath], timeout: 60);
        $process->run();

        return $process->isSuccessful() ? null : trim($process->getOutput()."\n".$process->getErrorOutput());
    }
}
