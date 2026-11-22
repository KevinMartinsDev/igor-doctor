<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Agent;

use IgorDoctor\Doctor\ToolingException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Checks that the Claude Code CLI is logged in before the first agent call.
 *
 * When the CLI cannot authenticate (an expired session, for example), its final result event has the
 * subtype "success" with is_error set, and the platform bridge reports only that subtype: the doctor
 * would stop on "Claude Code CLI process failed: "success"". `claude auth status --json` gives the
 * real state. An answer this class cannot read changes nothing: the agent call decides.
 */
final class ClaudeCliLogin
{
    public static function assertLoggedIn(string $cli): void
    {
        try {
            $process = new Process([$cli, 'auth', 'status', '--json'], sys_get_temp_dir(), timeout: 30);
            $process->run();
        } catch (ProcessException) {
            return;
        }

        $status = json_decode(trim($process->getOutput()), true);
        if (\is_array($status) && false === ($status['loggedIn'] ?? null)) {
            throw new ToolingException('The Claude Code CLI is not logged in, or its session expired: run "claude /login", then run the doctor again.');
        }
    }
}
