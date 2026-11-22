<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Briefing;

use IgorDoctor\Doctor\Briefing\Briefing;
use IgorDoctor\Doctor\Briefing\RuleReference;
use IgorDoctor\Doctor\Briefing\ServiceContext;
use IgorDoctor\Doctor\Briefing\SkillLibrary;
use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Tests\Doctor\Patch\TemporaryProject;
use IgorDoctor\Doctor\Runtime\WorkerScanRunner;
use PHPUnit\Framework\TestCase;

final class BriefingTest extends TestCase
{
    use TemporaryProject;

    public function testCombinesIgorsReferenceAndTheDoctorNotes(): void
    {
        $briefing = new Briefing(self::reference(['StateMutation' => "# StateMutation\n\n**Fix**: reset it."]), SkillLibrary::bundled());

        $text = $briefing->for(self::finding('StateMutation'));

        self::assertStringContainsString("Igor's reference of the rule StateMutation:\n**Fix**: reset it.", $text);
        self::assertStringNotContainsString('# StateMutation', $text, 'The title repeats the section header.');
        self::assertStringContainsString('Doctor notes on StateMutation', $text);
        self::assertStringContainsString('Igor does not follow a call from `reset()`', $text);
    }

    public function testOnlyTheRuleOfTheFindingIsBriefed(): void
    {
        $text = (new Briefing(self::reference([]), SkillLibrary::bundled()))->for(self::finding('IncompleteReset'));

        self::assertStringContainsString('Doctor notes on IncompleteReset', $text);
        self::assertStringNotContainsString('StateMutation', $text);
    }

    public function testTheBehaviourSectionOnlyReachesARunThatAllowsIt(): void
    {
        $briefing = new Briefing(self::reference([]), SkillLibrary::bundled());

        $default = $briefing->for(self::finding('ExecutionTerminator'));
        $allowed = $briefing->for(self::finding('ExecutionTerminator'), allowBehaviourChange: true);

        self::assertStringContainsString('Doctor notes on ExecutionTerminator', $default);
        self::assertStringNotContainsString(SkillLibrary::BEHAVIOUR_SECTION, $default);
        self::assertStringContainsString(SkillLibrary::BEHAVIOUR_SECTION, $allowed);
        self::assertStringContainsString('return new Response', $allowed);
    }

    public function testAnUnknownRuleWithoutReferenceGivesNoBriefing(): void
    {
        self::assertSame('', (new Briefing(self::reference([]), SkillLibrary::bundled()))->for(self::finding('NoSuchRule')));
    }

    public function testEveryIgorRuleIdThatHasNotesNamesARealRule(): void
    {
        $rules = ['StateMutation', 'DetectSingletonMutation', 'DetectClosureStateLeak', 'StaleDependency', 'IncompleteReset', 'MissingResetTag', 'UnsafeStateRestore', 'DoctrineFlushState', 'ProcessStateMutation', 'LocalStaticVariable', 'SuperglobalsUsage', 'ExecutionTerminator', 'MagicMethodDestruct', ...array_values(WorkerScanRunner::RULES)];

        foreach (glob(\dirname(__DIR__, 3).'/resources/skills/*.md') ?: [] as $file) {
            self::assertContains(basename($file, '.md'), $rules, 'A note must be named after an Igor rule id.');
        }
    }

    public function testRelatedFilesAreShownOnlyWhenThePolicyAllowsThem(): void
    {
        $root = $this->createProject([
            'src/A.php' => "<?php\n// finding\n",
            'src/Service/B.php' => "<?php\nclass B { public function addListener(\\Closure \$c): void {} }\n",
            'vendor/lib/C.php' => "<?php\n// vendor\n",
            '.env' => "SECRET=1\n",
        ]);
        $context = new class implements ServiceContext {
            public function relatedFiles(Finding $finding): array
            {
                return ['src/Service/B.php', 'vendor/lib/C.php', '.env', '../outside.php', 'src/A.php', 'src/Missing.php'];
            }
        };

        $text = (new Briefing(self::reference([]), SkillLibrary::bundled(), $context, new PatchPolicy($root)))->for(self::finding('NoSuchRule'));

        self::assertStringContainsString('Related file src/Service/B.php', $text);
        self::assertStringContainsString('   2| class B { public function addListener', $text);
        self::assertStringNotContainsString('vendor', $text);
        self::assertStringNotContainsString('SECRET', $text);
        self::assertStringNotContainsString('Related file src/A.php', $text, 'The finding\'s own file is already in the request.');
        self::assertSame(1, substr_count($text, 'Related file'));
    }

    public function testALongRelatedFileIsCut(): void
    {
        $root = $this->createProject(['src/A.php' => "<?php\n", 'src/B.php' => "<?php\n".str_repeat("// line\n", 400)]);
        $context = new class implements ServiceContext {
            public function relatedFiles(Finding $finding): array
            {
                return ['src/B.php'];
            }
        };

        $text = (new Briefing(self::reference([]), SkillLibrary::bundled(), $context, new PatchPolicy($root)))->for(self::finding('NoSuchRule'));

        self::assertStringContainsString(' 300| // line', $text);
        self::assertStringNotContainsString(' 301| ', $text);
        self::assertStringContainsString('(101 more lines not shown)', $text);
    }

    public function testTheLibraryNeverReadsOutsideItsDirectory(): void
    {
        $library = SkillLibrary::bundled();

        self::assertNull($library->skill('../README'));
        self::assertNull($library->skill('StateMutation.md'));
        self::assertNull($library->skill(''));
    }

    /**
     * @param array<string, string> $docs
     */
    private static function reference(array $docs): RuleReference
    {
        return new class($docs) implements RuleReference {
            /** @param array<string, string> $docs */
            public function __construct(private readonly array $docs)
            {
            }

            public function rule(string $ruleId): ?string
            {
                return $this->docs[$ruleId] ?? null;
            }
        };
    }

    private static function finding(string $rule): Finding
    {
        return new Finding('src/A.php', 3, $rule, 'ERROR', 'message');
    }
}
