<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use IgorDoctor\Runtime\Snapshot\ContainerStateExtractor;
use Symfony\Component\Routing\RouterInterface;

/**
 * Gives a runtime finding the place in the code a fix starts from:
 * - state owned by a class: the declaration of the property (or the method of a `static` variable);
 * - the PHP process: the first call in the application's code that changes that setting;
 * - a request that ended the process, or an answer that shows another request's input: the
 *   controller of the route.
 *
 * It only points at code; whether the file may be patched is the caller's decision.
 */
final class SourceLocator
{
    private const PROCESS_WRITERS = [
        'timezone' => '/\bdate_default_timezone_set\s*\(|\bini_set\s*\(\s*[\'"]date\.timezone/',
        'locale' => '/\bsetlocale\s*\(|\\\\?Locale::setDefault\s*\(/',
        'getenv' => '/\bputenv\s*\(/',
        '$_ENV' => '/\$_ENV\s*\[[^\]]*\]\s*=(?!=)/',
        'error_reporting' => '/\berror_reporting\s*\(\s*[^)\s]/',
        'mb_internal_encoding' => '/\bmb_internal_encoding\s*\(\s*[^)\s]/',
        'cwd' => '/\bchdir\s*\(/',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly ?RouterInterface $router = null,
    ) {
    }

    /**
     * @param array{type: string, owner: string, probe: string, paths: list<string>} $finding
     *
     * @return array{file: string, line: int}|null the file is relative to the project
     */
    public function locate(array $finding): ?array
    {
        $location = match (true) {
            ContainerStateExtractor::PROCESS === $finding['owner'] => $this->processWriter($finding['paths'][0] ?? ''),
            LeakType::ExecutionTerminated->value === $finding['type'], 'response' === $finding['owner'] => $this->controller($finding['probe']),
            default => $this->declaration($finding['owner'], $finding['paths'][0] ?? ''),
        };

        if (null === $location || !str_starts_with($location['file'], $this->projectDir.'/')) {
            return null;
        }

        return ['file' => substr($location['file'], \strlen($this->projectDir) + 1), 'line' => $location['line']];
    }

    /**
     * @return array{file: string, line: int}|null
     */
    private function declaration(string $owner, string $path): ?array
    {
        if (!class_exists($owner) && !trait_exists($owner)) {
            return null;
        }
        $class = new \ReflectionClass($owner);
        $file = $class->getFileName();
        if (false === $file) {
            return null;
        }

        if (1 === preg_match('/::(\w+)\(\)::static \$\w+$/', $path, $match) && $class->hasMethod($match[1])) {
            return ['file' => $file, 'line' => (int) $class->getMethod($match[1])->getStartLine()];
        }

        $property = null;
        if (preg_match_all('/(?:->\$|::\$)(?:[\w\\\\]+::)?(\w+)/', $path, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                if ($class->hasProperty($candidate)) {
                    $property = $candidate;
                    break;
                }
            }
        }

        $line = null !== $property ? self::lineOf($file, '/(?:public|protected|private|var|readonly|static)[^;(]*\$'.preg_quote($property, '/').'\b/') : null;

        return ['file' => $file, 'line' => $line ?? (int) $class->getStartLine()];
    }

    /**
     * @return array{file: string, line: int}|null
     */
    private function processWriter(string $path): ?array
    {
        $setting = (string) preg_replace('/^\{process\}->([^\[]+).*$/', '$1', $path);
        $pattern = self::PROCESS_WRITERS[$setting] ?? null;
        if (null === $pattern || !is_dir($this->projectDir.'/src')) {
            return null;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->projectDir.'/src', \FilesystemIterator::SKIP_DOTS));
        $candidates = [];
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $candidates[] = $file->getPathname();
            }
        }
        sort($candidates);

        foreach ($candidates as $candidate) {
            $line = self::lineOf($candidate, $pattern);
            if (null !== $line) {
                return ['file' => $candidate, 'line' => $line];
            }
        }

        return null;
    }

    /**
     * @return array{file: string, line: int}|null
     */
    private function controller(string $route): ?array
    {
        $controller = $this->router?->getRouteCollection()->get($route)?->getDefault('_controller');
        if (\is_string($controller) && str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);
        } elseif (\is_string($controller) && class_exists($controller)) {
            [$class, $method] = [$controller, '__invoke'];
        } else {
            return null;
        }

        if (!method_exists($class, $method)) {
            return null;
        }
        $reflection = new \ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        if (false === $file) {
            return null;
        }

        $start = (int) $reflection->getStartLine();
        $end = (int) $reflection->getEndLine();
        $lines = file($file) ?: [];
        for ($line = $start; $line <= $end; ++$line) {
            if (1 === preg_match('/\b(?:exit|die)\b\s*[;(]/', $lines[$line - 1] ?? '')) {
                return ['file' => $file, 'line' => $line];
            }
        }

        return ['file' => $file, 'line' => $start];
    }

    private static function lineOf(string $file, string $pattern): ?int
    {
        foreach (file($file) ?: [] as $index => $line) {
            if (1 === preg_match($pattern, $line)) {
                return $index + 1;
            }
        }

        return null;
    }
}
