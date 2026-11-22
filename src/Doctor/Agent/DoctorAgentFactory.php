<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Agent;

use IgorDoctor\Doctor\Briefing\Briefing;
use IgorDoctor\Doctor\Briefing\RuleReference;
use IgorDoctor\Doctor\Briefing\ServiceContext;
use IgorDoctor\Doctor\Briefing\SkillLibrary;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\ToolingException;
use Symfony\AI\Platform\Bridge\ClaudeCode\Factory as ClaudeCodeFactory;
use Symfony\AI\Platform\Bridge\ClaudeCode\ModelCatalog;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Builds the agent on the local Claude Code CLI, which uses the developer's own Claude login.
 *
 * DOCTOR_AI_MODEL picks one of the models of the bridge's catalog. DOCTOR_CLAUDE_BIN may point to
 * the CLI when it is not on the PATH. DOCTOR_AGENT_TIMEOUT caps one answer, in seconds (default 600).
 * The CLI runs in an empty directory so that it reads no project instructions or settings.
 */
final class DoctorAgentFactory
{
    public const ENV_MODEL = 'DOCTOR_AI_MODEL';
    public const ENV_CLI = 'DOCTOR_CLAUDE_BIN';
    public const ENV_TIMEOUT = 'DOCTOR_AGENT_TIMEOUT';
    private const DEFAULT_TIMEOUT = 600;

    public function create(PatchPolicy $policy, ?RuleReference $reference = null, bool $allowBehaviourChange = false): DoctorAgent
    {
        [$platform, $model] = $this->platform();

        return new DoctorAgent(
            $platform,
            $model,
            new SourcePatchTool($policy, $allowBehaviourChange),
            null !== $reference ? new Briefing($reference, SkillLibrary::bundled(), $reference instanceof ServiceContext ? $reference : null, $policy) : null,
            $allowBehaviourChange,
        );
    }

    /**
     * The Symfony AI platform on the Claude Code CLI, and the model to call on it.
     *
     * @return array{PlatformInterface, string}
     *
     * @throws ToolingException when the model is not set, or the CLI is missing or not logged in
     */
    public function platform(): array
    {
        $catalog = new ModelCatalog();
        $models = array_keys($catalog->getModels());

        $model = self::env(self::ENV_MODEL);
        if (null === $model || !\in_array($model, $models, true)) {
            throw new ToolingException(\sprintf('Set %s (in .env.local) to one of: %s.', self::ENV_MODEL, implode(', ', $models)));
        }

        $cli = self::env(self::ENV_CLI) ?? (new ExecutableFinder())->find('claude');
        if (null === $cli || !is_executable($cli)) {
            throw new ToolingException(\sprintf('The Claude Code CLI ("claude") was not found. Install it and log in, or set %s.', self::ENV_CLI));
        }

        ClaudeCliLogin::assertLoggedIn($cli);

        $timeout = self::env(self::ENV_TIMEOUT) ?? (string) self::DEFAULT_TIMEOUT;
        if (!ctype_digit($timeout) || 0 === (int) $timeout) {
            throw new ToolingException(\sprintf('%s must be a positive number of seconds.', self::ENV_TIMEOUT));
        }

        $sandbox = sys_get_temp_dir().'/igor-doctor-agent-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($sandbox);
        register_shutdown_function(static fn () => (new Filesystem())->remove($sandbox));

        return [ClaudeCodeFactory::createPlatform(cliBinary: $cli, workingDirectory: $sandbox, timeout: (float) $timeout, modelCatalog: $catalog), $model];
    }

    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
