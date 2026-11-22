<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Patch;

/**
 * What the agent may write, enforced in code before every write (AGENTS.md §6).
 *
 * Only PHP files inside the target project, outside vendor/, var/, config/packages/, hidden
 * directories and existing tests. Paths are resolved with realpath() on their directory, so
 * neither "../" nor a symbolic link can lead out of the project.
 */
final class PatchPolicy
{
    /** Directories, relative to the project root, that are never written. */
    private const DENIED_DIRECTORIES = ['vendor/', 'var/', 'config/packages/'];

    private readonly string $root;

    public function __construct(
        string $root,
        private readonly int $maxRemovedLines = 50,
    ) {
        $real = realpath($root);
        if (false === $real || !is_dir($real)) {
            throw new \InvalidArgumentException(\sprintf('The project root "%s" is not a directory.', $root));
        }
        $this->root = $real;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @param string $path a path relative to the project root
     *
     * @return string the absolute path that may be written
     *
     * @throws PatchRejected
     */
    public function resolveWritable(string $path): string
    {
        if ('' === $path || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '\\') || 1 === preg_match('/^[a-zA-Z]:/', $path)) {
            throw new PatchRejected(\sprintf('"%s" must be a plain path relative to the project root.', $path));
        }
        if (!str_ends_with($path, '.php')) {
            throw new PatchRejected(\sprintf('"%s" is not a PHP file.', $path));
        }

        $directory = realpath($this->root.'/'.\dirname($path));
        if (false === $directory || !is_dir($directory)) {
            throw new PatchRejected(\sprintf('The directory of "%s" does not exist in the project.', $path));
        }
        if (!str_starts_with($directory.'/', $this->root.'/')) {
            throw new PatchRejected(\sprintf('"%s" is outside the project root.', $path));
        }

        $absolute = $directory.'/'.basename($path);
        if (is_link($absolute)) {
            throw new PatchRejected(\sprintf('"%s" is a symbolic link.', $path));
        }
        if (file_exists($absolute) && !is_file($absolute)) {
            throw new PatchRejected(\sprintf('"%s" is not a regular file.', $path));
        }

        $relative = substr($absolute, \strlen($this->root) + 1);
        $this->assertNotDenied($relative, $absolute);

        return $absolute;
    }

    /**
     * Turns an absolute path back into the path relative to the project root.
     */
    public function relativize(string $absolute): string
    {
        return str_starts_with($absolute, $this->root.'/') ? substr($absolute, \strlen($this->root) + 1) : $absolute;
    }

    /**
     * @throws PatchRejected
     */
    public function assertAcceptable(string $relativePath, string $old, string $new): void
    {
        if ('' === trim($new)) {
            throw new PatchRejected(\sprintf('The patch empties "%s".', $relativePath));
        }
        if ($old === $new) {
            throw new PatchRejected(\sprintf('The patch does not change "%s".', $relativePath));
        }

        if (mb_check_encoding($old, 'UTF-8') && !mb_check_encoding($new, 'UTF-8')) {
            throw new PatchRejected(\sprintf('The patch makes "%s" invalid UTF-8.', $relativePath));
        }
        // A replacement character the file did not have means a character was lost on the way.
        if (substr_count($new, "\u{FFFD}") > substr_count($old, "\u{FFFD}")) {
            throw new PatchRejected(\sprintf('The patch adds replacement characters (U+FFFD) to "%s": some characters were corrupted.', $relativePath));
        }

        $removed = LineDiff::removedLines($old, $new);
        if ($removed > $this->maxRemovedLines) {
            throw new PatchRejected(\sprintf('The patch removes %d lines from "%s" (at most %d allowed).', $removed, $relativePath, $this->maxRemovedLines));
        }
    }

    private function assertNotDenied(string $relative, string $absolute): void
    {
        foreach (explode('/', $relative) as $segment) {
            if (str_starts_with($segment, '.')) {
                throw new PatchRejected(\sprintf('"%s" is hidden or in a hidden directory.', $relative));
            }
        }

        foreach (self::DENIED_DIRECTORIES as $denied) {
            if (str_starts_with($relative, $denied)) {
                throw new PatchRejected(\sprintf('"%s" is in %s, which is never patched.', $relative, $denied));
            }
        }

        if (str_starts_with($relative, 'tests/') && file_exists($absolute)) {
            throw new PatchRejected(\sprintf('"%s" is an existing test: tests may be added, not changed.', $relative));
        }
    }
}
