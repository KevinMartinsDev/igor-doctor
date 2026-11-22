<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Agent;

/**
 * A change proposed by the agent for one finding. It replaces the whole file.
 */
final readonly class Patch
{
    public function __construct(
        public string $file,
        public string $newContent,
        public string $justification,
    ) {
    }
}
