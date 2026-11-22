<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Patch;

use IgorDoctor\Doctor\Patch\LineDiff;
use PHPUnit\Framework\TestCase;

final class LineDiffTest extends TestCase
{
    public function testCountsOnlyRemovedLines(): void
    {
        self::assertSame(0, LineDiff::removedLines("a\nb\n", "a\nx\nb\n"));
        self::assertSame(1, LineDiff::removedLines("a\nb\nc\n", "a\nc\n"));
        self::assertSame(2, LineDiff::removedLines("a\nb\nc\n", "a\nB\nC\n"));
        self::assertSame(0, LineDiff::removedLines('', "a\n"));
        self::assertSame(0, LineDiff::removedLines("a\r\nb\r\n", "a\nb\n"));
        self::assertSame(0, LineDiff::removedLines("<?php\n'⬅️ Back';\n", "<?php\n'⬅️ Back';\n// added\n"));
        self::assertSame(["'⬅️ Back'", "'✅'"], LineDiff::splitLines("'⬅️ Back'\n'✅'\n"));
    }
}
