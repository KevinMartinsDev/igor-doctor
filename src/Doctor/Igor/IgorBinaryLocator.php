<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Igor;

use IgorDoctor\Doctor\ToolingException;
use Symfony\Component\Process\Process;

/**
 * Finds the Igor binary installed in the target project by Composer.
 *
 * vendor/bin/igor-php is a PHP wrapper that downloads the Go binary on first use and runs it
 * with passthru(), which holds back the replies of an interactive stdio session. The MCP
 * server is therefore started from the binary itself: the wrapper is only run once, with
 * --version, so that it downloads the binary and reports its version.
 */
final class IgorBinaryLocator
{
    public const ENV_OVERRIDE = 'IGOR_BIN';

    private ?string $binary = null;

    public function __construct(
        private readonly string $targetDir,
        private readonly ?string $override = null,
    ) {
    }

    public static function forTarget(string $targetDir): self
    {
        $override = getenv(self::ENV_OVERRIDE);

        return new self($targetDir, false === $override || '' === $override ? null : $override);
    }

    public function locate(): string
    {
        return $this->binary ??= $this->doLocate();
    }

    private function doLocate(): string
    {
        if (null !== $this->override) {
            if (!is_file($this->override) || !is_executable($this->override)) {
                throw new ToolingException(\sprintf('%s points to "%s", which is not an executable file.', self::ENV_OVERRIDE, $this->override));
            }

            return $this->override;
        }

        $wrapper = $this->targetDir.'/vendor/bin/igor-php';
        if (!is_file($wrapper)) {
            throw new ToolingException(\sprintf('Igor is not installed in "%s". Run "composer require --dev igor-php/igor-php" in that project, or set %s.', $this->targetDir, self::ENV_OVERRIDE));
        }

        $process = new Process([\PHP_BINARY, $wrapper, '--version'], $this->targetDir, timeout: 180);
        $process->run();
        // The version line goes to stderr, next to the wrapper's own messages.
        if (!$process->isSuccessful() || 1 !== preg_match('/version v?(\d+\.\d+\.\d+)/', $process->getOutput().$process->getErrorOutput(), $matches)) {
            throw new ToolingException(\sprintf("Igor could not be started:\n%s", trim($process->getErrorOutput().$process->getOutput())));
        }

        $binary = \sprintf(
            '%s/vendor/igor-php/igor-php/resources/bin/%s_%s_%s/igor-php%s',
            $this->targetDir,
            str_replace('.', '-', $matches[1]),
            self::os(),
            self::arch(),
            'windows' === self::os() ? '.exe' : '',
        );
        if (!is_file($binary)) {
            throw new ToolingException(\sprintf('Igor %s reported its version but its binary is not at "%s". Set %s to the binary.', $matches[1], $binary, self::ENV_OVERRIDE));
        }

        return $binary;
    }

    /**
     * Same mapping as the Igor wrapper.
     */
    private static function os(): string
    {
        return match (strtolower(\PHP_OS_FAMILY)) {
            'darwin' => 'darwin',
            'windows' => 'windows',
            default => 'linux',
        };
    }

    private static function arch(): string
    {
        $arch = strtolower(php_uname('m'));

        return match ($arch) {
            'x86_64', 'amd64' => 'amd64',
            'arm64', 'aarch64' => 'arm64',
            default => $arch,
        };
    }
}
