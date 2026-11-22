<?php

declare(strict_types=1);

namespace IgorDoctor\Command;

use IgorDoctor\Doctor\Agent\DoctorAgent;
use IgorDoctor\Doctor\Agent\DoctorAgentFactory;
use IgorDoctor\Doctor\ToolingException;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

#[AsCommand(
    name: 'doctor:ask',
    description: 'Ask the model one question through Symfony AI: the question on standard input, the answer on standard output.',
    help: <<<'HELP'
        The runtime probe asks its questions through this command (bin/ask), to unblock a route it could
        not test: it sends the route and what went wrong, and reads back a JSON recipe.

        The model is the agent's own: Symfony AI on the Claude Code CLI, DOCTOR_AI_MODEL in .env.local,
        and the model cannot act on the machine (no tool, no MCP server, no settings).
        HELP,
)]
final class AskCommand extends Command
{
    public function __construct(private readonly DoctorAgentFactory $factory = new DoctorAgentFactory())
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;
        $question = trim((string) stream_get_contents($stream ?? \STDIN));
        if ('' === $question) {
            $errors->writeln('No question on standard input.');

            return IgorDoctorCommand::EXIT_TOOLING_ERROR;
        }

        try {
            $answer = self::answer(...$this->factory->platform(), question: $question);
        } catch (ToolingException $e) {
            $errors->writeln($e->getMessage());

            return IgorDoctorCommand::EXIT_TOOLING_ERROR;
        } catch (PlatformException|ProcessException $e) {
            $errors->writeln('The model did not answer: '.strtok($e->getMessage(), "\n"));

            return IgorDoctorCommand::EXIT_TOOLING_ERROR;
        }

        $output->write($answer, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /**
     * The model's text answer to one question, asked without any way to act on the machine.
     */
    public static function answer(PlatformInterface $platform, string $model, string $question): string
    {
        $answer = (new Agent($platform, $model))->call(new MessageBag(Message::ofUser($question)), DoctorAgent::ISOLATION_OPTIONS)->getContent();

        return \is_string($answer) ? $answer : '';
    }
}
