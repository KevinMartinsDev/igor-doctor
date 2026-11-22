<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Agent;

use IgorDoctor\Doctor\Agent\SourcePatchTool;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\Patch\PatchRejected;
use IgorDoctor\Tests\Doctor\Patch\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourcePatchToolTest extends TestCase
{
    use TemporaryProject;

    private const SOURCE = "<?php\nclass A\n{\n    private array \$cache = [];\n    // ⬅️ keep me\n    public function get(): array { return \$this->cache; }\n}\n";

    private SourcePatchTool $tool;

    protected function setUp(): void
    {
        $this->tool = new SourcePatchTool(new PatchPolicy($this->createProject(['src/A.php' => self::SOURCE, '.env' => 'X=1', 'tests/.keep' => ''])));
    }

    public function testAppliesEditsInMemoryWithoutWriting(): void
    {
        $proposal = $this->tool->check(self::answer('src/A.php', [
            ['search' => 'class A', 'replace' => "use Symfony\\Contracts\\Service\\ResetInterface;\nclass A implements ResetInterface"],
            ['search' => "    public function get(): array { return \$this->cache; }\n", 'replace' => "    public function get(): array { return \$this->cache; }\n    public function reset(): void { \$this->cache = []; }\n"],
        ]));

        self::assertNotNull($proposal->patch);
        self::assertStringContainsString('class A implements ResetInterface', $proposal->patch->newContent);
        self::assertStringContainsString('public function reset(): void', $proposal->patch->newContent);
        self::assertStringContainsString('// ⬅️ keep me', $proposal->patch->newContent, 'Untouched lines are kept byte for byte.');
        self::assertSame(self::SOURCE, file_get_contents($this->root.'/src/A.php'));
    }

    public function testCreatesANewTestWithOneEmptySearch(): void
    {
        $proposal = $this->tool->check(self::answer('tests/ATest.php', [['search' => '', 'replace' => "<?php\n// test\n"]]));

        self::assertSame("<?php\n// test\n", $proposal->patch?->newContent);
    }

    public function testAcceptsAnAnswerWrappedInAMarkdownFence(): void
    {
        $answer = "```json\n".self::answer('src/A.php', [['search' => 'class A', 'replace' => 'final class A']])."\n```";

        self::assertNotNull($this->tool->check($answer)->patch);
    }

    public function testRecordsABehaviourChange(): void
    {
        $proposal = $this->tool->check('{"file": "", "edits": [], "justification": "Removing exit() changes the response.", "behaviour_changing": true}');

        self::assertTrue($proposal->behaviourChanging);
        self::assertNull($proposal->patch);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidAnswers(): iterable
    {
        yield 'prose' => ['I would add ResetInterface.', 'not a JSON object'];
        yield 'list' => ['["src/A.php"]', 'not a JSON object'];
        yield 'no justification' => ['{"file": "src/A.php", "edits": [{"search": "class A", "replace": "final class A"}]}', 'justification'];
        yield 'no edits' => ['{"file": "src/A.php", "edits": [], "justification": "x"}', 'non-empty list of "edits"'];
        yield 'whole file instead of edits' => ['{"file": "src/A.php", "new_content": "<?php\n", "justification": "x"}', 'non-empty list of "edits"'];
        yield 'search not found' => [self::answer('src/A.php', [['search' => '  12| class A', 'replace' => 'final class A']]), 'is not in the file'];
        yield 'search not unique' => [self::answer('src/A.php', [['search' => 'cache', 'replace' => 'store']]), 'appears 2 times'];
        yield 'empty search on an existing file' => [self::answer('src/A.php', [['search' => '', 'replace' => 'x']]), 'empty "search"'];
        yield 'malformed edit' => [self::answer('src/A.php', [['search' => 'class A']]), 'needs a string "search" and a string "replace"'];
        yield 'outside the project' => [self::answer('../x.php', [['search' => '', 'replace' => "<?php\n"]]), 'outside the project root'];
        yield 'env file' => [self::answer('.env', [['search' => 'X=1', 'replace' => 'X=2']]), 'not a PHP file'];
    }

    #[DataProvider('invalidAnswers')]
    public function testRejects(string $answer, string $reason): void
    {
        $this->expectException(PatchRejected::class);
        $this->expectExceptionMessage($reason);

        $this->tool->check($answer);
    }

    /**
     * @param list<array<string, string>> $edits
     */
    private static function answer(string $file, array $edits): string
    {
        return json_encode(['file' => $file, 'edits' => $edits, 'justification' => 'Fix the leak.'], \JSON_THROW_ON_ERROR);
    }
}
