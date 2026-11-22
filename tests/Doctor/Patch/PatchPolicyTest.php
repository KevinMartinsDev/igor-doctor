<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Patch;

use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\Patch\PatchRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PatchPolicyTest extends TestCase
{
    use TemporaryProject;

    private PatchPolicy $policy;

    protected function setUp(): void
    {
        $root = $this->createProject([
            'src/Service/Foo.php' => "<?php\n",
            'src/notes.txt' => 'x',
            'tests/FooTest.php' => "<?php\n",
            'config/services.php' => "<?php\n",
            'config/packages/framework.php' => "<?php\n",
            'vendor/acme/Lib.php' => "<?php\n",
            'var/cache/Container.php' => "<?php\n",
            '.env.php' => "<?php\n",
            '.hidden/Secret.php' => "<?php\n",
        ]);
        symlink($root.'/src/Service/Foo.php', $root.'/src/Link.php');
        mkdir($root.'/../outside-'.basename($root));
        symlink($root.'/../outside-'.basename($root), $root.'/src/escape');
        $this->policy = new PatchPolicy($root, maxRemovedLines: 3);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root.'/../outside-'.basename($this->root));
        parent::tearDown();
    }

    public function testAllowsAnExistingSourceFile(): void
    {
        self::assertSame($this->root.'/src/Service/Foo.php', $this->policy->resolveWritable('src/Service/Foo.php'));
    }

    public function testAllowsANewSourceFileAndANewTest(): void
    {
        self::assertSame($this->root.'/src/Service/Bar.php', $this->policy->resolveWritable('src/Service/Bar.php'));
        self::assertSame($this->root.'/tests/BarTest.php', $this->policy->resolveWritable('tests/BarTest.php'));
    }

    public function testAllowsConfigOutsidePackages(): void
    {
        self::assertSame($this->root.'/config/services.php', $this->policy->resolveWritable('config/services.php'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function deniedPaths(): iterable
    {
        yield 'traversal' => ['src/../../evil.php', 'outside the project root'];
        yield 'absolute' => ['/etc/passwd.php', 'relative'];
        yield 'symlinked file' => ['src/Link.php', 'symbolic link'];
        yield 'symlinked directory' => ['src/escape/Evil.php', 'outside the project root'];
        yield 'not php' => ['src/notes.txt', 'not a PHP file'];
        yield 'env file' => ['.env.php', 'hidden'];
        yield 'hidden directory' => ['.hidden/Secret.php', 'hidden'];
        yield 'vendor' => ['vendor/acme/Lib.php', 'vendor/'];
        yield 'var' => ['var/cache/Container.php', 'var/'];
        yield 'config packages' => ['config/packages/framework.php', 'config/packages/'];
        yield 'existing test' => ['tests/FooTest.php', 'existing test'];
        yield 'missing directory' => ['src/Nope/Foo.php', 'does not exist'];
        yield 'composer json' => ['composer.json', 'not a PHP file'];
        yield 'backslash' => ['src\\Service\\Foo.php', 'relative'];
    }

    #[DataProvider('deniedPaths')]
    public function testRejects(string $path, string $reason): void
    {
        $this->expectException(PatchRejected::class);
        $this->expectExceptionMessage($reason);

        $this->policy->resolveWritable($path);
    }

    public function testRejectsAPatchThatRemovesTooManyLines(): void
    {
        $this->expectException(PatchRejected::class);
        $this->expectExceptionMessage('removes 4 lines');

        $this->policy->assertAcceptable('src/A.php', "<?php\na\nb\nc\nd\n", "<?php\n// gone\n");
    }

    public function testAcceptsAPatchWithinTheLimit(): void
    {
        $this->expectNotToPerformAssertions();

        $this->policy->assertAcceptable('src/A.php', "<?php\na\nb\nc\n", "<?php\nuse X;\na\nB\nc\n");
    }

    public function testRejectsAPatchThatCorruptsCharacters(): void
    {
        $this->expectException(PatchRejected::class);
        $this->expectExceptionMessage('U+FFFD');

        $this->policy->assertAcceptable('src/A.php', "<?php\n\$a = '⬅️ Back';\n", "<?php\n\$a = '\u{FFFD}\n️ Back';\n");
    }

    public function testRejectsAPatchThatIsNotUtf8(): void
    {
        $this->expectException(PatchRejected::class);
        $this->expectExceptionMessage('invalid UTF-8');

        $this->policy->assertAcceptable('src/A.php', "<?php\n\$a = '⬅️';\n", "<?php\n\$a = '\xe2\xac';\n");
    }

    public function testRejectsANoOpPatchAndAnEmptyFile(): void
    {
        foreach (["<?php\n", '  '] as $new) {
            try {
                $this->policy->assertAcceptable('src/A.php', "<?php\n", $new);
                self::fail('Expected a rejection.');
            } catch (PatchRejected) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
