<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * Asks the model one question through Igor Doctor, and returns its text answer. The command is the
 * executable named by IGOR_AI_COMMAND, the doctor's bin/ask: it reads the question on standard input and
 * answers through Symfony AI, with a model that cannot act on the machine. The probe itself depends on no
 * AI library: the application it scans gets none.
 */
final class ModelCommand
{
    public const ENV = 'IGOR_AI_COMMAND';

    public function __construct(private readonly int $timeout = 660)
    {
    }

    /**
     * @throws ModelUnavailable when the command is not set, cannot start, fails, times out or answers nothing
     */
    public function ask(string $prompt): string
    {
        $command = getenv(self::ENV);
        if (!\is_string($command) || '' === $command) {
            throw new ModelUnavailable(\sprintf('Unblocking a route asks a model through Igor Doctor: set %s to the doctor\'s bin/ask (bin/install-runtime prints the line).', self::ENV));
        }
        if (!is_file($command) || !is_executable($command)) {
            throw new ModelUnavailable(\sprintf('%s ("%s") is not an executable file: point it to the doctor\'s bin/ask.', self::ENV, $command));
        }

        $process = @proc_open([$command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new ModelUnavailable(\sprintf('%s ("%s") could not be started.', self::ENV, $command));
        }

        fwrite($pipes[0], $prompt);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = $stderr = '';
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new ModelUnavailable(\sprintf('The model did not answer within %d seconds.', $this->timeout));
            }
            usleep(100_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if (0 !== $status['exitcode'] || '' === trim($stdout)) {
            $detail = trim($stderr) ?: 'no answer';
            throw new ModelUnavailable('The model could not be asked: '.mb_substr($detail, 0, 300));
        }

        return $stdout;
    }
}
