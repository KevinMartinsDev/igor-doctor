<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Agent;

use IgorDoctor\Doctor\Briefing\Briefing;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Patch\LineDiff;
use IgorDoctor\Doctor\Patch\PatchRejected;
use IgorDoctor\Doctor\ToolingException;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Asks the model for a fix of one Igor finding (AGENTS.md §7) through the Claude Code platform.
 *
 * The model gets no tool at all: every built-in Claude Code tool, MCP server, setting source and
 * slash command is disabled, so its only output is the JSON answer that SourcePatchTool checks.
 */
final class DoctorAgent implements PatchProposer
{
    public const SYSTEM_PROMPT = <<<'PROMPT'
        You fix one finding reported by Igor, a static analyser for PHP applications that run in FrankenPHP worker mode.
        In worker mode, shared services stay in memory between requests, so state written during one request leaks into the next.

        You receive one finding (file, line, rule, message) and the current content of its file. Fix that finding only.

        You may also receive Igor's reference of the rule and the doctor's notes on it: follow them, they say what fix
        passes the re-audit. You may receive related files, the services the line of the finding uses: read them to know
        what the call does, and patch one of them instead when the fix belongs there.

        Rules:
        1. Prefer the idiomatic Symfony fix: Symfony\Contracts\Service\ResetInterface (services are autoconfigured, so it
           adds the kernel.reset tag by itself: do not edit YAML), RequestStack or the Request argument instead of
           superglobals, a value passed per call instead of stored, a service that is not shared
           (#[Symfony\Component\DependencyInjection\Attribute\Autoconfigure(shared: false)]).
        2. Never remove a feature to silence Igor. If every fix would change what the code does (for example removing exit(),
           a destructor, or a closure kept on purpose), do not change the code: set "behaviour_changing" to true and explain why.
        3. If the finding is very likely a false positive, you may add the #[IgorPhp\IgorBundle\Attribute\WorkerSafe] attribute
           to the class with a justification, without changing the logic.
        4. Keep the patch minimal: one concern, keep the existing formatting, no unrelated change, no reformatting.
        5. You have no tools. Answer with one JSON object and nothing else, no Markdown fence:
           {"file": "<path exactly as given>",
            "edits": [{"search": "<exact lines to replace>", "replace": "<their new version>"}],
            "justification": "<one sentence>", "behaviour_changing": false}
           Each "search" is copied exactly from the file, with its indentation and without the line numbers shown to you,
           and must appear exactly once: add surrounding lines when needed. Edits apply in order; keep each one small.
           When behaviour_changing is true, "file" may be empty and "edits" an empty list.
        PROMPT;

    /**
     * Rule 2 when the run allows behaviour changes (--allow-behaviour-change).
     */
    private const RULE_BEHAVIOUR_ALLOWED = <<<'RULE'
        2. This run allows fixes that change behaviour. Still prefer a fix that keeps what the code does. When every fix
           would change it (for example removing exit(), a destructor, or a closure kept on purpose), apply the smallest
           change that removes the leak, keep the route or method working (it still returns a response), set
           "behaviour_changing" to true and say in the justification what changes. Never delete a whole method or route.
        RULE;

    public static function systemPrompt(bool $allowBehaviourChange = false): string
    {
        if (!$allowBehaviourChange) {
            return self::SYSTEM_PROMPT;
        }

        return (string) preg_replace('/^ *2\. Never remove.*?(?=^ *3\. )/ms', self::RULE_BEHAVIOUR_ALLOWED."\n", self::SYSTEM_PROMPT, 1);
    }

    /**
     * Options of the Claude Code CLI that leave the model without any way to act on the machine.
     */
    public const ISOLATION_OPTIONS = [
        'tools' => [],
        'strict_mcp_config' => true,
        'setting_sources' => '',
        'disable_slash_commands' => true,
        'no_session_persistence' => true,
    ];

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly string $model,
        private readonly SourcePatchTool $tool,
        private readonly ?Briefing $briefing = null,
        private readonly bool $allowBehaviourChange = false,
        private readonly int $maxAttempts = 3,
    ) {
    }

    public function propose(Finding $finding, string $fileContent): Proposal
    {
        $agent = new Agent($this->platform, $this->model);
        $request = self::describe($finding, $fileContent, $this->briefing?->for($finding, $this->allowBehaviourChange) ?? '');
        $rejection = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            try {
                $answer = $agent->call(new MessageBag(
                    Message::forSystem(self::systemPrompt($this->allowBehaviourChange)),
                    Message::ofUser(null === $rejection ? $request : $request."\n\n".$rejection),
                ), self::ISOLATION_OPTIONS)->getContent();
            } catch (ProcessTimedOutException $e) {
                // One slow answer (a long file to rewrite) must not end the run: this finding gets no patch.
                return Proposal::none(\sprintf('The agent did not answer within %d seconds.', (int) $e->getExceededTimeout()));
            } catch (PlatformException|ProcessException $e) {
                // The message of a process failure carries the whole prompt: keep only its first line.
                $message = (string) strtok($e->getMessage(), "\n");
                if (str_ends_with($message, '"success"')) {
                    // The CLI failed but its result event only says "success": the bridge lost the reason.
                    $message .= ' (the CLI gave no reason: check that "claude -p" answers, for example its login or usage limits)';
                }

                throw new ToolingException('The Claude Code platform failed: '.$message, previous: $e);
            }

            $answer = \is_string($answer) ? $answer : '';
            try {
                return $this->tool->check($answer);
            } catch (PatchRejected $e) {
                // The CLI keeps no conversation: the next attempt carries the rejection in its own prompt.
                $rejection = 'Your previous answer was rejected: '.$e->getMessage().' Answer again with a corrected JSON object.';
            }
        }

        return Proposal::none(substr((string) $rejection, 0, 300));
    }

    public static function describe(Finding $finding, string $fileContent, string $briefing = ''): string
    {
        $numbered = [];
        foreach (LineDiff::splitLines($fileContent) as $index => $line) {
            $numbered[] = \sprintf('%4d| %s', $index + 1, $line);
        }

        return implode("\n", array_filter([
            'Finding:',
            '- file: '.$finding->file,
            '- line: '.$finding->line,
            '- rule: '.$finding->ruleId,
            '- severity: '.$finding->severity,
            '- message: '.$finding->message,
            null !== $finding->class ? '- class: '.$finding->class.(null !== $finding->method ? '::'.$finding->method.'()' : '') : null,
            null !== $finding->remediation ? '- Igor remediation hint: '.$finding->remediation : null,
            '' !== $briefing ? "\n".$briefing : null,
            '',
            'Current content of '.$finding->file.' (line numbers are not part of the file):',
            implode("\n", $numbered),
        ], static fn (?string $line): bool => null !== $line));
    }
}
