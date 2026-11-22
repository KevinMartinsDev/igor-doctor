<?php

declare(strict_types=1);

namespace IgorDoctor\Tests\Doctor\Patch;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Builds a throwaway project tree in the system temp directory.
 */
trait TemporaryProject
{
    private string $root;

    /**
     * @param array<string, string> $files content by relative path
     */
    private function createProject(array $files): string
    {
        $this->root = sys_get_temp_dir().'/igor-doctor-test-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        foreach ($files as $path => $content) {
            $filesystem->dumpFile($this->root.'/'.$path, $content);
        }

        return $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }
}
