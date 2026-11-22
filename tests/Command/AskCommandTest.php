<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Command;

use IgorDoctor\Command\AskCommand;
use IgorDoctor\Command\IgorDoctorCommand;
use IgorDoctor\Doctor\Agent\DoctorAgent;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AskCommandTest extends KernelTestCase
{
    public function testCommandIsRegistered(): void
    {
        self::assertTrue((new Application(self::bootKernel()))->has('doctor:ask'));
    }

    public function testAnEmptyQuestionIsAToolingError(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('doctor:ask'));
        $tester->setInputs(['']);

        self::assertSame(IgorDoctorCommand::EXIT_TOOLING_ERROR, $tester->execute([]));
        self::assertStringContainsString('No question', $tester->getDisplay());
    }

    public function testTheQuestionGoesToTheModelWithoutAnyWayToActOnTheMachine(): void
    {
        $calls = [];
        $platform = MockPlatformFactory::createPlatform(static function ($model, $input, $options) use (&$calls): string {
            $calls[] = [$model, $input, $options];

            return '{"user": null}';
        });

        self::assertSame('{"user": null}', AskCommand::answer($platform, 'sonnet', 'Which request reaches the controller?'));
        self::assertCount(1, $calls);
        self::assertSame('sonnet', $calls[0][0]->getName());
        self::assertStringContainsString('Which request reaches the controller?', json_encode($calls[0][1], \JSON_THROW_ON_ERROR));
        foreach (DoctorAgent::ISOLATION_OPTIONS as $option => $value) {
            self::assertSame($value, $calls[0][2][$option] ?? null, $option);
        }
    }
}
