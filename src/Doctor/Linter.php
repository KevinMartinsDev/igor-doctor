<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

interface Linter
{
    /**
     * @return string|null null when the file is valid PHP, the error otherwise
     */
    public function lint(string $absolutePath): ?string;
}
