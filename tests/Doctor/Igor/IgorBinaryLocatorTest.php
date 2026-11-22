<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Igor;

use IgorDoctor\Doctor\Igor\IgorBinaryLocator;
use IgorDoctor\Doctor\ToolingException;
use PHPUnit\Framework\TestCase;

final class IgorBinaryLocatorTest extends TestCase
{
    public function testFailsWhenIgorIsNotInstalledInTheTarget(): void
    {
        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage('Igor is not installed');

        (new IgorBinaryLocator(sys_get_temp_dir()))->locate();
    }

    public function testFailsWhenTheOverrideIsNotExecutable(): void
    {
        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage(IgorBinaryLocator::ENV_OVERRIDE);

        (new IgorBinaryLocator(sys_get_temp_dir(), __FILE__))->locate();
    }

    public function testUsesAnExecutableOverride(): void
    {
        self::assertSame(\PHP_BINARY, (new IgorBinaryLocator(sys_get_temp_dir(), \PHP_BINARY))->locate());
    }
}
