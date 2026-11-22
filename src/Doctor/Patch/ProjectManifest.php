<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Patch;

/**
 * Snapshot of the project files taken before the loop, to detect and undo any write outside the
 * file being patched (AGENTS.md §6, third layer).
 */
final class ProjectManifest
{
    /** Directories and files that change on their own (caches, dependencies, OS metadata). */
    private const IGNORED = ['vendor', 'var', 'node_modules', '.git', '.DS_Store', '.phpunit.cache'];

    /**
     * @param array<string, string> $contents original content of every file, by relative path
     */
    private function __construct(
        private readonly string $root,
        private array $contents,
    ) {
    }

    public static function capture(string $root): self
    {
        $contents = [];
        foreach (self::scan($root) as $relative => $absolute) {
            $contents[$relative] = (string) file_get_contents($absolute);
        }

        return new self($root, $contents);
    }

    /**
     * Files created, changed or deleted since the snapshot, except the ones in $allowed.
     *
     * @param list<string> $allowed relative paths that may have changed
     *
     * @return list<string> relative paths
     */
    public function changesOutside(array $allowed): array
    {
        $current = self::scan($this->root);
        $changed = [];

        foreach ($this->contents as $relative => $content) {
            if (!isset($current[$relative]) || file_get_contents($current[$relative]) !== $content) {
                $changed[] = $relative;
            }
        }
        foreach (array_keys($current) as $relative) {
            if (!\array_key_exists($relative, $this->contents)) {
                $changed[] = (string) $relative;
            }
        }

        return array_values(array_diff($changed, $allowed));
    }

    /**
     * Puts back the snapshot content of the given files; files absent from the snapshot are removed.
     *
     * @param list<string> $paths relative paths
     */
    public function restore(array $paths): void
    {
        foreach ($paths as $relative) {
            $absolute = $this->root.'/'.$relative;
            if (\array_key_exists($relative, $this->contents)) {
                if (!is_dir(\dirname($absolute))) {
                    mkdir(\dirname($absolute), 0o777, true);
                }
                file_put_contents($absolute, $this->contents[$relative]);
            } elseif (is_file($absolute) || is_link($absolute)) {
                unlink($absolute);
            }
        }
    }

    /**
     * Updates the snapshot of a file after a patch was verified and kept.
     */
    public function accept(string $relative): void
    {
        $absolute = $this->root.'/'.$relative;
        if (is_file($absolute)) {
            $this->contents[$relative] = (string) file_get_contents($absolute);
        } else {
            unset($this->contents[$relative]);
        }
    }

    /**
     * @return array<string, string> absolute path by relative path
     */
    private static function scan(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !\in_array($file->getFilename(), self::IGNORED, true),
            ),
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() || $file->isLink()) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }
}
