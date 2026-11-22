<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Patch;

use IgorDoctor\Doctor\Patch\ProjectManifest;
use PHPUnit\Framework\TestCase;

final class ProjectManifestTest extends TestCase
{
    use TemporaryProject;

    public function testDetectsAndRestoresChangesOutsideTheAllowedFile(): void
    {
        $root = $this->createProject(['src/A.php' => 'a', 'src/B.php' => 'b', '.env' => 'SECRET=1', 'var/cache/x' => 'cache']);
        $manifest = ProjectManifest::capture($root);

        file_put_contents($root.'/src/A.php', 'patched');
        file_put_contents($root.'/.env', 'SECRET=2');
        unlink($root.'/src/B.php');
        file_put_contents($root.'/src/New.php', 'new');
        file_put_contents($root.'/var/cache/x', 'rebuilt');

        $outside = $manifest->changesOutside(['src/A.php']);
        sort($outside);
        self::assertSame(['.env', 'src/B.php', 'src/New.php'], $outside);

        $manifest->restore($outside);
        self::assertSame([], $manifest->changesOutside(['src/A.php']));
        self::assertSame('SECRET=1', file_get_contents($root.'/.env'));
        self::assertSame('b', file_get_contents($root.'/src/B.php'));
        self::assertFileDoesNotExist($root.'/src/New.php');
        self::assertSame('patched', file_get_contents($root.'/src/A.php'));
    }

    public function testAcceptedChangesAreTheNewBaseline(): void
    {
        $root = $this->createProject(['src/A.php' => 'a']);
        $manifest = ProjectManifest::capture($root);

        file_put_contents($root.'/src/A.php', 'patched');
        $manifest->accept('src/A.php');

        self::assertSame([], $manifest->changesOutside([]));
    }
}
