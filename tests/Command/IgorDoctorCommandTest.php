<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Command;

use IgorDoctor\Command\IgorDoctorCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IgorDoctorCommandTest extends KernelTestCase
{
    public function testCommandIsRegistered(): void
    {
        self::assertTrue((new Application(self::bootKernel()))->has('igor:doctor'));
    }

    public function testMissingTargetIsAToolingError(): void
    {
        $tester = $this->tester();

        self::assertSame(IgorDoctorCommand::EXIT_TOOLING_ERROR, $tester->execute(['target' => '/does/not/exist']));
        self::assertStringContainsString('is not a directory', $tester->getDisplay());
    }

    public function testTargetWithoutIgorIsAToolingError(): void
    {
        $tester = $this->tester();

        self::assertSame(IgorDoctorCommand::EXIT_TOOLING_ERROR, $tester->execute(['target' => sys_get_temp_dir()]));
        self::assertStringContainsString('Igor is not installed', $tester->getDisplay());
    }

    public function testAcceptsTheBehaviourChangeOption(): void
    {
        $tester = $this->tester();

        self::assertSame(IgorDoctorCommand::EXIT_TOOLING_ERROR, $tester->execute(['target' => sys_get_temp_dir(), '--allow-behaviour-change' => true]));
        self::assertStringContainsString('Fixes that change behaviour are allowed', $tester->getDisplay());
    }

    public function testInvalidIterationCapIsAToolingError(): void
    {
        self::assertSame(IgorDoctorCommand::EXIT_TOOLING_ERROR, $this->tester()->execute(['target' => sys_get_temp_dir(), '--max-iterations' => '0']));
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('igor:doctor'));
    }
}
