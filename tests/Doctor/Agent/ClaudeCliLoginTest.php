<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Agent;

use IgorDoctor\Doctor\Agent\ClaudeCliLogin;
use IgorDoctor\Doctor\ToolingException;
use PHPUnit\Framework\TestCase;

final class ClaudeCliLoginTest extends TestCase
{
    private ?string $cli = null;

    protected function tearDown(): void
    {
        if (null !== $this->cli) {
            @unlink($this->cli);
        }
    }

    public function testALoggedOutCliIsAToolingError(): void
    {
        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage('claude /login');

        ClaudeCliLogin::assertLoggedIn($this->fakeCli('{"loggedIn": false, "authMethod": "none"}'));
    }

    public function testALoggedInCliPasses(): void
    {
        ClaudeCliLogin::assertLoggedIn($this->fakeCli('{"loggedIn": true, "authMethod": "claude.ai"}'));

        $this->addToAssertionCount(1);
    }

    public function testAnUnreadableAnswerLeavesTheDecisionToTheAgentCall(): void
    {
        ClaudeCliLogin::assertLoggedIn($this->fakeCli('error: unknown command "auth"', 1));

        $this->addToAssertionCount(1);
    }

    private function fakeCli(string $output, int $exitCode = 0): string
    {
        $this->cli = (string) tempnam(sys_get_temp_dir(), 'fake-claude-');
        file_put_contents($this->cli, \sprintf("#!/bin/sh\necho %s\nexit %d\n", escapeshellarg($output), $exitCode));
        chmod($this->cli, 0755);

        return $this->cli;
    }
}
