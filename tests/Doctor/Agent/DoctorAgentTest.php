<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Agent;

use IgorDoctor\Doctor\Agent\DoctorAgent;
use IgorDoctor\Doctor\Agent\SourcePatchTool;
use IgorDoctor\Doctor\Briefing\Briefing;
use IgorDoctor\Doctor\Briefing\RuleReference;
use IgorDoctor\Doctor\Briefing\SkillLibrary;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\ToolingException;
use Symfony\AI\Platform\Exception\RuntimeException;
use IgorDoctor\Tests\Doctor\Patch\TemporaryProject;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class DoctorAgentTest extends TestCase
{
    use TemporaryProject;

    private const ORIGINAL = "<?php\nclass StatefulService {\n    private array \$cache = [];\n}\n";
    private const FIXED = "<?php\nuse Symfony\\Contracts\\Service\\ResetInterface;\nclass StatefulService implements ResetInterface {\n    private array \$cache = [];\n    public function reset(): void { \$this->cache = []; }\n}\n";

    public function testReturnsTheCheckedPatchAndRunsTheCliWithoutTools(): void
    {
        $calls = [];
        $platform = MockPlatformFactory::createPlatform(static function ($model, $input, $options) use (&$calls): string {
            $calls[] = $options;

            return json_encode(['file' => 'src/StatefulService.php', 'edits' => [['search' => "class StatefulService {\n    private array \$cache = [];\n", 'replace' => "use Symfony\\Contracts\\Service\\ResetInterface;\nclass StatefulService implements ResetInterface {\n    private array \$cache = [];\n    public function reset(): void { \$this->cache = []; }\n"]], 'justification' => 'Reset the cache between requests.'], \JSON_THROW_ON_ERROR);
        });

        $proposal = $this->agent($platform)->propose($this->finding(), self::ORIGINAL);

        self::assertNotNull($proposal->patch);
        self::assertSame(self::FIXED, $proposal->patch->newContent);
        self::assertSame(self::ORIGINAL, file_get_contents($this->root.'/src/StatefulService.php'), 'The agent never writes.');
        self::assertCount(1, $calls);
        self::assertSame([], $calls[0]['tools'], 'Every built-in Claude Code tool is disabled.');
        self::assertTrue($calls[0]['strict_mcp_config']);
        self::assertSame('', $calls[0]['setting_sources']);
    }

    public function testRetriesOnceWithTheRejectionInThePrompt(): void
    {
        $prompts = [];
        $platform = MockPlatformFactory::createPlatform(static function ($model, $input) use (&$prompts): string {
            $prompts[] = json_encode($input, \JSON_THROW_ON_ERROR);

            return 1 === \count($prompts)
                ? '{"file": "src/StatefulService.php", "edits": [{"search": "   2| class StatefulService {", "replace": "x"}], "justification": "x"}'
                : json_encode(['file' => 'src/StatefulService.php', 'edits' => [['search' => "class StatefulService {\n    private array \$cache = [];\n", 'replace' => "use Symfony\\Contracts\\Service\\ResetInterface;\nclass StatefulService implements ResetInterface {\n    private array \$cache = [];\n    public function reset(): void { \$this->cache = []; }\n"]], 'justification' => 'Reset the cache.'], \JSON_THROW_ON_ERROR);
        });

        $proposal = $this->agent($platform)->propose($this->finding(), self::ORIGINAL);

        self::assertNotNull($proposal->patch);
        self::assertCount(2, $prompts);
        self::assertStringContainsString('previous answer was rejected', $prompts[1]);
        self::assertStringContainsString('without the line numbers', $prompts[1]);
    }

    public function testGivesUpAfterThreeInvalidAnswers(): void
    {
        $proposal = $this->agent(MockPlatformFactory::createPlatform('I cannot fix this.'))->propose($this->finding(), self::ORIGINAL);

        self::assertNull($proposal->patch);
        self::assertFalse($proposal->behaviourChanging);
        self::assertStringContainsString('not a JSON object', $proposal->reason);
    }

    public function testReportsABehaviourChange(): void
    {
        $platform = MockPlatformFactory::createPlatform('{"file": "", "edits": [], "justification": "exit() is the feature.", "behaviour_changing": true}');

        $proposal = $this->agent($platform)->propose($this->finding(), self::ORIGINAL);

        self::assertTrue($proposal->behaviourChanging);
        self::assertSame('exit() is the feature.', $proposal->reason);
    }

    public function testABehaviourChangingPatchIsOnlyKeptWhenTheRunAllowsIt(): void
    {
        $answer = json_encode(['file' => 'src/StatefulService.php', 'edits' => [['search' => "    private array \$cache = [];\n", 'replace' => '']], 'justification' => 'Drop the cache.', 'behaviour_changing' => true], \JSON_THROW_ON_ERROR);

        $default = $this->agent(MockPlatformFactory::createPlatform($answer))->propose($this->finding(), self::ORIGINAL);
        $allowed = $this->agent(MockPlatformFactory::createPlatform($answer), allowBehaviourChange: true)->propose($this->finding(), self::ORIGINAL);

        self::assertTrue($default->behaviourChanging);
        self::assertNull($default->patch, 'Without the option the edits are dropped.');
        self::assertTrue($allowed->behaviourChanging);
        self::assertNotNull($allowed->patch);
        self::assertSame("<?php\nclass StatefulService {\n}\n", $allowed->patch->newContent);
    }

    public function testTheSystemPromptSwapsTheBehaviourRuleOnlyWhenAllowed(): void
    {
        self::assertStringContainsString('Never remove a feature', DoctorAgent::systemPrompt());
        self::assertStringNotContainsString('Never remove a feature', DoctorAgent::systemPrompt(true));
        self::assertStringContainsString('This run allows fixes that change behaviour', DoctorAgent::systemPrompt(true));
        self::assertStringContainsString('3. If the finding is very likely a false positive', DoctorAgent::systemPrompt(true));
    }

    public function testATimeoutGivesNoPatchInsteadOfEndingTheRun(): void
    {
        $platform = MockPlatformFactory::createPlatform(static function (): never {
            $process = new Process(['claude'], timeout: 600);

            throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
        });

        $proposal = $this->agent($platform)->propose($this->finding(), self::ORIGINAL);

        self::assertNull($proposal->patch);
        self::assertFalse($proposal->behaviourChanging);
        self::assertSame('The agent did not answer within 600 seconds.', $proposal->reason);
    }

    public function testAFailureWithoutReasonSaysWhatToCheck(): void
    {
        $platform = MockPlatformFactory::createPlatform(static function (): never {
            throw new RuntimeException('Claude Code CLI process failed: "success"');
        });

        try {
            $this->agent($platform)->propose($this->finding(), self::ORIGINAL);
            self::fail('A platform failure is a tooling error.');
        } catch (ToolingException $e) {
            self::assertStringContainsString('the CLI gave no reason', $e->getMessage());
            self::assertStringContainsString('login', $e->getMessage());
        }
    }

    public function testTheUserMessageCarriesTheFindingAndNumberedSource(): void
    {
        $message = DoctorAgent::describe($this->finding(), self::ORIGINAL);

        self::assertStringContainsString('- rule: StateMutation', $message);
        self::assertStringContainsString('- file: src/StatefulService.php', $message);
        self::assertStringContainsString('   3|     private array $cache = [];', $message);
    }

    public function testTheBriefingOfTheRuleReachesThePrompt(): void
    {
        $prompts = [];
        $platform = MockPlatformFactory::createPlatform(static function ($model, $input) use (&$prompts): string {
            $prompts[] = json_encode($input, \JSON_THROW_ON_ERROR);

            return '{"file": "", "edits": [], "justification": "x", "behaviour_changing": true}';
        });
        $root = $this->createProject(['src/StatefulService.php' => self::ORIGINAL]);
        $reference = new class implements RuleReference {
            public function rule(string $ruleId): ?string
            {
                return "# {$ruleId}\n\n**Fix**: frozen reference.";
            }
        };

        (new DoctorAgent($platform, 'mock-model', new SourcePatchTool(new PatchPolicy($root)), new Briefing($reference, SkillLibrary::bundled())))
            ->propose($this->finding(), self::ORIGINAL);

        self::assertStringContainsString('frozen reference', $prompts[0]);
        self::assertStringContainsString('Doctor notes on StateMutation', $prompts[0]);
    }

    public function testMultiByteCharactersEndingInByte0x85StayWhole(): void
    {
        // "⬅" is e2 ac 85 and "✅" is e2 9c 85: 0x85 is NEL, a line break for PCRE's \R without /u.
        $message = DoctorAgent::describe($this->finding(), "<?php\n\$a = '⬅️ Back';\n\$b = '✅ Safe';\n");

        self::assertStringContainsString("   2| \$a = '⬅️ Back';", $message);
        self::assertStringContainsString("   3| \$b = '✅ Safe';", $message);
        self::assertStringNotContainsString("\u{FFFD}", $message);
        self::assertTrue(mb_check_encoding($message, 'UTF-8'));
    }

    private function agent(PlatformInterface $platform, bool $allowBehaviourChange = false): DoctorAgent
    {
        $root = $this->createProject(['src/StatefulService.php' => self::ORIGINAL]);

        return new DoctorAgent($platform, 'mock-model', new SourcePatchTool(new PatchPolicy($root), $allowBehaviourChange), allowBehaviourChange: $allowBehaviourChange);
    }

    private function finding(): Finding
    {
        return new Finding('src/StatefulService.php', 3, 'StateMutation', 'ERROR', "Mutation of state 'cache'", 'App\\Service\\StatefulService', 'addData');
    }
}
