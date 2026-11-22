<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\ModelCommand;
use IgorDoctor\Runtime\Audit\ModelUnavailable;
use PHPUnit\Framework\TestCase;

final class ModelCommandTest extends TestCase
{
    private string $directory;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/igor-model-command-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
        $this->previous = getenv(ModelCommand::ENV);
    }

    protected function tearDown(): void
    {
        putenv(false === $this->previous ? ModelCommand::ENV : ModelCommand::ENV.'='.$this->previous);
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function testTheQuestionGoesToTheCommandAndItsOutputIsTheAnswer(): void
    {
        putenv(ModelCommand::ENV.'='.$this->script('read question; echo "answer to: $question"'));

        self::assertSame("answer to: route?\n", (new ModelCommand())->ask("route?\n"));
    }

    public function testAMissingCommandSaysHowToSetIt(): void
    {
        putenv(ModelCommand::ENV);

        $this->expectException(ModelUnavailable::class);
        $this->expectExceptionMessage('bin/ask');
        (new ModelCommand())->ask('route?');
    }

    public function testAFailingCommandGivesItsError(): void
    {
        putenv(ModelCommand::ENV.'='.$this->script('cat > /dev/null; echo "Set DOCTOR_AI_MODEL" >&2; exit 2'));

        $this->expectException(ModelUnavailable::class);
        $this->expectExceptionMessage('Set DOCTOR_AI_MODEL');
        (new ModelCommand())->ask('route?');
    }

    public function testACommandThatDoesNotAnswerInTimeIsStopped(): void
    {
        putenv(ModelCommand::ENV.'='.$this->script('sleep 5'));

        $this->expectException(ModelUnavailable::class);
        $this->expectExceptionMessage('within 1 seconds');
        (new ModelCommand(1))->ask('route?');
    }

    private function script(string $body): string
    {
        $file = $this->directory.'/ask';
        file_put_contents($file, "#!/usr/bin/env bash\n".$body."\n");
        chmod($file, 0o755);

        return $file;
    }
}
